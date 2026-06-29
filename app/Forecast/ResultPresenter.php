<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Enums\ScenarioVariant;
use App\Import\MoneyText;
use App\Models\Result;
use Illuminate\Support\Collection;
use RetireForecast\FinanceEngine\Benchmark\RetirementLivingStandards;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * Turns a run's three variant {@see SimulationResult}s into everything the results
 * view shows: headline figures as text, the fan-chart options + its data-table rows,
 * and the buy-vs-rent comparison. The chart options are a progressive enhancement;
 * the table rows and headline text are the accessible source of truth, so every
 * number a chart plots is also produced here as text (WCAG 2.1 AA).
 *
 * No recommendation is ever formed here: figures are presented per variant, never
 * ranked or framed as "better".
 */
final class ResultPresenter
{
    private const LABELS = [
        'stay_put' => 'Stay put',
        'buy_outright' => 'Sell & buy cheaper',
        'rent' => 'Sell & rent',
    ];

    /** Fixed display order so the comparison reads the same every time. */
    private const ORDER = ['stay_put', 'buy_outright', 'rent'];

    /** Human labels for the cashflow ladder's income sources (YearResult::INCOME_SOURCES). */
    private const SOURCE_LABELS = [
        'salary' => 'Salary',
        'defined_benefit' => 'DB pension',
        'state_pension' => 'State Pension',
        'other_taxable' => 'Annuity / other',
        'investment_income' => 'Investment income',
        'tax_free_income' => 'Tax-free income',
        'pension_lump_sum' => 'Pension tax-free cash',
        'pension_drawdown' => 'Pension drawdown',
        'asset_drawdown' => 'Savings drawn',
    ];

    /**
     * The income sources that count as a secure floor: income that lasts for life and
     * does not depend on a pot lasting or on investment returns — guaranteed pensions
     * (DB, State Pension), purchased annuities, and any tax-free income (e.g. DLA, which
     * must NOT be dropped — see the completeness rule). Salary is excluded (it is earned
     * and stops at retirement); pension lump sums and drawdown, and savings drawn, are
     * excluded (they deplete the pot).
     */
    private const SECURE_SOURCES = ['defined_benefit', 'state_pension', 'other_taxable', 'tax_free_income'];

    /** The 3-tier budget categories, in display order, with their labels. */
    private const EXPENSE_TIERS = [
        'essential' => 'Essential',
        'discretionary' => 'Discretionary',
        'self_investment' => 'Self-investment',
    ];

    /**
     * @param  Collection<string, Result>  $resultsByVariant  keyed by variant value
     * @param  bool  $includeHome  false (default) plots USABLE wealth (excl. the home) —
     *                             the spendable money that actually runs out; true plots
     *                             TOTAL wealth (incl. the home). The home is an illiquid
     *                             floor that props total up without paying any bills, so
     *                             excl-home is the honest "will it last" view for a couple
     *                             not planning to sell again. The headline cards always
     *                             show both figures as text regardless of this toggle.
     * @param  Household|null  $household  when given, the charts label the calendar-year axis
     *                                     and tables with the people's ages (age = year -
     *                                     birthYear, the engine's own definition).
     * @return array<string, mixed>
     */
    public static function build(Collection $resultsByVariant, string $primaryVariant, bool $includeHome = false, ?Household $household = null): array
    {
        $variants = [];
        foreach (self::ORDER as $key) {
            $result = $resultsByVariant->get($key);
            if ($result instanceof Result) {
                $variants[$key] = self::headline($key, $result->simulationResult());
            }
        }

        $primary = array_key_exists($primaryVariant, $variants) ? $primaryVariant : array_key_first($variants);
        $primarySim = $resultsByVariant->get($primary)->simulationResult();

        // Ages by calendar year for the axis + tables (empty if no household passed).
        $ageByYear = $household !== null
            ? self::agesByYear($household, array_column($primarySim->fanChart, 'calendarYear'))
            : [];

        return [
            'variants' => $variants,
            'primary' => $primary,
            'includeHome' => $includeHome,
            // False for a run computed before the per-year usable fan existed: the spendable
            // (excl-home) view then falls back to total, so the page prompts a re-run rather
            // than silently showing total wealth as if it were spendable money (no silent failure).
            'usableFanAvailable' => $primarySim->usableFanChart !== [],
            'fan' => self::fan($primary, $primarySim, $includeHome, $ageByYear),
            'comparison' => self::comparison($resultsByVariant, $includeHome, $ageByYear),
        ];
    }

