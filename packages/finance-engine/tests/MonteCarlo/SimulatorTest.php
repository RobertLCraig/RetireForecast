<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\MonteCarlo;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\ReturnModel;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class SimulatorTest extends TestCase
{
    private function simulator(): Simulator
    {
        return new Simulator(TaxYearRegistry::for('2026-27'));
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    private function comfortable(): Household
    {
        return new Household(
            'MC couple',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(4_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(350_000), Money::zero(), Money::zero(), 55),
            ],
        );
    }

    public function test_run_is_reproducible_under_a_fixed_seed(): void
    {
        $a = $this->simulator()->run($this->comfortable(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable, 200, seed: 7);
        $b = $this->simulator()->run($this->comfortable(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable, 200, seed: 7);

        $this->assertSame($a->successProbabilityEssentials, $b->successProbabilityEssentials);
        $this->assertSame($a->terminalWealthPercentiles['p50']->pence, $b->terminalWealthPercentiles['p50']->pence);
        $this->assertSame($a->fanChart[0]['p50']->pence, $b->fanChart[0]['p50']->pence);
        $this->assertSame(7, $a->seed);
    }

    public function test_different_seeds_give_different_paths(): void
    {
        $a = $this->simulator()->run($this->comfortable(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable, 200, seed: 1);
        $b = $this->simulator()->run($this->comfortable(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable, 200, seed: 2);

        // Terminal medians should not coincide exactly across independent seeds.
        $this->assertNotSame($a->terminalWealthPercentiles['p50']->pence, $b->terminalWealthPercentiles['p50']->pence);
    }

    public function test_comfortable_household_has_high_success_probability(): void
    {
        $result = $this->simulator()->run($this->comfortable(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable, 400, seed: 42);

        $this->assertGreaterThan(0.95, $result->successProbabilityEssentials);
        $this->assertGreaterThanOrEqual(0.0, $result->successProbabilityFullSpend);
        $this->assertLessThanOrEqual(1.0, $result->successProbabilityFullSpend);
        $this->assertNotEmpty($result->fanChart);
    }

    public function test_underfunded_household_has_lower_success_than_comfortable(): void
    {
        $comfortable = $this->simulator()->run($this->comfortable(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable, 300, seed: 5);

        $underfunded = new Household(
            'Underfunded',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1964-04-01'), Sex::Female, EmploymentStatus::NotWorking),
                new Person('p2', new DateTimeImmutable('1964-09-01'), Sex::Male, EmploymentStatus::NotWorking),
            ],
            new ExpenseProfile(Money::fromPounds(28_000), Money::fromPounds(6_000), Percent::fromPercent(70)),
            [new DcPension('p2', Money::fromPounds(40_000), Money::zero(), Money::zero(), 55)],
        );

        $result = $this->simulator()->run($underfunded, $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable, 300, seed: 5);

        $this->assertLessThan($comfortable->successProbabilityEssentials, $result->successProbabilityEssentials);
        $this->assertGreaterThan(0.0, $result->depletionRate);
    }

    public function test_usable_wealth_percentiles_are_reported_and_not_above_total(): void
    {
        $result = $this->simulator()->run($this->comfortable(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable, 200, seed: 11);

        $this->assertArrayHasKey('p50', $result->usableWealthPercentiles);
        // Usable (excl. the home) can never exceed total (incl. the home). This
        // couple owns no property, so the two coincide; the invariant must hold.
        $this->assertLessThanOrEqual(
            $result->terminalWealthPercentiles['p50']->pence,
            $result->usableWealthPercentiles['p50']->pence,
        );
    }

    public function test_usable_fan_tracks_the_total_fan_year_by_year_and_never_exceeds_it(): void
    {
        // A home-owning couple: the per-year usable fan (excl. home) must share the total
        // fan's calendar years and sit at or below it in every year and percentile, and the
        // £400k home must pull usable strictly below total in at least one year (proving the
        // exclusion is real, not just plumbed). usable = liquid + pension, the one definition.
        $household = new Household(
            'MC couple with a home',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(4_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(350_000), Money::zero(), Money::zero(), 55),
            ],
            primaryResidence: new Property(Money::fromPounds(400_000), OwnershipType::Outright),
        );

        $result = $this->simulator()->run($household, $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable, 200, seed: 13);

        $this->assertNotEmpty($result->usableFanChart);
        $this->assertSameSize($result->fanChart, $result->usableFanChart);

        $separatedYears = 0;
        foreach ($result->fanChart as $i => $totalBand) {
            $usableBand = $result->usableFanChart[$i];
            $this->assertSame($totalBand['calendarYear'], $usableBand['calendarYear'], 'the fans must share calendar years, in order');
            foreach (['p10', 'p25', 'p50', 'p75', 'p90'] as $p) {
                $this->assertLessThanOrEqual(
                    $totalBand[$p]->pence,
                    $usableBand[$p]->pence,
                    "usable {$p} exceeds total in {$totalBand['calendarYear']}",
                );
            }
            if ($usableBand['p50']->pence < $totalBand['p50']->pence) {
                $separatedYears++;
            }
        }

        $this->assertGreaterThan(0, $separatedYears, 'the home should pull usable below total in at least one year');
    }

    public function test_zero_volatility_returns_collapse_to_the_mean(): void
    {
        $set = new AssumptionSet(
            name: 'zero-vol',
            sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::fromPercent(4), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::fromPercent(1), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::fromPercent(0), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::fromPercent(2),
            inflationVolatility: Percent::zero(),
            houseGrowth: Percent::fromPercent(1),
            rentInflation: Percent::fromPercent(0),
            salaryGrowth: Percent::fromPercent(1),
            investmentIncomeYield: Percent::fromPercent(2),
        );

        $model = new ReturnModel($set, new PortfolioAllocation([0.40, 0.60, 0.0]));
        $path = $model->generatePath(10, new Randomizer(new Mt19937(99)));

        // 40% x 4% + 60% x 1% = 2.2% every year, exactly, with no volatility.
        foreach ($path['investment'] as $r) {
            $this->assertEqualsWithDelta(0.022, $r, 1e-9);
        }
        foreach ($path['inflation'] as $infl) {
            $this->assertEqualsWithDelta(0.02, $infl, 1e-9);
        }
    }
}
