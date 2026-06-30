<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use App\Import\MoneyText;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The cashflow ladder now itemises each year's spend into its essential floor and the
 * discretionary remainder, so the spend is traceable rather than a single opaque number.
 * The trust-critical property is reconciliation: essential + discretionary must equal the
 * spend shown, every year — a derived split that drifts from its total would be the exact
 * inconsistent-aggregation failure the data-layer rule exists to prevent.
 */
final class LadderSpendSplitTest extends TestCase
{
    private function forecast(): ForecastResult
    {
        // A couple with a clear discretionary layer above their essentials, so the split is
        // non-trivial (both parts positive).
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'Splits', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'dob' => '1955-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'dob' => '1955-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '200000']],
            'expenseLines' => [
                ['id' => 'e1', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'd1', 'amount' => '8000', 'category' => 'discretionary'],
            ],
            'expense' => ['survivorFactor' => '70'],
        ]);

        return (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_essential_plus_discretionary_reconciles_to_the_spend_every_year(): void
    {
        $ladder = ResultPresenter::ladder($this->forecast());

        $this->assertNotEmpty($ladder['rows']);
        foreach ($ladder['rows'] as $row) {
            $this->assertArrayHasKey('essentialSpend', $row);
            $this->assertArrayHasKey('discretionarySpend', $row);

            $this->assertSame(
                MoneyText::toPence($row['spend']),
                MoneyText::toPence($row['essentialSpend']) + MoneyText::toPence($row['discretionarySpend']),
                "Spend split must reconcile in {$row['year']}",
            );
        }
    }

    public function test_each_year_is_classified_surplus_drawing_or_shortfall(): void
    {
        $ladder = ResultPresenter::ladder($this->forecast());

        foreach ($ladder['rows'] as $row) {
            $this->assertContains($row['status'], ['surplus', 'drawing', 'shortfall'], "year {$row['year']}");
            $this->assertIsBool($row['belowFloor']);
        }

        // This couple's State Pension alone doesn't meet their £28k spend, so they draw on the ISA.
        $this->assertContains('drawing', array_column($ladder['rows'], 'status'));
    }

    public function test_the_safety_buffer_is_configurable_and_flags_below_buffer_years(): void
    {
        $forecast = $this->forecast();

        // A huge buffer (50 years of essentials) puts even the first year below it.
        $tight = ResultPresenter::ladder($forecast, bufferMonths: 600);
        $this->assertSame(600, $tight['bufferMonths']);
        $this->assertTrue($tight['rows'][0]['belowFloor']);
        $this->assertSame($tight['rows'][0]['year'], $tight['floorBreachYear']);

        // A zero buffer flags only actually running out — a funded year is never "below £0".
        $none = ResultPresenter::ladder($forecast, bufferMonths: 0);
        $this->assertSame(0, $none['bufferMonths']);
        $this->assertFalse($none['rows'][0]['belowFloor']);
    }

    public function test_the_split_is_non_trivial_at_least_once_so_the_columns_earn_their_place(): void
    {
        $ladder = ResultPresenter::ladder($this->forecast());

        // At least one year has a real discretionary layer on top of essentials (both > 0),
        // otherwise the itemisation would be vacuous.
        $hasBoth = false;
        foreach ($ladder['rows'] as $row) {
            if (MoneyText::toPence($row['essentialSpend']) > 0 && MoneyText::toPence($row['discretionarySpend']) > 0) {
                $hasBoth = true;
                break;
            }
        }
        $this->assertTrue($hasBoth);
    }
}