    /** @return array<string, mixed> */
    private static function headline(string $variant, SimulationResult $r): array
    {
        return [
            'key' => $variant,
            'label' => self::LABELS[$variant],
            'successEssentials' => self::formatPercent($r->successProbabilityEssentials),
            'successFullSpend' => self::formatPercent($r->successProbabilityFullSpend),
            'depletionRate' => self::formatPercent($r->depletionRate),
            'medianDepletionYear' => $r->medianDepletionYear ?? null,
            // A plain-English verdict that drives the risk home. Factual (anchored to the
            // simulated futures), never a recommendation, so it stays on the guidance side.
            'verdict' => self::runOutVerdict($r->depletionRate),
            'terminalP10' => $r->terminalWealthPercentiles['p10']->format(),
            'terminalP50' => $r->terminalWealthPercentiles['p50']->format(),
            'terminalP90' => $r->terminalWealthPercentiles['p90']->format(),
            // Usable wealth excludes the home, so an asset-rich household that runs out of
            // spendable cash does not read as the "wealthiest" outcome (gotcha P).
            'usableP50' => self::usableMedian($r),
        ];
    }

    /** Median terminal usable wealth (excl. home), or null for a run predating the field. */
    private static function usableMedian(SimulationResult $r): ?string
    {
        return isset($r->usableWealthPercentiles['p50']) ? $r->usableWealthPercentiles['p50']->format() : null;
    }

    /**
     * A plain-English verdict on the depletion (run-short) risk, scaling from "lasts in
     * every future" to "you'd very likely run out of money". It is deliberately blunt where
     * the risk is high — but it is a FACTUAL statement about the simulated futures, anchored
     * with "on these figures", never a recommendation to act, so it stays guidance-side and
     * clears the banned-phrasing lint. `level` drives the colour the panel gives it.
     *
     * @return array{level: string, text: string}
     */
    private static function runOutVerdict(float $depletionRate): array
    {
        $pct = self::formatPercent($depletionRate);

        return match (true) {
            $depletionRate <= 0.0 => ['level' => 'none', 'text' => 'On these figures, the money lasts to the end in every simulated future.'],
            $depletionRate < 0.2 => ['level' => 'low', 'text' => "On these figures, the money lasts in the large majority of futures — it runs short in {$pct} of them."],
            $depletionRate < 0.5 => ['level' => 'medium', 'text' => "On these figures, there's a real risk the money runs short — it does in {$pct} of simulated futures."],
            $depletionRate < 0.8 => ['level' => 'high', 'text' => "On these figures, you'd more likely than not run out of money before the end — it runs short in {$pct} of simulated futures."],
            default => ['level' => 'high', 'text' => "On these figures, you'd very likely run out of money before the end — it runs short in {$pct} of simulated futures."],
        };
    }

