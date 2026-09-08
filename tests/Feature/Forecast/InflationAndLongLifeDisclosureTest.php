<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\ResultPresenter;
use DateTimeImmutable;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\HistoricalBacktester;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use Tests\TestCase;

/**
 * The two surfaces board card 0064 owes a reader. The inflation draw now carries a persistence
 * and a correlation with real returns, and both move every plan, so under the no-invisible-figures
 * rule both have to be visible and interrogable rather than sitting inside the model. And the
 * historical stress test now reports what the plan survives when a bad early sequence and a long
 * life are asked TOGETHER, which is the pair that multiplies.
 */
final class InflationAndLongLifeDisclosureTest extends TestCase
{
    private function household(): Household
    {
        return new Household(
            'Disclosure couple', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(24_000), Money::fromPounds(8_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(180, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(180, 0)),
                new DcPension('p2', Money::fromPounds(240_000), Money::zero(), Money::zero(), 55),
            ],
        );
    }

    public function test_the_assumptions_panel_shows_the_inflation_persistence_and_its_market_correlation(): void
    {
        $panel = ResultPresenter::assumptionsPanel(
            AssumptionSetLibrary::default(),
            new HousingAction(Money::fromPounds(400_000)),
            PortfolioAllocation::cautious40_60(),
        );

        $rows = collect($panel['economic'])->keyBy('key');

        $this->assertTrue($rows->has('inflationPersistence'), 'the inflation persistence must be on the page');
        $this->assertTrue($rows->has('inflationAssetCorrelation'), 'the inflation/return correlation must be on the page');

        // The rows read the figures the model actually ran on rather than restating them, so a
        // re-source moves the sentence with the constant. 0.70 persistence, and the hardest-hit
        // asset's correlation, are what the shipped default carries.
        $this->assertSame('70%', $rows['inflationPersistence']['value']);
        $this->assertSame('-0.55', $rows['inflationAssetCorrelation']['value']);

        // Both sit immediately after the inflation mean they qualify, not adrift in the list.
        $keys = array_column($panel['economic'], 'key');
        $at = (int) array_search('inflation', $keys, true);
        $this->assertSame(['inflationPersistence', 'inflationAssetCorrelation'], array_slice($keys, $at + 1, 2));
    }

    public function test_a_set_that_models_no_inflation_dynamics_shows_neither_row(): void
    {
        // A stored snapshot from before the card states neither figure, and reads as the old
        // memoryless independent draw. Showing a 0% persistence row would describe a modelling
        // choice as though it were an economic one.
        $set = AssumptionSetLibrary::default();
        $plain = new AssumptionSet(
            name: $set->name,
            sourceNote: $set->sourceNote,
            assetClasses: $set->assetClasses,
            correlationMatrix: $set->correlationMatrix,
            inflationMean: $set->inflationMean,
            inflationVolatility: $set->inflationVolatility,
            houseGrowth: $set->houseGrowth,
            rentInflation: $set->rentInflation,
            salaryGrowth: $set->salaryGrowth,
            investmentIncomeYield: $set->investmentIncomeYield,
        );

        $panel = ResultPresenter::assumptionsPanel(
            $plain,
            new HousingAction(Money::fromPounds(400_000)),
            PortfolioAllocation::cautious40_60(),
        );

        $keys = array_column($panel['economic'], 'key');
        $this->assertNotContains('inflationPersistence', $keys);
        $this->assertNotContains('inflationAssetCorrelation', $keys);
    }

    public function test_the_stress_test_panel_reports_the_long_life_run_beside_the_representative_one(): void
    {
        $backtester = new HistoricalBacktester(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $household = $this->household();

        $representative = $backtester->backtest($household, AssumptionSetLibrary::default(), $settings);
        $longLife = $backtester->backtest($household, AssumptionSetLibrary::default(), $settings, horizon: PlanningHorizon::P90);

        $panel = ResultPresenter::historicalStressTest($representative, 2026, $longLife);

        $this->assertNotNull($panel['longLife'], 'the long-life run must reach the panel');
        $this->assertSame(PlanningHorizon::P90->label(), $panel['longLife']['label']);
        $this->assertSame(PlanningHorizon::P90->oddsPhrase(), $panel['longLife']['oddsPhrase']);
        // A harder test of the same plan: it cannot survive more starts than the shorter horizon did.
        $this->assertLessThanOrEqual($panel['survivalPct'], $panel['longLife']['survivalPct']);
    }

    public function test_the_panel_omits_the_long_life_run_when_it_is_the_same_run(): void
    {
        // Asked for a long-life run that turns out identical (here, by handing it the same result),
        // the panel says nothing rather than reporting one figure twice under a longer-sounding
        // label, which would read as a harder test that had been passed.
        $backtester = new HistoricalBacktester(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $result = $backtester->backtest($this->household(), AssumptionSetLibrary::default(), $settings);

        $this->assertNull(ResultPresenter::historicalStressTest($result, 2026, $result)['longLife']);
        $this->assertNull(ResultPresenter::historicalStressTest($result, 2026)['longLife']);
    }
}
