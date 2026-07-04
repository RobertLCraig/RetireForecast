<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\ResultPresenter;
use App\Models\Result;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use Tests\TestCase;

/**
 * The two over-time charts: the fan defaults to SPENDABLE money (excl. home) and flips to
 * total on the include-home toggle, and the strategy comparison is one median line PER
 * housing strategy over time (not a single terminal bar), so "which keeps the most usable
 * money over a long life" is legible. Hand-built results give full control over the two
 * bases so the toggle is shown to switch the data, not just the labels.
 */
final class ResultPresenterChartsTest extends TestCase
{
    public function test_the_fan_defaults_to_spendable_excl_home_and_the_toggle_flips_it_to_total(): void
    {
        $results = $this->threeStrategies();

        $excl = ResultPresenter::build($results, 'buy_outright', includeHome: false);
        $incl = ResultPresenter::build($results, 'buy_outright', includeHome: true);

        // Default basis is the spendable (excl-home) usable fan: the median row IS the
        // usable median, not the total — the data switches, not only the wording.
        $this->assertTrue($excl['fan']['usableBasis']);
        $this->assertStringContainsString('Spendable', $excl['fan']['basisLabel']);
        $this->assertSame(Money::fromPounds(400_000)->format(), $excl['fan']['rows'][0]['p50']);

        // Toggled on, the fan plots total wealth (incl. home) — a different, higher figure.
        $this->assertFalse($incl['fan']['usableBasis']);
        $this->assertStringContainsString('Total', $incl['fan']['basisLabel']);
        $this->assertSame(Money::fromPounds(520_000)->format(), $incl['fan']['rows'][0]['p50']);

        // The axis opts into the £ formatter and is anchored at zero so "do we hit £0?" reads honestly.
        $this->assertTrue($excl['fan']['options']['moneyAxis']);
        $this->assertSame(0, $excl['fan']['options']['yaxis']['min']);
        // A solvent plan never dips below zero, so the £0 floor stays.
        $this->assertFalse($excl['fan']['dipsNegative']);
    }

    public function test_the_fan_plots_the_net_position_below_zero_and_drops_the_axis_floor_when_the_money_runs_out(): void
    {
        // A plan whose spendable money runs out: the net-position fan carries a year gone
        // negative. The fan must plot THAT (not the £0-floored usable fan), flag the dip, drop
        // the axis floor so the shortfall depth shows, and its data table must match the chart.
        $result = $this->makeResult('stay_put', total: 200_000, usable: 100_000, depletion: 0.6, netFanMedians: [2026 => 60_000, 2027 => -40_000]);

        $built = ResultPresenter::build(collect(['stay_put' => $result]), 'stay_put', includeHome: false);

        $this->assertTrue($built['fan']['usableBasis']);
        $this->assertTrue($built['fan']['dipsNegative']);
        // The £0 floor is dropped when the series goes negative (so it isn't clipped at zero).
        $this->assertArrayNotHasKey('min', $built['fan']['options']['yaxis']);
        // The accessible table shows the NET position (2027's 10th percentile is below zero),
        // proving the chart plots the net-position fan, not the £0-floored usable fan.
        $this->assertSame(Money::fromPounds(-90_000)->format(), $built['fan']['rows'][1]['p10']);
    }

    public function test_the_comparison_is_one_median_line_per_strategy_over_time(): void
    {
        $results = $this->threeStrategies();

        $excl = ResultPresenter::build($results, 'buy_outright', includeHome: false)['comparison'];

        // One overlaid line per housing strategy (not a single terminal bar).
        $this->assertCount(3, $excl['options']['series']);
        $this->assertCount(3, $excl['strategies']);
        $this->assertSame(['stay_put', 'buy_outright', 'rent'], array_column($excl['strategies'], 'key'));

        // The accessible year x strategy table carries every plotted point, and the lines use
        // the USABLE median: stay-put's first year is its spendable figure, not its total.
        $this->assertNotEmpty($excl['lineRows']);
        $this->assertSame(Money::fromPounds(300_000)->format(), $excl['lineRows'][0]['cells']['stay_put']);

        // The per-strategy summary keeps the run-out stats a line can't show (so a high line
        // never hides a high risk), with the keys the PDF + CSV also read.
        $this->assertCount(3, $excl['rows']);
        foreach ($excl['rows'] as $row) {
            $this->assertArrayHasKey('successEssentials', $row);
            $this->assertArrayHasKey('depletionRate', $row);
            $this->assertArrayHasKey('medianTerminal', $row);
        }

        // Include-home flips the comparison basis too: stay-put's line becomes its total.
        $incl = ResultPresenter::build($results, 'buy_outright', includeHome: true)['comparison'];
        $this->assertFalse($incl['usableBasis']);
        $this->assertSame(Money::fromPounds(500_000)->format(), $incl['lineRows'][0]['cells']['stay_put']);
    }