    /**
     * The fan chart for one variant: 10–90 and 25–75 percentile bands plus the median
     * line, with a fully populated table of the same figures.
     *
     * @return array<string, mixed>
     */
    private static function fan(string $variant, SimulationResult $r, bool $includeHome, array $ageByYear = []): array
    {
        [$series, $usableBasis] = self::fanSeries($r, $includeHome);

        $band = fn (string $lo, string $hi): array => array_map(
            fn (array $y): array => ['x' => $y['calendarYear'], 'y' => [self::pounds($y[$lo]), self::pounds($y[$hi])]],
            $series,
        );
        $line = array_map(
            fn (array $y): array => ['x' => $y['calendarYear'], 'y' => self::pounds($y['p50'])],
            $series,
        );

        $rows = array_map(fn (array $y): array => [
            'year' => $y['calendarYear'],
            'ages' => $ageByYear[$y['calendarYear']] ?? null,
            'p10' => $y['p10']->format(),
            'p25' => $y['p25']->format(),
            'p50' => $y['p50']->format(),
            'p75' => $y['p75']->format(),
            'p90' => $y['p90']->format(),
        ], $series);

        $basisLabel = $usableBasis ? 'Spendable money, excl. home' : 'Total wealth, incl. home';

        $options = [
            'chart' => ['type' => 'rangeArea', 'height' => 380, 'toolbar' => ['show' => false]],
            'colors' => ['#93c5fd', '#3b82f6', '#1e3a8a'],
            'series' => [
                ['name' => '10th–90th percentile', 'type' => 'rangeArea', 'data' => $band('p10', 'p90')],
                ['name' => '25th–75th percentile', 'type' => 'rangeArea', 'data' => $band('p25', 'p75')],
                ['name' => 'Median (50th)', 'type' => 'line', 'data' => $line],
            ],
            'fill' => ['opacity' => [0.25, 0.4, 1]],
            'stroke' => ['curve' => 'straight', 'width' => [0, 0, 3], 'dashArray' => [0, 0, 0]],
            'dataLabels' => ['enabled' => false],
            'markers' => ['size' => 0],
            // moneyAxis: charts.js attaches a £-abbreviating axis/tooltip formatter (a JS
            // function can't travel through JSON). Anchoring the axis at 0 keeps "do we hit
            // zero?" honest; forceNiceScale stops the big upside tail from squashing the
            // body of the data into a flat-looking band near the bottom (the old complaint).
            'moneyAxis' => true,
            // ageByYear: charts.js turns the calendar-year axis into a two-line label (year +
            // the people's ages that year). A plain map (year keys -> "82 / 84"), not an
            // ApexCharts option; year keys aren't zero-sequential so @js encodes it as an object.
            'ageByYear' => $ageByYear === [] ? null : $ageByYear,
            'xaxis' => ['type' => 'numeric', 'tickAmount' => 8, 'decimalsInFloat' => 0, 'title' => ['text' => 'Calendar year']],
            'yaxis' => ['min' => 0, 'forceNiceScale' => true, 'title' => ['text' => $basisLabel.' (real £)']],
            'legend' => ['position' => 'top'],
        ];

        return [
            'variant' => $variant,
            'label' => self::LABELS[$variant],
            'usableBasis' => $usableBasis,
            'basisLabel' => $basisLabel,
            'options' => $options,
            'rows' => $rows,
        ];
    }

    /**
     * The per-year band to plot: USABLE (excl. home) by default, TOTAL (incl. home) when
     * includeHome. Falls back to the total fan for a run persisted before the per-year
     * usable fan landed (its usableFanChart is empty), so an old stored run still draws.
     *
     * @return array{0: list<array<string, mixed>>, 1: bool} [bands, isUsableBasis]
     */
    private static function fanSeries(SimulationResult $r, bool $includeHome): array
    {
        if (! $includeHome && $r->usableFanChart !== []) {
            return [$r->usableFanChart, true];
        }

        return [$r->fanChart, false];
    }

    /** A distinct line colour per housing strategy, so each reads the same across the app. */
    private const VARIANT_COLOURS = [
        'stay_put' => '#6b7280',      // slate
        'buy_outright' => '#2563eb',  // blue
        'rent' => '#d97706',          // amber
    ];

