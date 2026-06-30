<?php

declare(strict_types=1);

namespace Tests\Unit\Demo;

use App\Demo\DemoScenario;
use App\Forecast\BuilderStateDelta;
use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The demo preset's figures are in the one canonical shape, so they assemble to the
 * engine DTOs and run exactly like a user-built scenario (no parallel representation
 * that could drift), the spend totals reconcile to the line items, the sample is
 * obviously fictional, and the what-if is a value-only delta that demonstrably reaches
 * the forecast.
 */
class DemoScenarioTest extends TestCase
{
    public function test_the_demo_base_state_assembles_to_a_runnable_household(): void
    {
        $assembler = new HouseholdAssembler;
        $household = $assembler->household(DemoScenario::baseState());

        $this->assertCount(2, $household->persons);
        $this->assertCount(4, $household->pensions); // dc + db + two state pensions
        $this->assertNotNull($household->primaryResidence);

        $action = $assembler->housingAction(DemoScenario::baseState()['housing']);
        $this->assertTrue($action->salePrice->isPositive());
        $this->assertNotNull($action->buyPrice);   // buy-cheaper-outright candidate
        $this->assertNotNull($action->annualRent); // and a sell-and-rent candidate
    }

    public function test_the_demo_spend_totals_reconcile_to_the_sum_of_the_lines(): void
    {
        $profile = (new HouseholdAssembler)->household(DemoScenario::baseState())->expenseProfile;

        // essential = £24,000; discretionary = £9,000 + the *spent* £1,200 course = £10,200.
        $this->assertTrue($profile->essentialAnnualSpend->equals(Money::fromPence(2_400_000)));
        $this->assertTrue($profile->discretionaryAnnualSpend->equals(Money::fromPence(1_020_000)));
    }

    public function test_the_demo_is_obviously_fictional(): void
    {
        $state = DemoScenario::baseState();

        $this->assertStringContainsStringIgnoringCase('fictional', $state['householdName']);
        foreach ($state['people'] as $person) {
            $this->assertStringContainsStringIgnoringCase('fictional', (string) $person['name']);
        }
    }

    public function test_the_what_if_is_a_value_only_delta_on_the_retirement_age(): void
    {
        $base = DemoScenario::baseState();
        $edited = DemoScenario::retireEarlyState();

        // This what-if changes values only — no rows added (a whole-row array value) or removed
        // (the REMOVED sentinel); the retirement age is among the changed leaves.
        $overrides = BuilderStateDelta::diff($base, $edited);
        foreach ($overrides as $value) {
            $this->assertIsNotArray($value);
            $this->assertNotSame(BuilderStateDelta::REMOVED, $value);
        }
        $this->assertSame('64', $overrides['people.p1.plannedRetirementAge']);
        $this->assertSame('66', $base['people'][0]['plannedRetirementAge']); // the base is unchanged
    }

    public function test_retiring_earlier_lowers_the_central_forecast_wealth(): void
    {
        $forecaster = new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        );
        $assumptions = AssumptionSetLibrary::default();
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $assembler = new HouseholdAssembler;

        $base = $forecaster->forecast($assembler->household(DemoScenario::baseState()), $assumptions, $settings);
        $early = $forecaster->forecast($assembler->household(DemoScenario::retireEarlyState()), $assumptions, $settings);

        // Two fewer salary years means less accumulated wealth — the lever reaches the result.
        $this->assertLessThan($base->terminalTotalWealth->pence, $early->terminalTotalWealth->pence);
    }
}
