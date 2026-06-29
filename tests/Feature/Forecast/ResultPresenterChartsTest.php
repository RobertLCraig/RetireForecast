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

    private function makeResult(string $variant, int $total, int $usable, float $depletion, bool $usableFan = true): Result
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
