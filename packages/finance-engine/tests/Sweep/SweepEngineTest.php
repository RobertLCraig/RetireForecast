<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Sweep;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepCurve;
use RetireForecast\FinanceEngine\Sweep\SweepEngine;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use RetireForecast\FinanceEngine\Sweep\SweepPoint;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The decision-support correctness spine (docs/PLAN-decision-support.md, Phase 0). Proves the
 * SweepEngine measures a success curve with honest confidence intervals, reads its crossing of a
 * target as a banded verdict (S3), and — the reason this is a Monte Carlo engine and not a
 * deterministic bracket — that a deterministic median pass over-promises on a survivor-cliff
 * household (S1): at a spend the deterministic forecast endorses, the real MC chance is well below
 * the target the reader would set.
 */
final class SweepEngineTest extends TestCase
{
    private function engine(): SweepEngine
    {
        return new SweepEngine(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi));
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /**
     * A curve of given (leverValue => probability) points, CI collapsed (the verdict logic reads
     * the point estimate).
     *
     * @param  array<int|float, float>  $probByLever
     */
    private function curve(array $probByLever): SweepCurve
    {
        $points = [];
        foreach ($probByLever as $lever => $p) {
            $points[] = new SweepPoint((float) $lever, (float) $p, (float) $p, (float) $p, 1000);
        }

        return new SweepCurve($points, SweepMetric::Essentials, LeverDirection::Increasing, 'test', '£', 1000, 1);
    }

    public function test_crossing_verdicts_read_the_curve_against_the_target(): void
    {
        $engine = $this->engine();

        // Whole curve at/above target → already on track (no change needed in range).
        $this->assertSame(
            CrossingVerdict::AlreadyOnTrack,
            $engine->findCrossing($this->curve([0 => 0.97, 100 => 0.98, 200 => 0.99]), 0.95)->verdict,
        );

        // Whole curve below target → unreachable by this lever in range.
        $this->assertSame(
            CrossingVerdict::Unreachable,
            $engine->findCrossing($this->curve([0 => 0.40, 100 => 0.55, 200 => 0.70]), 0.95)->verdict,
        );

        // A single crossing → a genuine threshold, reported as a band around the interpolated estimate.
        $crossing = $engine->findCrossing($this->curve([0 => 0.80, 100 => 0.90, 200 => 1.00]), 0.95);
        $this->assertSame(CrossingVerdict::Crosses, $crossing->verdict);
        $this->assertTrue($crossing->hasThreshold());
        $this->assertSame(100.0, $crossing->lowerLever);
        $this->assertSame(200.0, $crossing->upperLever);
        $this->assertEqualsWithDelta(150.0, $crossing->estimate, 0.001); // 0.95 is halfway from 0.90 to 1.00

        // Two crossings → non-monotone: the first is reported but flagged (a single threshold would mislead).
        $nonMono = $engine->findCrossing($this->curve([0 => 0.99, 100 => 0.80, 200 => 0.99]), 0.95);
        $this->assertSame(CrossingVerdict::NonMonotone, $nonMono->verdict);
        $this->assertNotNull($nonMono->estimate);
    }

    public function test_a_sweep_measures_a_rising_curve_that_crosses_the_target_and_is_reproducible(): void
    {
        $household = $this->cashPoorCouple();
        $lever = new StartingCashLever;
        $grid = [0.0, 100_000.0, 200_000.0, 300_000.0, 400_000.0, 500_000.0];

        $curve = $this->engine()->sweep(
            $household, $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable,
            $lever, $grid, SweepMetric::Essentials, nPaths: 250, seed: 7,
        );

        // A point per grid value, each with a well-formed Wilson interval: low <= high, both in
        // [0, 1]. (The Wilson centre is shifted off the point estimate, so p can sit marginally
        // outside its own interval at the extremes — that is correct, not a bug.)
        $this->assertCount(6, $curve->points);
        foreach ($curve->points as $pt) {
            $this->assertLessThanOrEqual($pt->ciHigh, $pt->ciLow);   // ciLow <= ciHigh
            $this->assertGreaterThanOrEqual(0.0, $pt->ciLow);
            $this->assertLessThanOrEqual(1.0, $pt->ciHigh);
        }

        // More starting cash raises success: the bottom of the grid is clearly below the target and
        // the top clearly above, so the curve genuinely crosses it.
        $this->assertLessThan(0.90, $curve->points[0]->successProbability);
        $this->assertGreaterThan(0.90, $curve->points[5]->successProbability);
        $crossing = $this->engine()->findCrossing($curve, 0.90);
        $this->assertSame(CrossingVerdict::Crosses, $crossing->verdict);
        $this->assertGreaterThan(0.0, $crossing->estimate);
        $this->assertLessThan(500_000.0, $crossing->estimate);

        // Pinned seed → byte-identical curve (the golden-master property; common random numbers).
        $again = $this->engine()->sweep(
            $household, $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable,
            $lever, $grid, SweepMetric::Essentials, nPaths: 250, seed: 7,
        );
        foreach ($curve->points as $i => $pt) {
            $this->assertSame($pt->successProbability, $again->points[$i]->successProbability);
        }
    }