    public function test_person_ages_label_the_axis_and_the_chart_tables(): void
    {
        // age = calendarYear - birthYear (the engine's own per-year definition): 2026 -> 68 / 66.
        $household = new Household('h', RegionProfile::EnglandWalesNi, [
            new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
            new Person('p2', new DateTimeImmutable('1960-09-01'), Sex::Male, EmploymentStatus::Retired),
        ], new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)));

        $built = ResultPresenter::build($this->threeStrategies(), 'buy_outright', includeHome: false, household: $household);

        $this->assertSame('68 / 66', $built['fan']['rows'][0]['ages']);
        $this->assertSame('68 / 66', $built['comparison']['lineRows'][0]['ages']);
        // The axis label map is attached for charts.js (year keys -> ages label).
        $this->assertSame('68 / 66', $built['fan']['options']['ageByYear'][2026]);
        $this->assertSame('69 / 67', $built['fan']['options']['ageByYear'][2027]);

        // Without a household, ages are absent and the axis stays year-only.
        $noAges = ResultPresenter::build($this->threeStrategies(), 'buy_outright');
        $this->assertNull($noAges['fan']['rows'][0]['ages']);
        $this->assertNull($noAges['fan']['options']['ageByYear']);
    }

    public function test_build_flags_when_a_run_predates_the_usable_fan(): void
    {
        // A fresh run carries the per-year usable fan -> the spendable view is available.
        $this->assertTrue(ResultPresenter::build($this->threeStrategies(), 'buy_outright')['usableFanAvailable']);

        // A run persisted before it -> flagged so the page can prompt a re-run instead of
        // silently drawing total wealth as spendable.
        $stale = collect([
            'stay_put' => $this->makeResult('stay_put', 500_000, 300_000, 0.04, usableFan: false),
            'buy_outright' => $this->makeResult('buy_outright', 520_000, 400_000, 0.01, usableFan: false),
            'rent' => $this->makeResult('rent', 540_000, 540_000, 0.55, usableFan: false),
        ]);
        $this->assertFalse(ResultPresenter::build($stale, 'buy_outright')['usableFanAvailable']);
    }

    public function test_the_burndown_continues_below_zero_by_the_cumulative_shortfall(): void
    {
        // Solvent in 2026 (usable £20k), then out of money in 2027 and 2028 (usable £0) with
        // £15k of spend unfunded each of those years. The burndown line tracks usable wealth
        // while solvent, then keeps falling below £0 by the ACCUMULATED shortfall (−£15k, −£30k)
        // — usable wealth alone floors at zero and can't show that depth.
        $forecast = new ForecastResult(
            [
                $this->year(0, 2026, liquid: 12_000, pension: 8_000, unmet: 0),
                $this->year(1, 2027, liquid: 0, pension: 0, unmet: 15_000),
                $this->year(2, 2028, liquid: 0, pension: 0, unmet: 15_000),
            ],
            false, false, 2027, Money::zero(), Money::zero(), 2028,
        );

        $burndown = ResultPresenter::burndown([['name' => 'Plan', 'forecast' => $forecast]]);

        $this->assertTrue($burndown['dipsNegative']);
        $cells = $burndown['rows'][0]['cells'];
        $this->assertSame(Money::fromPounds(20_000)->format(), $cells[2026]);   // usable wealth, still solvent
        $this->assertSame(Money::fromPounds(-15_000)->format(), $cells[2027]);  // £0 − £15k cumulative shortfall
        $this->assertSame(Money::fromPounds(-30_000)->format(), $cells[2028]);  // £0 − £30k cumulative shortfall
    }

    public function test_the_burndown_shades_below_zero_only_when_a_plan_runs_out(): void
    {
        // A plan that runs out gets a light-red y-axis region from £0 down, so the shortfall
        // territory reads at a glance. The band anchors at y = 0 and drops to a sentinel floor
        // ApexCharts clamps to the bottom of the plot, whatever the auto axis minimum.
        $runsOut = new ForecastResult(
            [
                $this->year(0, 2026, liquid: 12_000, pension: 8_000, unmet: 0),
                $this->year(1, 2027, liquid: 0, pension: 0, unmet: 15_000),
            ],
            false, false, 2027, Money::zero(), Money::zero(), 2027,
        );
        $band = ResultPresenter::burndown([['name' => 'Plan', 'forecast' => $runsOut]])['options']['annotations']['yaxis'];
        $this->assertCount(1, $band);
        $this->assertSame(0, $band[0]['y']);
        $this->assertLessThan(0, $band[0]['y2']);          // fills below the zero line
        $this->assertSame('#ef4444', $band[0]['fillColor']);

        // A plan that stays solvent throughout has no below-zero region to shade.
        $solvent = new ForecastResult(
            [
                $this->year(0, 2026, liquid: 12_000, pension: 8_000, unmet: 0),
                $this->year(1, 2027, liquid: 10_000, pension: 8_000, unmet: 0),
            ],
            false, false, null, Money::zero(), Money::zero(), 2027,
        );
        $options = ResultPresenter::burndown([['name' => 'Plan', 'forecast' => $solvent]])['options'];
        $this->assertArrayNotHasKey('annotations', $options);
    }

    private function year(int $index, int $calendarYear, int $liquid, int $pension, int $unmet): YearResult
    {
        $liquidM = Money::fromPounds($liquid);
        $pensionM = Money::fromPounds($pension);

        return new YearResult(
            yearIndex: $index,
            calendarYear: $calendarYear,
            ages: [],
            aliveCount: 2,
            grossIncome: Money::zero(),
            totalTax: Money::zero(),
            netIncome: Money::zero(),
            spendTarget: Money::zero(),
            essentialSpend: Money::zero(),
            shortfallFunded: Money::zero(),
            unmetSpend: Money::fromPounds($unmet),
            essentialsMet: $unmet === 0,
            liquidWealth: $liquidM,
            pensionWealth: $pensionM,
            propertyWealth: Money::zero(),
            totalWealth: $liquidM->plus($pensionM),
        );
    }

    /**
     * Three strategies with deliberately distinct total vs usable medians so the toggle and
     * the per-strategy lines are unambiguous. Keyed by variant value, as build() expects.
     *
     * @return Collection<string, Result>
     */
    private function threeStrategies(): Collection
    {
        return collect([
            // [total first-year median, usable first-year median] in whole pounds.
            'stay_put' => $this->makeResult('stay_put', total: 500_000, usable: 300_000, depletion: 0.04),
            'buy_outright' => $this->makeResult('buy_outright', total: 520_000, usable: 400_000, depletion: 0.01),
            'rent' => $this->makeResult('rent', total: 540_000, usable: 540_000, depletion: 0.55),
        ]);
    }

    /**
     * @param  array<int, int>|null  $netFanMedians  calendarYear => net-position median (pounds);
     *                                               pass a negative year to model running out
     */
    private function makeResult(string $variant, int $total, int $usable, float $depletion, bool $usableFan = true, ?array $netFanMedians = null): Result
    {
        $sim = new SimulationResult(
            nPaths: 100,
            seed: 1,
            successProbabilityEssentials: 1.0 - $depletion,
            successProbabilityFullSpend: 0.8,
            depletionRate: $depletion,
            medianDepletionYear: null,
            terminalWealthPercentiles: $this->band($total),
            fanChart: $this->fan([2026 => $total, 2027 => $total - 10_000]),
            usableWealthPercentiles: $this->band($usable),
            // A run from before the per-year usable fan landed has none.
            usableFanChart: $usableFan ? $this->fan([2026 => $usable, 2027 => $usable - 10_000]) : [],
            // The net-position fan (continues below £0 by the shortfall); absent unless supplied.
            netPositionFanChart: $netFanMedians === null ? [] : $this->fan($netFanMedians),
        );

        return (new Result(['variant' => $variant]))->setSimulationResult($sim);
    }

    /**
     * A percentile band around a median (whole pounds).
     *
     * @return array{p10: Money, p25: Money, p50: Money, p75: Money, p90: Money}
     */
    private function band(int $p50): array
    {
        return [
            'p10' => Money::fromPounds($p50 - 50_000),
            'p25' => Money::fromPounds($p50 - 20_000),
            'p50' => Money::fromPounds($p50),
            'p75' => Money::fromPounds($p50 + 20_000),
            'p90' => Money::fromPounds($p50 + 50_000),
        ];
    }

    /**
     * A per-year fan from a map of calendarYear => median pounds.
     *
     * @param  array<int, int>  $medians
     * @return list<array<string, mixed>>
     */
    private function fan(array $medians): array
    {
        $bands = [];
        foreach ($medians as $year => $p50) {
            $bands[] = ['calendarYear' => $year, 'paths' => 100, ...$this->band($p50)];
        }

        return $bands;
    }
}
