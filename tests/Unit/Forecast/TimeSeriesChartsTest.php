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
 * The three hero time-series charts (C1 income staircase, C2 wealth composition, C3 costs)
 * are pictures of the SAME per-year figures the cashflow ladder lists — built from one
 * {@see ForecastResult}. The trust-critical properties are therefore reconciliation (a
 * chart's <details> table sums to the same totals) and completeness (every income source
 * that occurs reaches the accessible table — no silent drop, even when the CHART folds the
 * smallest into "Other"). A chart that disagreed with the ladder would be the exact
 * inconsistent-aggregation failure the data-layer rule exists to prevent.
 */
final class TimeSeriesChartsTest extends TestCase
{
    private function forecast(array $state): ForecastResult
    {
        $household = (new HouseholdAssembler)->household($state);

        return (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    /** A working-and-retired couple with a home, pensions, savings and a discretionary layer. */
    private function richState(): array
    {
        return [
            'householdName' => 'Charts', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [
                ['id' => 'p1', 'dob' => '1962-01-01', 'sex' => 'male', 'employmentStatus' => 'employed', 'grossSalary' => '40000', 'plannedRetirementAge' => 66],
                ['id' => 'p2', 'dob' => '1960-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '221'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '221'],
                ['id' => 'db2', 'ownerId' => 'p2', 'subtype' => 'db', 'accruedAnnualPension' => '9000', 'normalRetirementAge' => 65],
                ['id' => 'dc1', 'ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '150000', 'earliestAccessAge' => 57,
                    'withdrawals' => [['kind' => 'drawdown', 'amount' => '8000', 'atAge' => 67]]],
            ],
            'accounts' => [
                ['id' => 'a1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => '120000'],
                ['id' => 'a2', 'ownerId' => 'p2', 'type' => 'gia', 'balance' => '60000'],
            ],
            'hasProperty' => true,
            'property' => ['currentValue' => '400000', 'ownership' => 'outright', 'runningCosts' => '3000'],
            'expenseLines' => [
                ['id' => 'e1', 'amount' => '26000', 'category' => 'essential'],
                ['id' => 'd1', 'amount' => '10000', 'category' => 'discretionary'],
            ],
            'expense' => ['survivorFactor' => '70'],
        ];
    }

    public function test_income_chart_table_lists_every_source_and_reconciles_to_the_ladder(): void
    {
        $forecast = $this->forecast($this->richState());
        $charts = ResultPresenter::timeSeriesCharts($forecast);
        $ladder = ResultPresenter::ladder($forecast);

        $income = $charts['income'];
        $this->assertNotEmpty($income['rows']);

        // Completeness: the income table lists exactly the sources the ladder does (one
        // definition — the same sourceOccurs filter), so no source is silently dropped.
        $this->assertSame($ladder['sources'], $income['sources']);

        $ladderByYear = collect($ladder['rows'])->keyBy('year');
        foreach ($income['rows'] as $row) {
            // The per-source figures match the ladder's cell-for-cell.
            foreach ($income['sources'] as $source) {
                $this->assertSame(
                    $ladderByYear[$row['year']]['income'][$source],
                    $row['income'][$source],
                    "income source {$source} must match the ladder in {$row['year']}",
                );
            }

            // Reconciliation: the source columns sum to the row total (gross income).
            $sum = 0;
            foreach ($income['sources'] as $source) {
                $sum += MoneyText::toPence($row['income'][$source]);
            }
            $this->assertSame(MoneyText::toPence($row['total']), $sum, "income must reconcile in {$row['year']}");
        }
    }

    public function test_wealth_chart_reconciles_pension_liquid_home_to_total_and_the_ladder(): void
    {
        $forecast = $this->forecast($this->richState());
        $charts = ResultPresenter::timeSeriesCharts($forecast);
        $ladder = ResultPresenter::ladder($forecast);

        $wealth = $charts['wealth'];
        $this->assertNotEmpty($wealth['rows']);

        $ladderByYear = collect($ladder['rows'])->keyBy('year');
        foreach ($wealth['rows'] as $row) {
            // The three legs sum to the net-worth total, exactly (totalWealth is derived
            // from pension + liquid + home equity, so this can never drift).
            $this->assertSame(
                MoneyText::toPence($row['total']),
                MoneyText::toPence($row['pension']) + MoneyText::toPence($row['liquid']) + MoneyText::toPence($row['homeEquity']),
                "wealth legs must reconcile in {$row['year']}",
            );

            // And the total is the same total (incl. home equity) the ladder carries.
            $this->assertSame($ladderByYear[$row['year']]['totalWealth'], $row['total'], "wealth total must match the ladder in {$row['year']}");
        }

        // A home is present, so home equity is a real (positive) leg at least once.
        $this->assertNotEmpty(array_filter($wealth['rows'], fn (array $r): bool => MoneyText::toPence($r['homeEquity']) > 0));
    }

    public function test_costs_chart_reconciles_essential_plus_discretionary_to_the_ladder(): void
    {
        $forecast = $this->forecast($this->richState());
        $charts = ResultPresenter::timeSeriesCharts($forecast);
        $ladder = ResultPresenter::ladder($forecast);

        $costs = $charts['costs'];
        $this->assertNotEmpty($costs['rows']);

        $ladderByYear = collect($ladder['rows'])->keyBy('year');
        foreach ($costs['rows'] as $row) {
            $this->assertSame(
                MoneyText::toPence($row['total']),
                MoneyText::toPence($row['essential']) + MoneyText::toPence($row['discretionary']),
                "costs must reconcile in {$row['year']}",
            );

            // Same split the ladder itemises (one definition).
            $this->assertSame($ladderByYear[$row['year']]['essentialSpend'], $row['essential'], "essential must match the ladder in {$row['year']}");
            $this->assertSame($ladderByYear[$row['year']]['spend'], $row['total'], "spend total must match the ladder in {$row['year']}");
        }
    }

    public function test_chart_series_are_stacked_area_over_non_negative_real_pounds(): void
    {
        $forecast = $this->forecast($this->richState());
        $charts = ResultPresenter::timeSeriesCharts($forecast);

        foreach (['income', 'wealth', 'costs'] as $key) {
            $options = $charts[$key]['options'];
            $this->assertSame('area', $options['chart']['type'], "{$key} is an area chart");
            $this->assertTrue($options['chart']['stacked'], "{$key} is stacked");
            $this->assertSame(0, $options['yaxis']['min'], "{$key} anchors at zero");
            $this->assertNotEmpty($options['series']);

            // A colour per plotted series, and every plotted point is a non-negative pounds y.
            $this->assertCount(count($options['series']), $options['colors']);
            foreach ($options['series'] as $series) {
                foreach ($series['data'] as $point) {
                    $this->assertIsInt($point['y']);
                    $this->assertGreaterThanOrEqual(0, $point['y'], "{$key} series {$series['name']} must be non-negative");
                }
            }
        }
    }

    public function test_income_chart_caps_at_the_palette_and_folds_the_rest_without_dropping_any_source(): void
    {
        // A household that lights up more than eight income sources across the horizon: salary,
        // DB, State Pension, a taxable annuity/rental stream (other_taxable), GIA investment
        // income, a tax-free disability benefit, a DC drawdown + its tax-free cash, savings
        // drawn, and a one-off capital receipt.
        $state = $this->richState();
        $state['incomeStreams'] = [
            ['id' => 'r1', 'ownerId' => 'p2', 'type' => 'rental', 'grossAnnual' => '6000', 'taxable' => true, 'startAge' => 60],
            ['id' => 'dla', 'ownerId' => 'p2', 'type' => 'disability_benefit', 'grossAnnual' => '4000', 'startAge' => 60],
        ];
        $state['capitalReceipts'] = [
            ['ownerId' => 'p1', 'label' => 'Gift', 'amount' => '20000', 'year' => 2027],
        ];
        // A PCLS as well as the drawdown, so pension_lump_sum occurs too.
        $state['pensions'][3]['withdrawals'] = [
            ['kind' => 'pcls', 'amount' => '10000', 'atAge' => 66],
            ['kind' => 'drawdown', 'amount' => '8000', 'atAge' => 67],
        ];

        $forecast = $this->forecast($state);
        $income = ResultPresenter::timeSeriesCharts($forecast)['income'];

        // More than eight sources occur, so the chart folds the smallest into "Other" — but the
        // table still lists every one of them (completeness: no silent drop).
        $this->assertGreaterThan(8, count($income['sources']));
        $this->assertNotEmpty($income['folded']);

        // The chart carries the eight palette hues plus exactly one "Other" band.
        $this->assertCount(9, $income['options']['series']);
        $this->assertCount(9, $income['options']['colors']);
        $this->assertSame('Other income', $income['options']['series'][8]['name']);

        // Every folded source is still a column in the accessible table.
        foreach ($income['folded'] as $source) {
            $this->assertContains($source, $income['sources']);
        }

        // And the table still reconciles source-columns to the total each year.
        foreach ($income['rows'] as $row) {
            $sum = 0;
            foreach ($income['sources'] as $source) {
                $sum += MoneyText::toPence($row['income'][$source]);
            }
            $this->assertSame(MoneyText::toPence($row['total']), $sum, "income must reconcile in {$row['year']}");
        }
    }
}
