<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Compliance\Interpretation;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * The plain-English "What you can afford" reduction of a family of plans (a base plus its
 * delta-child what-ifs). Where {@see ResultPresenter} lays every figure out for a reader who
 * wants the detail, this answers the one question an impatient reader actually has —
 * "can I afford this, yes or no?" — for each plan, then leads with the ones that work.
 *
 * The verdict is driven by the DETERMINISTIC central projection (the expected path), so it is
 * computed synchronously for every plan with no Monte Carlo run needed. That projection is
 * optimistic for a survivor-cliff household (it walks the median, not the unlucky tail), so a
 * plan's stored full Monte Carlo success — "in how many of many possible futures does it last"
 * — is shown ALONGSIDE the verdict whenever a run exists, never hidden. A plan can read
 * "works on the expected path" here yet only, say, 60% sure once uncertainty is added; the two
 * figures together are the honest picture, and the copy says so.
 *
 * A plan "works" when its essentials (the must-pay floor: rent/housing costs, food, bills, with
 * the survivor factor applied) are met EVERY year to the end. Meeting the full budget too is
 * "comfortable"; meeting only the essential floor is "the essentials are covered". Anything that
 * runs the essentials short before the end does not work.
 *
 * No figure is invented here — every number comes from the engine's own {@see ForecastResult} /
 * {@see SimulationResult}; this only classifies and phrases them. The one directive "the plan to
 * lean towards" sentence is gated by the caller behind the walled-off `interpret` ability, exactly
 * as {@see Interpretation} is, so the guidance-only partition holds when it is off.
 */
final class AffordabilityAssessment
{
    private const VARIANT_LABELS = [
        'stay_put' => 'Keep your home',
        'buy_outright' => 'Sell & buy somewhere cheaper',
        'rent' => 'Sell & rent',
    ];

    /**
     * Build the ordered plan cards. Each input row describes one plan and its already-computed
     * forecast; this decides the verdict, the wording and the ordering (working plans first,
     * most-money-left first within each tier).
     *
     * @param  list<array{scenario: Scenario, variant: string, forecast: ForecastResult, careStress: ForecastResult, household: Household, baseYear: int, monthlyRent: ?int, mc: ?SimulationResult}>  $plans
     * @return list<array<string, mixed>>
     */
    public static function cards(array $plans): array
    {
        $cards = array_map(
            fn (array $p): array => self::card(
                $p['scenario'], $p['variant'], $p['forecast'], $p['careStress'], $p['household'], $p['baseYear'], $p['monthlyRent'], $p['mc'] ?? null, $p['sustainable'] ?? null,
            ),
            $plans,
        );

        // Working plans first; within a group, the plan that leaves the most spendable money on
        // top. Both keys sort DESCENDING (higher tier / more money first), so the strongest plan
        // is `$cards[0]` — "comfortable" above "essentials only" above "fails".
        usort($cards, fn (array $a, array $b): int => [$b['tierRank'], $b['moneyLeftPence']]
            <=> [$a['tierRank'], $a['moneyLeftPence']]);

        return $cards;
    }

    /**
     * The one-line "bottom line" for the top of the page: how many plans work, and the working
     * plan that keeps the most spendable money to the end. Factual (it names the strongest of the
     * plans the reader already entered, it does not tell them to act) so it clears the banned-
     * phrasing partition; the caller may append a directive sentence behind the `interpret` gate.
     *
     * `lead` is the probability layer the page opens with (B1) — see {@see lead()}.
     *
     * @param  list<array<string, mixed>>  $cards  the output of {@see cards()}
     * @return array{workCount: int, total: int, best: ?array<string, mixed>, headline: string, lead: array<string, mixed>}
     */
    public static function bottomLine(array $cards): array
    {
        $working = array_values(array_filter($cards, fn (array $c): bool => $c['works']));
        $best = $working[0] ?? null; // cards() already sorts working + most-money-left first
        $lead = self::lead($cards[0] ?? null);

        if ($best === null) {
            return [
                'workCount' => 0,
                'total' => count($cards),
                'best' => null,
                'lead' => $lead,
                'headline' => 'On the figures entered, none of these plans keep the essentials paid for life. '
                    .'The options below each show the year the money would run short — the least-bad ones are first.',
            ];
        }

        $rent = $best['monthlyRentLabel'] !== null ? " at {$best['monthlyRentLabel']} a month" : '';
        $headline = "{$best['title']}{$rent} keeps the essentials paid for the rest of your life and leaves "
            ."the most money behind ({$best['moneyLeftRough']}). It is the strongest of the plans you entered.";

        // Honesty: this verdict is the expected, care-free path. If even the strongest plan cannot absorb
        // a significant care need, say so here rather than let "for life" stand unqualified (A2).
        $careCaveat = $best['careStress']['holds']
            ? 'This assumes no long-term care; the strongest plan could still absorb a few years of it.'
            : 'This assumes no long-term care — if it is needed, even this plan would run short (see each plan’s care line below).';

        return [
            'workCount' => count($working),
            'total' => count($cards),
            'best' => $best,
            'lead' => $lead,
            'headline' => $headline,
            'careCaveat' => $careCaveat,
        ];
    }