    /**
     * How the three housing strategies compare OVER TIME: each variant's MEDIAN spendable
     * money (excl. home, or total when includeHome) by calendar year, overlaid as one line
     * each, so you can read which strategy keeps the most usable money as the household ages
     * and where each trajectory trends toward zero. The earlier terminal-wealth bar chart
     * hid exactly this: it dropped the time dimension and, counting the home, made the
     * options look near-identical even when the spendable paths diverge sharply.
     *
     * A high median line is not the whole story (a future can run short along the way and
     * recover), so the per-strategy run-out stats stay in `rows` beside the chart — a high
     * line never hides a high risk. Late years thin out as fewer simulated futures still
     * have both partners alive; `paths` is carried per point for that caveat.
     *
     * @param  Collection<string, Result>  $resultsByVariant
     * @return array{options: array<string, mixed>, rows: list<array<string, mixed>>, years: list<int>, lineRows: list<array{year: int, cells: array<string, ?string>}>, strategies: list<array{key: string, label: string}>, usableBasis: bool, basisLabel: string}
     */
    private static function comparison(Collection $resultsByVariant, bool $includeHome, array $ageByYear = []): array
    {
        $rows = [];
        $yearsSet = [];
        $byVariant = []; // key => [calendarYear => ['pounds' => int, 'text' => string]]
        $usableBasis = true;

        foreach (self::ORDER as $key) {
            $result = $resultsByVariant->get($key);
            if (! $result instanceof Result) {
                continue;
            }
            $r = $result->simulationResult();
            [$fan, $isUsable] = self::fanSeries($r, $includeHome);
            $usableBasis = $usableBasis && $isUsable;

            $median = [];
            foreach ($fan as $band) {
                $year = $band['calendarYear'];
                $yearsSet[$year] = true;
                $median[$year] = ['pounds' => self::pounds($band['p50']), 'text' => $band['p50']->format()];
            }
            $byVariant[$key] = $median;

            $rows[] = [
                'label' => self::LABELS[$key],
                'successEssentials' => self::formatPercent($r->successProbabilityEssentials),
                'successFullSpend' => self::formatPercent($r->successProbabilityFullSpend),
                'depletionRate' => self::formatPercent($r->depletionRate),
                // Reaches the comparison table, CSV and PDF, not only the headline cards.
                'medianDepletionYear' => $r->medianDepletionYear ?? null,
                'medianUsable' => self::usableMedian($r),
                'medianTerminal' => $r->terminalWealthPercentiles['p50']->format(),
            ];
        }

        $years = array_keys($yearsSet);
        sort($years);

        // One overlaid line per strategy (chart) + the matching year x strategy table
        // (the accessible source of truth — every point the chart plots is also text here).
        $series = [];
        $colours = [];
        $strategies = [];
        $lineRows = [];
        foreach (array_keys($byVariant) as $key) {
            $strategies[] = ['key' => $key, 'label' => self::LABELS[$key]];
            $colours[] = self::VARIANT_COLOURS[$key];
            $series[] = [
                'name' => self::LABELS[$key],
                'data' => array_map(
                    fn (int $year): array => ['x' => $year, 'y' => $byVariant[$key][$year]['pounds'] ?? null],
                    $years,
                ),
            ];
        }
        foreach ($years as $year) {
            $cells = [];
            foreach (array_keys($byVariant) as $key) {
                $cells[$key] = $byVariant[$key][$year]['text'] ?? null;
            }
            $lineRows[] = ['year' => $year, 'ages' => $ageByYear[$year] ?? null, 'cells' => $cells];
        }

        $basisLabel = $usableBasis ? 'Median spendable money, excl. home' : 'Median total wealth, incl. home';

        $options = [
            'chart' => ['type' => 'line', 'height' => 360, 'toolbar' => ['show' => false]],
            'colors' => $colours,
            'series' => $series,
            'stroke' => ['curve' => 'straight', 'width' => 3],
            'dataLabels' => ['enabled' => false],
            'markers' => ['size' => 0],
            'moneyAxis' => true,
            'ageByYear' => $ageByYear === [] ? null : $ageByYear,
            'xaxis' => ['type' => 'numeric', 'tickAmount' => 8, 'decimalsInFloat' => 0, 'title' => ['text' => 'Calendar year']],
            'yaxis' => ['min' => 0, 'forceNiceScale' => true, 'title' => ['text' => $basisLabel.' (real £)']],
            'legend' => ['position' => 'top'],
        ];

        return [
            'options' => $options,
            'rows' => $rows,
            'years' => $years,
            'lineRows' => $lineRows,
            'strategies' => $strategies,
            'usableBasis' => $usableBasis,
            'basisLabel' => $basisLabel,
        ];
    }