    public function test_a_deterministic_median_pass_over_promises_on_a_survivor_cliff(): void
    {
        // S1: on a survivor-cliff household (a big slice of income stops on the first death), the
        // deterministic forecast runs each person at their INDEPENDENT median age — close together,
        // so the survivor period is short and the money lasts. But the Monte Carlo samples the tail
        // where the first death is early and the survivor lives long: a long, exposed survivor
        // period that runs short. So there is a spend the deterministic pass endorses at which the
        // real (MC) chance is well below the target a reader would set — the reason the crossing
        // must be found by MC, not a deterministic bracket.
        $household = $this->survivorCliffCouple();
        $deterministic = new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
        $lever = new EssentialSpendLever;
        $target = 0.95;

        $found = false;
        foreach ([18_000.0, 20_000.0, 22_000.0, 24_000.0, 26_000.0] as $spend) {
            $inputs = $lever->apply($household, $this->settings(), $spend);
            $det = $deterministic->forecast($inputs->household, AssumptionSetLibrary::default(), $inputs->settings);

            $curve = $this->engine()->sweep(
                $household, $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable,
                $lever, [$spend], SweepMetric::Essentials, nPaths: 400, seed: 3,
            );
            $mc = $curve->points[0]->successProbability;

            // A spend the deterministic median pass endorses, yet the MC chance falls short of target.
            if ($det->essentialsAlwaysMet && $mc < $target) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'a deterministic median pass should over-promise vs the MC target on a survivor-cliff household');
    }

    public function test_the_2d_frontier_is_a_threshold_of_one_lever_conditioned_on_another(): void
    {
        // S2: the spend the money can sustain is not one number — it depends on how much cash the
        // household holds. The frontier of the spend threshold vs starting cash should therefore
        // RISE with cash: more cash affords a higher sustainable spend.
        $frontier = $this->engine()->frontier(
            $this->cashPoorCouple(),
            $this->settings(),
            AssumptionSetLibrary::default(),
            new CohortLifeTable,
            new EssentialSpendLever,                              // threshold lever (swept)
            [24_000.0, 30_000.0, 36_000.0, 42_000.0, 48_000.0],  // spend grid
            new StartingCashLever,                               // condition lever (held)
            [100_000.0, 300_000.0, 500_000.0],                  // cash grid
            SweepMetric::Essentials,
            targetProbability: 0.90,
            nPaths: 200,
            seed: 4,
        );

        $this->assertCount(3, $frontier->points);

        // Collect the spend ceiling at each cash level (only where a real crossing was found).
        $ceilings = [];
        foreach ($frontier->points as $point) {
            if ($point->crossing->hasThreshold()) {
                $ceilings[] = $point->crossing->estimate;
            }
        }

        // The frontier is meaningful (several genuine thresholds) and monotone: more cash never
        // lowers the sustainable-spend ceiling, and the top of the cash range clears the bottom.
        $this->assertGreaterThanOrEqual(2, count($ceilings), 'the frontier should produce real thresholds');
        for ($i = 1; $i < count($ceilings); $i++) {
            $this->assertGreaterThanOrEqual($ceilings[$i - 1], $ceilings[$i], 'more cash should not lower the spend ceiling');
        }
        $this->assertGreaterThan($ceilings[0], end($ceilings), 'more cash affords a higher sustainable spend');
    }

    /** A couple with State Pensions but no other assets, spending above their income — success rises with cash. */
    private function cashPoorCouple(): Household
    {
        return new Household(
            'Cash-poor',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1955-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(30_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(210)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(210)),
            ],
        );
    }

    /** A survivor-cliff couple: a tax-free income stream on p1 stops on p1's death, exposing the survivor. */
    private function survivorCliffCouple(): Household
    {
        return new Household(
            'Survivor cliff',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1952-04-01'), Sex::Male, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1952-09-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(22_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(140)),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(90_000))],
            incomeStreams: [
                // A tax-free benefit on p1 (like DLA/AA) that stops when p1 dies — the cliff.
                new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(7_000), taxable: false, inflationLinked: true, startAge: 0),
            ],
        );
    }
}

/**
 * A synthetic lever for the tests: extra starting cash for the first person. More cash can only
 * help the money last, so success is monotone increasing in the value.
 */
final class StartingCashLever implements SweepLever
{
    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $accounts = [...$household->accounts, new Account($household->persons[0]->id, AccountType::Cash, Money::fromPence((int) round($value * 100)))];

        return new SweepInputs(
            new Household(
                $household->name, $household->region, $household->persons, $household->expenseProfile,
                $household->pensions, $accounts, $household->incomeStreams, $household->primaryResidence, $household->relationshipStatus,
            ),
            $settings,
        );
    }

    public function name(): string
    {
        return 'starting cash';
    }

    public function unit(): string
    {
        return '£';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Increasing;
    }
}

/**
 * A synthetic lever for the tests: the household's essential annual spend. Higher spend can only
 * lower the chance the money lasts, so success is monotone decreasing in the value.
 */
final class EssentialSpendLever implements SweepLever
{
    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $e = $household->expenseProfile;
        $profile = new ExpenseProfile(
            Money::fromPence((int) round($value * 100)),
            $e->discretionaryAnnualSpend,
            $e->survivorSpendFactor,
            $e->oneOffCosts,
            $e->propertyCosts,
            $e->employmentCosts,
            $e->mortgageCosts,
        );

        return new SweepInputs(
            new Household(
                $household->name, $household->region, $household->persons, $profile,
                $household->pensions, $household->accounts, $household->incomeStreams, $household->primaryResidence, $household->relationshipStatus,
            ),
            $settings,
        );
    }

    public function name(): string
    {
        return 'essential spend';
    }

    public function unit(): string
    {
        return '£';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Decreasing;
    }
}
