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
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
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
 *
 * The nominal-pounds toggle is held to the same bar, plus one of its own: the figures it shows
 * must be the engine's own pre-deflation year ({@see YearResult::$nominal}), not these deflated
 * ones multiplied back up, and a forecast that carries no such year must report the view as
 * unavailable rather than label real money as nominal.
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

    public function test_the_nominal_toggle_shows_the_engines_own_pre_deflation_figures(): void
    {
        $forecast = $this->forecast($this->richState());

        $real = ResultPresenter::timeSeriesCharts($forecast);
        $nominal = ResultPresenter::timeSeriesCharts($forecast, nominal: true);

        $this->assertFalse($real['nominal']);
        $this->assertTrue($nominal['nominal']);
        $this->assertTrue($nominal['nominalAvailable']);

        // Every plotted and tabulated figure is read STRAIGHT off YearResult::$nominal, the
        // projector's pre-deflation year. Nothing here re-inflates a deflated figure, so this
        // compares the presenter's output with the engine value it must have used.
        $twinByYear = [];
        foreach ($forecast->years as $year) {
            $twinByYear[$year->calendarYear] = $year->nominal;
        }

        foreach ($nominal['wealth']['rows'] as $row) {
            $twin = $twinByYear[$row['year']];
            $this->assertSame($twin->pensionWealth->format(), $row['pension'], "pensions must be the engine's nominal figure in {$row['year']}");
            $this->assertSame($twin->liquidWealth->format(), $row['liquid'], "savings must be the engine's nominal figure in {$row['year']}");
            $this->assertSame($twin->homeEquity()->format(), $row['homeEquity'], "home equity must be the engine's nominal figure in {$row['year']}");
            $this->assertSame($twin->totalWealth->format(), $row['total'], "the total must be the engine's nominal figure in {$row['year']}");
        }

        foreach ($nominal['costs']['rows'] as $row) {
            $twin = $twinByYear[$row['year']];
            $this->assertSame($twin->essentialSpend->format(), $row['essential'], "essential spend must be the engine's nominal figure in {$row['year']}");
            $this->assertSame($twin->spendTarget->format(), $row['total'], "the spend target must be the engine's nominal figure in {$row['year']}");
        }

        foreach ($nominal['income']['rows'] as $row) {
            $twin = $twinByYear[$row['year']];
            foreach ($nominal['income']['sources'] as $source) {
                $this->assertSame(
                    ($twin->incomeBySource[$source] ?? Money::zero())->format(),
                    $row['income'][$source],
                    "income source {$source} must be the engine's nominal figure in {$row['year']}",
                );
            }
        }

        // The two views are the same money on two yardsticks, so the base year (price level 1.0)
        // must read identically and the later years must not: a toggle that changed only the
        // wording would pass the first of these and fail the second.
        $this->assertSame($real['costs']['rows'][0]['total'], $nominal['costs']['rows'][0]['total']);
        $lastReal = $real['costs']['rows'][count($real['costs']['rows']) - 1];
        $lastNominal = $nominal['costs']['rows'][count($nominal['costs']['rows']) - 1];
        $this->assertGreaterThan(MoneyText::toPence($lastReal['total']), MoneyText::toPence($lastNominal['total']));

        // The basis is named once and every label follows it, so a chart can never be drawn on
        // one basis and captioned as the other.
        $this->assertSame('cash pounds', $nominal['basisShort']);
        $this->assertSame('real pounds', $real['basisShort']);
        $this->assertSame('Spending (cash £)', $nominal['costs']['options']['yaxis']['title']['text']);
        $this->assertSame('Spending (real £)', $real['costs']['options']['yaxis']['title']['text']);
    }

    public function test_the_nominal_view_still_reconciles_and_lists_every_source(): void
    {
        $forecast = $this->forecast($this->richState());
        $charts = ResultPresenter::timeSeriesCharts($forecast, nominal: true);

        // Same completeness and reconciliation bar as the real view: the same sources occur,
        // the columns sum to the row total, and the wealth legs sum to the net-worth total.
        $this->assertSame(
            ResultPresenter::timeSeriesCharts($forecast)['income']['sources'],
            $charts['income']['sources'],
        );

        foreach ($charts['income']['rows'] as $row) {
            $sum = 0;
            foreach ($charts['income']['sources'] as $source) {
                $sum += MoneyText::toPence($row['income'][$source]);
            }
            $this->assertSame(MoneyText::toPence($row['total']), $sum, "nominal income must reconcile in {$row['year']}");
        }

        foreach ($charts['wealth']['rows'] as $row) {
            $this->assertSame(
                MoneyText::toPence($row['total']),
                MoneyText::toPence($row['pension']) + MoneyText::toPence($row['liquid']) + MoneyText::toPence($row['homeEquity']),
                "nominal wealth legs must reconcile in {$row['year']}",
            );
        }
    }

    public function test_a_forecast_without_pre_deflation_figures_cannot_offer_the_nominal_view(): void
    {
        // A year restored from an older stored result carries no twin. Rather than show real
        // money under a nominal label, the presenter reports the view as unavailable and hands
        // back the real figures, so the caller hides the toggle.
        $bare = new ForecastResult(
            [
                new YearResult(
                    yearIndex: 0, calendarYear: 2026, ages: [], aliveCount: 2,
                    grossIncome: Money::fromPounds(30_000), totalTax: Money::zero(),
                    netIncome: Money::fromPounds(30_000), spendTarget: Money::fromPounds(25_000),
                    essentialSpend: Money::fromPounds(20_000), shortfallFunded: Money::zero(),
                    unmetSpend: Money::zero(), essentialsMet: true,
                    liquidWealth: Money::fromPounds(100_000), pensionWealth: Money::zero(),
                    propertyWealth: Money::zero(),
                    incomeBySource: ['state_pension' => Money::fromPounds(30_000)],
                ),
            ],
            true, true, null, Money::zero(), Money::zero(), 2026,
        );

        $charts = ResultPresenter::timeSeriesCharts($bare, nominal: true);

        $this->assertFalse($charts['nominalAvailable']);
        $this->assertFalse($charts['nominal']);
        $this->assertSame('real pounds', $charts['basisShort']);
        $this->assertSame(Money::fromPounds(25_000)->format(), $charts['costs']['rows'][0]['total']);
    }
}