    /**
     * Wealth-over-time "burndown" for a set of plans (a base + its delta-child what-ifs),
     * each plotted as one line and overlaid so the trajectories read against each other.
     * Plots USABLE wealth (excl. home) — the spendable money that actually burns down and
     * hits zero if it runs out, the honest "will it last" measure (gotcha P); the home, being
     * illiquid, is excluded. Usable is `liquidWealth + pensionWealth`, the SAME definition the
     * cashflow ladder uses, so the two can't drift.
     *
     * Returns the ApexCharts line options plus a year × plan table (the accessible source of
     * truth — every line the chart draws is also a column here). Plans can end in different
     * years (different death ages), so a plan that has ended shows a null/blank cell.
     *
     * @param  list<array{name: string, forecast: ForecastResult}>  $plans
     * @return array{options: array<string, mixed>, years: list<int>, rows: list<array{name: string, cells: array<int, ?string>}>}
     */
    public static function burndown(array $plans): array
    {
        // Union of calendar years across the plans, ascending.
        $yearsSet = [];
        foreach ($plans as $plan) {
            foreach ($plan['forecast']->years as $year) {
                $yearsSet[$year->calendarYear] = true;
            }
        }
        $years = array_keys($yearsSet);
        sort($years);

        $series = [];
        $rows = [];
        foreach ($plans as $plan) {
            $usableByYear = [];
            foreach ($plan['forecast']->years as $year) {
                $usableByYear[$year->calendarYear] = $year->liquidWealth->plus($year->pensionWealth);
            }

            $data = [];
            $cells = [];
            foreach ($years as $calendarYear) {
                $usable = $usableByYear[$calendarYear] ?? null;
                $data[] = ['x' => $calendarYear, 'y' => $usable !== null ? self::pounds($usable) : null];
                $cells[$calendarYear] = $usable?->format();
            }

            $series[] = ['name' => $plan['name'], 'data' => $data];
            $rows[] = ['name' => $plan['name'], 'cells' => $cells];
        }

        $options = [
            'chart' => ['type' => 'line', 'height' => 360, 'toolbar' => ['show' => false]],
            'series' => $series,
            'stroke' => ['curve' => 'straight', 'width' => 2],
            'dataLabels' => ['enabled' => false],
            'markers' => ['size' => 0],
            'xaxis' => ['type' => 'numeric', 'tickAmount' => 8, 'decimalsInFloat' => 0, 'title' => ['text' => 'Calendar year']],
            'yaxis' => ['title' => ['text' => 'Usable wealth, excl. home (real £)']],
            'legend' => ['position' => 'top'],
        ];

        return ['options' => $options, 'years' => $years, 'rows' => $rows];
    }

    /**
     * The deterministic central-projection cashflow ladder: per year, income split by
     * source, then tax, spend, and the usable / total wealth carried forward. Only the
     * income sources that actually occur are kept as columns. This is the year-by-year
     * walk-through the sector leads with, and the visual guard that every income source
     * reaches the forecast (no silent drop, gotcha Q).
     *
     * @return array{sources: list<string>, sourceLabels: array<string, string>, rows: list<array<string, mixed>>, finalYear: int}
     */
    public static function ladder(ForecastResult $forecast): array
    {
        // Drop columns for sources that never occur, so the table stays readable.
        $active = array_values(array_filter(
            YearResult::INCOME_SOURCES,
            fn (string $source): bool => self::sourceOccurs($forecast, $source),
        ));

        $rows = [];
        foreach ($forecast->years as $year) {
            $income = [];
            foreach ($active as $source) {
                $income[$source] = ($year->incomeBySource[$source] ?? Money::zero())->format();
            }
            $rows[] = [
                'year' => $year->calendarYear,
                'ages' => implode(' / ', $year->ages),
                'income' => $income,
                'tax' => $year->totalTax->format(),
                'spend' => $year->spendTarget->format(),
                'shortfall' => $year->unmetSpend->isZero() ? null : $year->unmetSpend->format(),
                'usableWealth' => $year->liquidWealth->plus($year->pensionWealth)->format(),
                'totalWealth' => $year->totalWealth->format(),
            ];
        }

        return [
            'sources' => $active,
            'sourceLabels' => self::SOURCE_LABELS,
            'rows' => $rows,
            'finalYear' => $forecast->finalCalendarYear,
        ];
    }