    /**
     * The figure the landing leads with (B1): the strongest plan's Monte Carlo chance that the
     * essentials last, and the plain word band for it. Leading with the deterministic yes/no was
     * the honesty gap this replaces — a green "yes, this lasts" beside an unshown coin-flip.
     *
     * `checked` is false when that plan has no completed full run. There is then NO probability,
     * and the caller must say so: the deterministic verdict is one average future, not a chance,
     * so putting it in the probability's place would read as certainty the tool does not have.
     *
     * @param  array<string, mixed>|null  $card  the strongest card, {@see cards()} sorts it first
     * @return array{plan: ?string, checked: bool, percent: ?string, band: ?array{level: string, word: string}}
     */
    private static function lead(?array $card): array
    {
        return [
            'plan' => $card['title'] ?? null,
            'checked' => ($card['mcEssentials'] ?? null) !== null,
            'percent' => $card['mcEssentials'] ?? null,
            'band' => $card['mcBand'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function card(Scenario $plan, string $variant, ForecastResult $forecast, ForecastResult $careStress, Household $household, int $baseYear, ?int $monthlyRent, ?SimulationResult $mc, ?array $sustainable): array
    {
        $lasts = $forecast->depletionCalendarYear === null;
        $essentialsMet = $forecast->essentialsAlwaysMet;
        $fullMet = $forecast->fullSpendAlwaysMet;

        // Tier: comfortable (full budget lasts) > essentials only (floor lasts, extras don't) > fails.
        // The tier (and the ordering) is the EXPECTED, care-free path — the central estimate — so a
        // strong plan still ranks strong; the care stress is shown beside it, never folded into the rank.
        [$tier, $tierRank] = match (true) {
            $essentialsMet && $fullMet => ['comfortable', 3],
            $essentialsMet => ['essentials_only', 2],
            default => ['fails', 1],
        };
        $works = $essentialsMet;

        $runsOutYear = $forecast->depletionCalendarYear;
        $runsOutAges = $runsOutYear !== null ? self::agesAt($household, $forecast, $runsOutYear) : null;
        $yearsFromNow = $runsOutYear !== null ? max(0, $runsOutYear - $baseYear) : null;

        $moneyLeft = $forecast->terminalUsableWealth;
        $monthlyRentLabel = $monthlyRent !== null ? '£'.number_format($monthlyRent) : null;

        $verdict = self::verdict($tier, $runsOutYear, $runsOutAges, $yearsFromNow, $moneyLeft);

        return [
            'id' => $plan->id,
            'title' => $plan->name,
            'variant' => $variant,
            'variantLabel' => self::VARIANT_LABELS[$variant] ?? $variant,
            'monthlyRentLabel' => $monthlyRentLabel,
            'tier' => $tier,
            'tierRank' => $tierRank,
            'works' => $works,
            'lasts' => $lasts,
            'verdict' => $verdict,
            'runsOutYear' => $runsOutYear,
            'runsOutAges' => $runsOutAges,
            'yearsFromNow' => $yearsFromNow,
            'moneyLeftPence' => $moneyLeft->pence,
            'moneyLeft' => $moneyLeft->format(),
            'moneyLeftRough' => self::roughPounds($moneyLeft),
            // The care-stress companion (A2): the SAME expected path with one adverse late-life nursing
            // spell injected, so a care-free "lasts for life" is never shown alone. The base verdict
            // above assumes no long-term care; this says what a significant care need would do.
            'careStress' => self::careStress($careStress, $household, $baseYear),
            // What this plan actually leaves them to live on each month — the question the reader
            // came with, and the one a yes/no verdict alone does not answer. Includes the drop once
            // one partner is left, which is where these plans diverge most.
            'spendable' => ResultPresenter::spendableSummary($forecast),
            // The SOLVED answer to "how much could we spend on things we choose?" — the most this
            // plan could fund every year without ever falling short. Null = the plan cannot cover
            // essentials at any level of restraint, which is a different thing from "£0 spare".
            'affordableFreeSpendMonthly' => $sustainable === null ? null : $sustainable['monthly']->format(),
            'affordableFreeSpendAnnual' => $sustainable === null ? null : $sustainable['annual']->format(),
            'affordableFreeSpendUncapped' => $sustainable['ceilingHit'] ?? false,
            // The honest "how sure" figure from a full Monte Carlo run, when one exists for this
            // plan; null prompts the caller to offer a re-run rather than implying certainty.
            'mcEssentials' => $mc !== null ? self::pct($mc->successProbabilityEssentials) : null,
            'mcFullSpend' => $mc !== null ? self::pct($mc->successProbabilityFullSpend) : null,
            // The plain word for that chance, from the one banding home, so this landing and the
            // comparison chip can never disagree on where "likely to last" starts.
            'mcBand' => $mc !== null ? ResultPresenter::lastsBand($mc->successProbabilityEssentials) : null,
        ];
    }

    /**
     * The "if significant care is needed" companion verdict for a card: the same expected path with
     * an adverse ~4-year nursing spell (~£1,800/wk) injected on the last-surviving partner. Not a
     * probability, not averaged into the headline — a single stress shown beside the care-free base.
     *
     * @return array{holds: bool, runsOutYear: ?int, runsOutAges: ?string, yearsFromNow: ?int, careCostRough: string, verdict: string}
     */
    private static function careStress(ForecastResult $forecast, Household $household, int $baseYear): array
    {
        $holds = $forecast->essentialsAlwaysMet;
        $runsOutYear = $forecast->depletionCalendarYear;
        $runsOutAges = $runsOutYear !== null ? self::agesAt($household, $forecast, $runsOutYear) : null;
        $yearsFromNow = $runsOutYear !== null ? max(0, $runsOutYear - $baseYear) : null;

        if ($holds) {
            $verdict = 'Even if one of you needed several years of nursing care later in life, the essentials '
                .'would still be covered — this plan has room for it.';
        } elseif ($runsOutYear !== null) {
            $ages = $runsOutAges !== null ? " (when you’d be {$runsOutAges})" : '';
            $when = $yearsFromNow !== null ? ", about {$yearsFromNow} years from now" : '';
            $verdict = 'But if one of you needed about four years of nursing care (~£1,800 a week), the money '
                ."would run short in {$runsOutYear}{$ages}{$when} — care is the biggest risk to this plan.";
        } else {
            $verdict = 'But if one of you needed about four years of nursing care (~£1,800 a week), the '
                .'essentials could not be covered every year — care is the biggest risk to this plan.';
        }

        return [
            'holds' => $holds,
            'runsOutYear' => $runsOutYear,
            'runsOutAges' => $runsOutAges,
            'yearsFromNow' => $yearsFromNow,
            'careCostRough' => self::roughPounds($forecast->careCostReal()),
            'verdict' => $verdict,
        ];
    }

    /**
     * The plain-English verdict sentence. Deliberately blunt on a failure (the reader needs to
     * hear it) but always a factual statement about the expected path, never "you should".
     */
    private static function verdict(string $tier, ?int $runsOutYear, ?string $runsOutAges, ?int $yearsFromNow, Money $moneyLeft): string
    {
        return match ($tier) {
            'comfortable' => 'Yes — on the expected path this covers your full budget for the rest of your life, and still leaves money behind ('
                .self::roughPounds($moneyLeft).').',
            'essentials_only' => 'Mostly — the essentials (your must-pay costs) stay covered for life, but there are years the full budget can’t stretch to every extra. The money does not run out.',
            default => self::failVerdict($runsOutYear, $runsOutAges, $yearsFromNow),
        };
    }

    private static function failVerdict(?int $runsOutYear, ?string $runsOutAges, ?int $yearsFromNow): string
    {
        if ($runsOutYear === null) {
            return 'No — on the expected path the essentials cannot be covered every year.';
        }
        $ages = $runsOutAges !== null ? " (when you’d be {$runsOutAges})" : '';
        $when = $yearsFromNow !== null ? " — about {$yearsFromNow} years from now" : '';

        return "No — on the expected path your savings run out in {$runsOutYear}{$ages}{$when}, "
            .'so the essential bills can’t be met after that.';
    }

    /** People's ages in the given calendar year, from the forecast's own per-year record. */
    private static function agesAt(Household $household, ForecastResult $forecast, int $year): ?string
    {
        foreach ($forecast->years as $y) {
            if ($y->calendarYear === $year) {
                $parts = [];
                foreach ($household->persons as $person) {
                    $age = $y->ages[$person->id] ?? null;
                    if ($age !== null) {
                        $name = $person->name !== null && $person->name !== '' ? $person->name : 'you';
                        $parts[] = "{$name} {$age}";
                    }
                }

                return $parts === [] ? null : implode(', ', $parts);
            }
        }

        return null;
    }

    /** A legible rounded-pounds figure ("about £135,000") — pennies are noise at this scale. */
    private static function roughPounds(Money $m): string
    {
        $pounds = (int) round($m->pence / 100);
        if ($pounds <= 0) {
            return '£0';
        }
        $rounded = $pounds >= 10_000
            ? (int) (round($pounds / 1_000) * 1_000)
            : (int) (round($pounds / 100) * 100);

        return '£'.number_format($rounded);
    }

    private static function pct(float $fraction): string
    {
        return round($fraction * 100).'%';
    }
}
