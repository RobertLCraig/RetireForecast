<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\HistoricalBacktester;
use RetireForecast\FinanceEngine\Forecast\HistoricalReturns;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class HistoricalBacktestTest extends TestCase
{
    private function backtester(): HistoricalBacktester
    {
        return new HistoricalBacktester(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /**
     * A retired couple (both 68, assumed to live to 92) with State Pensions of $weeklySp each,
     * a DC pot, and essential + discretionary spend.
     */
    private function couple(int $weeklySp, int $pot, int $essential, int $discretionary): Household
    {
        return new Household(
            'Backtest', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(92)),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(92)),
            ],
            new ExpenseProfile(Money::fromPounds($essential), Money::fromPounds($discretionary), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of($weeklySp, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of($weeklySp, 0)),
                new DcPension('p2', Money::fromPounds($pot), Money::zero(), Money::zero(), 55),
            ],
        );
    }

    public function test_the_historical_series_covers_the_expected_span_and_matches_the_source(): void
    {
        $this->assertSame(1871, HistoricalReturns::firstYear());
        $this->assertSame(2020, HistoricalReturns::lastYear());
        $this->assertCount(150, HistoricalReturns::years());

        // Spot-checks against the Jorda-Schularick-Taylor source (real total returns):
        // the 1973-74 UK crash and the 2008 GFC.
        $this->assertEqualsWithDelta(-0.5696, HistoricalReturns::equityReal(1974), 0.0005);
        $this->assertEqualsWithDelta(-0.3219, HistoricalReturns::equityReal(2008), 0.0005);
        $this->assertTrue(HistoricalReturns::inflation(1975) > 0.20, '1975 UK inflation was over 20%');
    }

    public function test_it_tests_every_start_year_with_at_least_the_minimum_historical_run(): void
    {
        // Default 10-year minimum window: 1871..(2020-9) = 1871..2011 = 141 start years.
        $result = $this->backtester()->backtest(
            $this->couple(241, 400_000, 22_000, 4_000),
            AssumptionSetLibrary::default(),
            $this->settings(),
        );

        $this->assertSame(141, $result->count());
        $this->assertSame(1871, $result->outcomes[0]->startYear);
        $this->assertSame(2011, $result->outcomes[$result->count() - 1]->startYear);
    }

    public function test_a_comfortable_plan_survives_every_historical_start(): void
    {
        $result = $this->backtester()->backtest(
            $this->couple(241, 400_000, 22_000, 4_000),
            AssumptionSetLibrary::default(),
            $this->settings(),
        );

        $this->assertSame(1.0, $result->survivalRate());
        $this->assertNotNull($result->worst());
    }

    public function test_a_doomed_plan_survives_no_start(): void
    {
        // Spend far above what the income + pot can sustain: the pot empties in every sequence.
        $result = $this->backtester()->backtest(
            $this->couple(180, 120_000, 45_000, 0),
            AssumptionSetLibrary::default(),
            $this->settings(),
        );

        $this->assertSame(0.0, $result->survivalRate());
        $this->assertNotNull($result->worst()->depletionCalendarYear);
    }

    public function test_sequence_risk_a_borderline_plan_fails_a_bad_start_but_survives_a_calm_one(): void
    {
        // A drawdown-reliant plan on a knife-edge: whether it lasts depends on WHEN it starts.
        $result = $this->backtester()->backtest(
            $this->couple(180, 240_000, 24_000, 8_000),
            AssumptionSetLibrary::default(),
            $this->settings(),
        );

        // Some historical starts survive, some do not — the whole point of sequence risk.
        $this->assertGreaterThan(0.0, $result->survivalRate());
        $this->assertLessThan(1.0, $result->survivalRate());

        // Retiring into the 1973-74 crash breaks the plan; retiring into the calm mid-1980s does not.
        $badStart = $result->forStartYear(1973);
        $calmStart = $result->forStartYear(1985);
        $this->assertNotNull($badStart);
        $this->assertNotNull($calmStart);
        $this->assertFalse($badStart->essentialsAlwaysMet, 'retiring into 1973 should run the money out');
        $this->assertNotNull($badStart->depletionCalendarYear);
        $this->assertTrue($calmStart->essentialsAlwaysMet, 'retiring into 1985 should survive');
    }

    /**
     * The same couple with NO stated lifespan, so their death ages come from the cohort life table
     * and therefore move with the planning horizon. The fixture above pins both to 92, and a
     * lifespan the reader stated is never extended (board card 0061), so it could not see this.
     */
    private function coupleOnCohortLifespans(int $weeklySp, int $pot, int $essential, int $discretionary): Household
    {
        return new Household(
            'Backtest', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds($essential), Money::fromPounds($discretionary), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of($weeklySp, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of($weeklySp, 0)),
                new DcPension('p2', Money::fromPounds($pot), Money::zero(), Money::zero(), 55),
            ],
        );
    }

    public function test_the_backtest_can_be_run_at_a_longer_life_horizon_than_the_representative_one(): void
    {
        // Board card 0064: a bad early sequence and a long life are two risks that MULTIPLY, and
        // holding the lifespan at the representative horizon means the stress test never combines
        // them. Asked for a longer horizon, every start year funds more years of life.
        $household = $this->coupleOnCohortLifespans(241, 400_000, 22_000, 4_000);

        $representative = $this->backtester()->backtest($household, AssumptionSetLibrary::default(), $this->settings());
        $longLife = $this->backtester()->backtest($household, AssumptionSetLibrary::default(), $this->settings(), horizon: PlanningHorizon::P90);

        $this->assertGreaterThan(
            $representative->forStartYear(1973)->planYears,
            $longLife->forStartYear(1973)->planYears,
            'the long-life horizon should carry the last survivor further',
        );
    }

    public function test_the_long_life_horizon_is_the_harder_test_of_the_same_plan(): void
    {
        // A borderline plan: more years of spending off the same pot, over the same market
        // sequences, so no start year can survive the long horizon that failed the shorter one.
        $household = $this->coupleOnCohortLifespans(180, 240_000, 24_000, 8_000);

        $representative = $this->backtester()->backtest($household, AssumptionSetLibrary::default(), $this->settings(), horizon: PlanningHorizon::P50);
        $longLife = $this->backtester()->backtest($household, AssumptionSetLibrary::default(), $this->settings(), horizon: PlanningHorizon::P90);

        $this->assertLessThan($representative->survivalRate(), $longLife->survivalRate());
    }

    public function test_a_stated_lifespan_is_not_extended_by_the_long_life_horizon(): void
    {
        // The horizon is a percentile of a MODELLED death age. Where the reader stated the age
        // themselves it is a fact, not an estimate, and asking for a longer horizon must not
        // silently overwrite it (board card 0061).
        $household = $this->couple(241, 400_000, 22_000, 4_000);

        $representative = $this->backtester()->backtest($household, AssumptionSetLibrary::default(), $this->settings());
        $longLife = $this->backtester()->backtest($household, AssumptionSetLibrary::default(), $this->settings(), horizon: PlanningHorizon::P90);

        $this->assertSame($representative->forStartYear(1973)->planYears, $longLife->forStartYear(1973)->planYears);
    }

    public function test_a_bad_start_leaves_less_terminal_wealth_than_a_calm_one(): void
    {
        // Even for a plan that survives every start, sequence matters: a bad start ends far poorer.
        $result = $this->backtester()->backtest(
            $this->couple(241, 400_000, 22_000, 4_000),
            AssumptionSetLibrary::default(),
            $this->settings(),
        );

        $depression = $result->forStartYear(1929);
        $calm = $result->forStartYear(1985);
        $this->assertNotNull($depression);
        $this->assertNotNull($calm);
        $this->assertLessThan(
            $calm->terminalUsableWealth->pence,
            $depression->terminalUsableWealth->pence,
            'retiring into 1929 should leave less than retiring into 1985',
        );
    }
}
