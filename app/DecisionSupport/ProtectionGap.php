<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Protection\EarlyDeathStress;

/**
 * **"What happens to the one who is left?"** — the protection gap.
 *
 * A couple's plan is quietly a bet that both of them live roughly as long as the table says. The
 * first death is the sharpest event in the projection: a salary or a State Pension stops outright,
 * a DB pension drops to its survivor's fraction or to nothing, and the survivor's spending falls by
 * far less than their income does. This tool already computes that cliff and shows it. What it
 * never did was name the instrument that fills the hole, which is one of the few genuinely valuable
 * things a protection adviser does — and it is unusually cheap to answer here, because the engine
 * already knows the size of the hole.
 *
 * Three figures per person, all derived from the SAME variant-aware deterministic projection the
 * results page reads (never a stay-put reading of a sell plan — a live trap in this codebase):
 *
 *  1. **What happens** — when the survivor's money runs out, against the couple's own baseline.
 *  2. **What is already in place** — the employer death-in-service payout, read out of the stressed
 *     forecast itself rather than recomputed, so the panel cannot disagree with the ladder.
 *  3. **What is still missing** — a bisection for the extra life cover that restores the survivor
 *     to the household's own baseline, plus the same figure once the employer's cover has **ceased
 *     at retirement**, which is the cliff-edge most people do not know they are walking towards.
 *
 * **The bar is relative, not absolute.** "Restore" means the survivor's money lasts at least as
 * long as the couple's own plan made it last. An absolute "must never run short" bar would be
 * unanswerable for a household whose joint plan already runs short, and would report a protection
 * need for a shortfall that has nothing to do with a death.
 *
 * Deterministic and synchronous, like {@see SustainableSpend}: no queue worker, ~50 forecasts per
 * scenario. It therefore inherits the central path's optimism (it walks the expected path, not the
 * unlucky tail), and any surface showing it must say so.
 */
final class ProtectionGap
{
    /** Bisection steps once the answer is bracketed. */
    private const STEPS = 18;

    /** Never search for cover above this, whatever the household needs. */
    private const CEILING = 3_000_000.0;

    /**
     * Solved sums are rounded UP to this, both because a bisection on a step function (the year the
     * money runs out) knows the answer only to within a step, and because rounding a protection
     * need DOWN would report a sum that does not quite do the job.
     */
    private const ROUND_UP_TO = 1_000_00;

    public function __construct(private readonly ScenarioForecaster $forecaster) {}

    /**
     * The protection picture for this scenario, or **null** when the question does not arise: a
     * one-person household has no survivor to protect (their equivalent question is about the
     * estate, which the IHT panel already answers).
     *
     * @return array{
     *     deathYear: int,
     *     baselineDepletionYear: int|null,
     *     people: list<array{
     *         id: string,
     *         name: string,
     *         survivorName: string,
     *         depletionYear: int|null,
     *         worseThanBaseline: bool,
     *         coverInForce: Money,
     *         coverDescription: string|null,
     *         coverCeasesInYear: int|null,
     *         gap: Money,
     *         gapCeilingHit: bool,
     *         needWithoutCover: Money,
     *         gapAfterCoverCeases: Money|null,
     *         deathYearAfterRetirement: int|null,
     *     }>
     * }|null
     *
     * $strategy pins the housing variant to read (stay put / buy cheaper / rent). The results page
     * passes the strategy its ladder is currently SHOWING, so the panel and the ladder can never
     * disagree about which plan is being stressed; null falls back to the scenario's own stored
     * choice, which is what a caller outside the ladder wants.
     */
    public function forScenario(Scenario $scenario, ?string $strategy = null): ?array
    {
        ['household' => $household, 'settings' => $settings, 'assumptions' => $assumptions] = $this->forecaster->variantInputs($scenario, $strategy);
        if (count($household->persons) < 2) {
            return null;
        }

        $forecaster = new DeterministicForecaster($this->forecaster->config($scenario), new CohortLifeTable);
        $forecast = fn (Household $h): ForecastResult => $forecaster->forecast($h, $assumptions, $settings);

        $baseline = $forecast($household);
        $deathYear = $settings->baseYear + 1;

        $people = [];
        foreach ($household->persons as $person) {
            $people[] = $this->forPerson($household, $person, $deathYear, $baseline, $forecast);
        }

        return [
            'deathYear' => $deathYear,
            'baselineDepletionYear' => $baseline->depletionCalendarYear,
            'people' => $people,
        ];
    }