    private static function sourceOccurs(ForecastResult $forecast, string $source): bool
    {
        foreach ($forecast->years as $year) {
            $money = $year->incomeBySource[$source] ?? null;
            if ($money instanceof Money && $money->isPositive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The income-floor readout: essential spending vs secure (guaranteed-for-life,
     * non-pot) income, taken at the last year everyone is still alive — by then every
     * guaranteed source that ever starts is in payment and any salary has ended, so it
     * is the household's mature floor. Reports the coverage factually (a percentage and
     * the surplus or gap); it never says whether that is enough (no recommendation).
     *
     * Returns null when the projection has no years to read.
     *
     * @return array{year: int, ages: string, essentialSpend: string, secureIncome: string, sources: list<array{label: string, amount: string}>, coveragePct: int, surplus: ?string, gap: ?string, fullyCovered: bool}|null
     */
    public static function incomeFloor(ForecastResult $forecast): ?array
    {
        $snapshot = self::matureSnapshot($forecast);
        if ($snapshot === null) {
            return null;
        }

        $sources = [];
        $secure = Money::zero();
        foreach (self::SECURE_SOURCES as $source) {
            $money = $snapshot->incomeBySource[$source] ?? Money::zero();
            if ($money->isPositive()) {
                $sources[] = ['label' => self::SOURCE_LABELS[$source], 'amount' => $money->format()];
                $secure = $secure->plus($money);
            }
        }

        $essential = $snapshot->essentialSpend;
        $shortfall = $essential->minus($secure);
        $surplus = $secure->minus($essential);
        $coverage = $essential->isPositive() ? (int) round($secure->pence / $essential->pence * 100) : 100;

        return [
            'year' => $snapshot->calendarYear,
            'ages' => implode(' / ', $snapshot->ages),
            'essentialSpend' => $essential->format(),
            'secureIncome' => $secure->format(),
            'sources' => $sources,
            'coveragePct' => $coverage,
            'surplus' => $surplus->isPositive() ? $surplus->format() : null,
            'gap' => $shortfall->isPositive() ? $shortfall->format() : null,
            'fullyCovered' => ! $shortfall->isPositive(),
        ];
    }

    /** The last projected year in which every person is still alive (the mature floor point). */
    private static function matureSnapshot(ForecastResult $forecast): ?YearResult
    {
        $snapshot = null;
        foreach ($forecast->years as $year) {
            $everyoneAlive = $year->aliveCount === count($year->ages);
            if ($everyoneAlive) {
                $snapshot = $year;
            }
        }

        // Fall back to the final year if a death falls in the very first year (degenerate),
        // so the readout still shows rather than silently vanishing.
        return $snapshot ?? ($forecast->years[array_key_last($forecast->years)] ?? null);
    }

    /**
     * The PLSA Retirement Living Standards benchmark: where the household's annual
     * spending lands against the recognised Minimum / Moderate / Comfortable yardsticks
     * for its composition (single vs couple). A factual orientation — which standard the
     * spend reaches — never a judgement that it is too low or high, and never a
     * recommendation.
     *
     * The spend compared is put on the PLSA basis (see {@see RetirementLivingStandards}):
     * it EXCLUDES rent and mortgage (PLSA assumes the home is owned outright — rent lives
     * in the housing action, not the household, so it is excluded automatically) and
     * INCLUDES home running costs (energy, council tax, maintenance), which PLSA also
     * includes. So comparable spend = the household's lifestyle spend
     * (`expenseProfile->targetAnnualSpend()` — essential + discretionary, already excluding
     * saved self-investment) plus any owned-home running costs. This reuses the very
     * `ExpenseProfile` the forecast runs on, so the benchmarked figure cannot drift from
     * the projection. London is not modelled as a region, so the (lower) outside-London
     * figures are used and the higher London cut is flagged in the readout.
     *
     * Returns null when there is no spend to benchmark.
     *
     * @return array{comparableSpend: string, couple: bool, composition: string, runningCostsIncluded: bool, tiers: list<array{key: string, label: string, amount: string, met: bool}>, tierReached: ?string, tierReachedLabel: ?string, belowMinimum: bool, nextTier: ?string, nextTierLabel: ?string, gapToNext: ?string, source: string, edition: string, verifiedOn: string}|null
     */
    public static function plsaBenchmark(Household $household): ?array
    {
        $spend = $household->expenseProfile->targetAnnualSpend();
        $runningCosts = $household->primaryResidence?->runningCosts;
        if ($runningCosts !== null) {
            $spend = $spend->plus($runningCosts);
        }

        if (! $spend->isPositive()) {
            return null;
        }

        $couple = count($household->persons) >= 2;
        // London is not a modelled region; use the general (outside-London) figures and
        // surface the higher-London caveat in the view.
        $result = RetirementLivingStandards::classify($spend, $couple, london: false);

        $tiers = [];
        foreach (RetirementLivingStandards::TIERS as $tier) {
            $tiers[] = [
                'key' => $tier,
                'label' => RetirementLivingStandards::TIER_LABELS[$tier],
                'amount' => $result->tier($tier)->format(),
                'met' => $result->meets($tier),
            ];
        }

        $next = $result->nextTier();
        $gap = $result->gapToNextTier();

        return [
            'comparableSpend' => $spend->format(),
            'couple' => $couple,
            'composition' => $couple ? 'couple' : 'single person',
            'runningCostsIncluded' => $runningCosts !== null && $runningCosts->isPositive(),
            'tiers' => $tiers,
            'tierReached' => $result->tierReached,
            'tierReachedLabel' => $result->tierReached !== null ? RetirementLivingStandards::TIER_LABELS[$result->tierReached] : null,
            'belowMinimum' => $result->belowMinimum(),
            'nextTier' => $next,
            'nextTierLabel' => $next !== null ? RetirementLivingStandards::TIER_LABELS[$next] : null,
            'gapToNext' => $gap !== null ? $gap->format() : null,
            'source' => RetirementLivingStandards::SOURCE,
            'edition' => RetirementLivingStandards::EDITION,
            'verifiedOn' => RetirementLivingStandards::VERIFIED_ON,
        ];
    }

    /**
     * The 3-tier line-item budget echoed back from the builder form-state: the user's
     * spending grouped into essential / discretionary / self-investment with per-line
     * detail and tier subtotals, plus the split between what is spent (counts as spend
     * in the forecast) and what is saved (self-investment flagged to build net worth).
     *
     * Reads the same `expenseLines` the {@see HouseholdAssembler} derives the engine
     * totals from, so the displayed subtotals reconcile to the forecast's spend (the
     * data-integrity invariant — asserted in ExpenseBreakdownReconciliationTest). A
     * scenario predating line items (none present) falls back to its flat
     * essential/discretionary totals, mirroring the assembler's own fallback.
     *
     * @param  array<string, mixed>  $state  the effective builder form-state
     * @return array{tiers: list<array{key: string, label: string, lines: list<array{label: string, amount: string, saved: bool}>, subtotal: string}>, spendingTotal: string, savingTotal: string, total: string, hasSaving: bool}
     */
    public static function expenseBreakdown(array $state): array
    {
        $lines = $state['expenseLines'] ?? [];
        if ($lines === []) {
            $lines = self::flatFallbackLines($state['expense'] ?? []);
        }

        $tiers = [];
        $spending = Money::zero();
        $saving = Money::zero();
        foreach (self::EXPENSE_TIERS as $key => $label) {
            $tierLines = [];
            $subtotal = Money::zero();
            foreach ($lines as $line) {
                if (($line['category'] ?? '') !== $key) {
                    continue;
                }
                $amount = Money::fromPence(MoneyText::toPence((string) ($line['amount'] ?? '0')));
                $saved = $key === 'self_investment' && (bool) ($line['savedAsAsset'] ?? false);
                $tierLines[] = [
                    'label' => (string) ($line['label'] ?? ''),
                    'amount' => $amount->format(),
                    'saved' => $saved,
                ];
                $subtotal = $subtotal->plus($amount);
                // Saved self-investment builds net worth (a contribution), not spend; all
                // else is spend. One home per pound — exactly mirrors the assembler.
                $saved ? $saving = $saving->plus($amount) : $spending = $spending->plus($amount);
            }
            if ($tierLines !== []) {
                $tiers[] = ['key' => $key, 'label' => $label, 'lines' => $tierLines, 'subtotal' => $subtotal->format()];
            }
        }

        return [
            'tiers' => $tiers,
            'spendingTotal' => $spending->format(),
            'savingTotal' => $saving->format(),
            'total' => $spending->plus($saving)->format(),
            'hasSaving' => $saving->isPositive(),
        ];
    }

    /**
     * Synthesise line items from the legacy flat totals so a pre-C1 scenario still shows
     * a breakdown. Mirrors {@see HouseholdAssembler::essentialAndDiscretionary()}'s
     * fallback exactly (essential required, discretionary optional), so the displayed
     * figures still reconcile to the forecast's spend.
     *
     * @param  array<string, mixed>  $expense
     * @return list<array{label: string, amount: string, category: string, savedAsAsset: bool}>
     */
    private static function flatFallbackLines(array $expense): array
    {
        $lines = [['label' => 'Essential spending', 'amount' => (string) ($expense['essential'] ?? '0'), 'category' => 'essential', 'savedAsAsset' => false]];
        if (($expense['discretionary'] ?? '') !== '') {
            $lines[] = ['label' => 'Discretionary spending', 'amount' => (string) $expense['discretionary'], 'category' => 'discretionary', 'savedAsAsset' => false];
        }

        return $lines;
    }

    /** Whole pounds (chart axes do not need pence and floats stay out of money maths). */
    private static function pounds(Money $money): int
    {
        return intdiv($money->pence, 100);
    }

    /**
     * Each person's age in each calendar year, e.g. `2040 => "82 / 84"`, in household order.
     * Age = calendarYear - birthYear, which is exactly the engine's own per-year age
     * (`YearResult::ages` = baseAge + yearIndex = (baseYear - birthYear) + yearIndex), so the
     * chart axis + tables read the same age the cashflow ladder does (one definition; the
     * reconciliation is asserted in a test). Ages are derived from DOB, never stored.
     *
     * @param  list<int>  $years
     * @return array<int, string>
     */
    private static function agesByYear(Household $household, array $years): array
    {
        $birthYears = array_map(
            fn (Person $p): int => (int) $p->dob->format('Y'),
            $household->persons,
        );

        $map = [];
        foreach ($years as $year) {
            $map[$year] = implode(' / ', array_map(
                fn (int $birthYear): string => (string) ($year - $birthYear),
                $birthYears,
            ));
        }

        return $map;
    }

    /**
     * One definition for a probability shown as a percentage — shared by the headline
     * cards, the comparison table, the CSV exports and the walled-off interpretation, so
     * the same figure can never be formatted two different ways on two surfaces
     * (data-integrity: one figure, one home).
     */
    public static function formatPercent(float $fraction): string
    {
        return round($fraction * 100).'%';
    }

    public static function variantLabel(ScenarioVariant $variant): string
    {
        return self::LABELS[$variant->value];
    }
}