    /**
     * One person's half of the picture: what their death next year does, what their employer's
     * cover already pays, and what is still missing — now and once that cover has ceased.
     *
     * @param  callable(Household): ForecastResult  $forecast
     * @return array<string, mixed>
     */
    private function forPerson(Household $household, Person $person, int $deathYear, ForecastResult $baseline, callable $forecast): array
    {
        $stressed = EarlyDeathStress::died($household, $person->id, $deathYear);
        $withCover = $forecast($stressed);

        // Read the employer payout out of the forecast rather than recomputing it from the DTO:
        // one definition, and the panel can never state a figure the ladder does not show.
        $coverInForce = Money::zero();
        foreach ($withCover->years as $year) {
            $coverInForce = $coverInForce->plus($year->incomeBySource['death_in_service'] ?? Money::zero());
        }

        $target = $baseline->depletionCalendarYear;
        $gap = $this->solveCover($stressed, $person->id, $deathYear, $target, $forecast);

        // What they would need with NO employer cover at all: the same solve on a household whose
        // cover has been stripped. Skipped (and equal to the gap) when there is no cover in force.
        $needWithoutCover = $coverInForce->isPositive()
            ? $this->solveCover($this->withoutEmployerCover($stressed, $person->id), $person->id, $deathYear, $target, $forecast)
            : $gap;

        // The cliff: employer cover ceases at retirement, so the same death a year later is met
        // with nothing. Only meaningful while there is cover to lose and a retirement to lose it at.
        $retirementYear = $this->retirementYear($person);
        $afterCeases = null;
        $deathYearAfterRetirement = null;
        if ($coverInForce->isPositive() && $retirementYear !== null && $retirementYear >= $deathYear) {
            $deathYearAfterRetirement = $retirementYear + 1;
            $afterCeases = $this->solveCover(
                EarlyDeathStress::died($household, $person->id, $deathYearAfterRetirement),
                $person->id, $deathYearAfterRetirement, $target, $forecast,
            );
        }

        $survivor = null;
        foreach ($household->persons as $other) {
            if ($other->id !== $person->id) {
                $survivor = $other;
                break;
            }
        }

        return [
            'id' => $person->id,
            'name' => $person->name ?? 'This person',
            'survivorName' => $survivor?->name ?? 'their partner',
            'depletionYear' => $withCover->depletionCalendarYear,
            'worseThanBaseline' => self::rank($withCover) < self::rank($baseline),
            'coverInForce' => $coverInForce,
            'coverDescription' => $person->deathInServiceCover?->describe(),
            'coverCeasesInYear' => $coverInForce->isPositive() ? $retirementYear : null,
            'gap' => $gap['amount'],
            'gapCeilingHit' => $gap['ceilingHit'],
            'needWithoutCover' => $needWithoutCover['amount'],
            'gapAfterCoverCeases' => $afterCeases['amount'] ?? null,
            'deathYearAfterRetirement' => $deathYearAfterRetirement,
        ];
    }

    /**
     * Bisect for the life cover, paid in $deathYear, that restores the stressed household to the
     * couple's own baseline: the money must last at least as long as it did before the death.
     *
     * More cover can only push the depletion year later, so the bar is monotone in the amount,
     * which is what makes bisection valid rather than a lucky guess. Zero is tried first, because
     * a survivor is quite often no worse off (a partner can be a net cost), and reporting a
     * protection need that is not there would be worse than reporting none.
     *
     * @param  callable(Household): ForecastResult  $forecast
     * @return array{amount: Money, ceilingHit: bool}
     */
    private function solveCover(Household $stressed, string $deceasedId, int $deathYear, ?int $baselineDepletionYear, callable $forecast): array
    {
        $baselineRank = $baselineDepletionYear ?? PHP_INT_MAX;
        $holds = fn (float $pounds): bool => self::rank($forecast(
            EarlyDeathStress::withLifeCover($stressed, $deceasedId, $deathYear, Money::fromPence((int) round(max(0.0, $pounds) * 100)))
        )) >= $baselineRank;

        if ($holds(0.0)) {
            return ['amount' => Money::zero(), 'ceilingHit' => false];
        }

        $low = 0.0;
        $high = 25_000.0;
        while ($high < self::CEILING && ! $holds($high)) {
            $low = $high;
            $high *= 2.0;
        }

        if ($high >= self::CEILING && ! $holds(self::CEILING)) {
            // No lump sum inside the search range restores the baseline. Report the ceiling and
            // FLAG it rather than implying a precise answer the search never found.
            return ['amount' => self::roundUp(self::CEILING), 'ceilingHit' => true];
        }

        // Invariant: $low does not hold, $high does.
        for ($i = 0; $i < self::STEPS; $i++) {
            $mid = ($low + $high) / 2.0;
            if ($holds($mid)) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        return ['amount' => self::roundUp($high), 'ceilingHit' => false];
    }

    /**
     * The same household with $personId's employer cover removed — the "what would you need if you
     * did not have it?" comparator, and the shape a post-retirement death already has.
     */
    private function withoutEmployerCover(Household $household, string $personId): Household
    {
        return $household->withPersons(array_map(
            static fn (Person $p): Person => $p->id === $personId
                ? new Person(
                    $p->id, $p->dob, $p->sex, $p->employmentStatus, $p->grossSalary, $p->salaryGrowth,
                    $p->plannedRetirementAge, $p->niCategory, $p->name, $p->longevity,
                    $p->receivesDisabilityBenefit, $p->caresForPartner, deathInServiceCover: null,
                    disabilityBenefitFromAge: $p->disabilityBenefitFromAge,
                )
                : $p,
            $household->persons,
        ));
    }

    /** The calendar year this person's employment (and so their death-in-service cover) ends. */
    private function retirementYear(Person $person): ?int
    {
        if ($person->plannedRetirementAge === null) {
            return null;
        }

        return (int) $person->dob->format('Y') + $person->plannedRetirementAge;
    }

    /** Later is better: the year the money runs out, with "never" as the top of the order. */
    private static function rank(ForecastResult $result): int
    {
        return $result->depletionCalendarYear ?? PHP_INT_MAX;
    }

    /** Round a solved sum UP to the nearest £1,000 — see {@see ROUND_UP_TO}. */
    private static function roundUp(float $pounds): Money
    {
        $pence = (int) ceil($pounds * 100 / self::ROUND_UP_TO) * self::ROUND_UP_TO;

        return Money::fromPence($pence);
    }
}
