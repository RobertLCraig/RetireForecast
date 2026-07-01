<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Enums\ScenarioVariant;
use App\Import\MoneyText;
use App\Models\Result;
use App\Models\Scenario;
use Illuminate\Support\Collection;
use RetireForecast\FinanceEngine\Benchmark\RetirementLivingStandards;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\HistoricalBacktestOutcome;
use RetireForecast\FinanceEngine\Forecast\HistoricalBacktestResult;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Housing\HousingProceeds;
use RetireForecast\FinanceEngine\Housing\HousingPurchase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\CareImpact;
use RetireForecast\FinanceEngine\MonteCarlo\LongevityDistribution;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\StatePension\StatePensionAge;

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
        'means_tested_benefit' => 'Pension Credit',
        'pension_lump_sum' => 'Pension tax-free cash',
        'pension_drawdown' => 'Pension drawdown',
        'asset_drawdown' => 'Savings drawn',
    ];

    /**
     * The income sources that count as a secure floor: income that lasts for life and
     * does not depend on a pot lasting or on investment returns — guaranteed pensions
     * (DB, State Pension), purchased annuities, any tax-free income (e.g. DLA, which must
     * NOT be dropped — see the completeness rule), and the means-tested Pension Credit
     * top-up (a guaranteed floor in its own right). Salary is excluded (it is earned and
     * stops at retirement); pension lump sums and drawdown, and savings drawn, are
     * excluded (they deplete the pot).
     */
    private const SECURE_SOURCES = ['defined_benefit', 'state_pension', 'other_taxable', 'tax_free_income', 'means_tested_benefit'];

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
            // How long the household may last, from the joint-life sampler (same across variants —
            // mortality does not depend on the housing choice). Null for a run predating the field.
            'longevity' => self::longevityPanel($primarySim->longevity),
            // The modelled late-life care-cost risk (null unless the run modelled care).
            'careImpact' => self::careImpactPanel($primarySim->careImpact),
        ];
    }

    /**
     * The modelled care-cost risk for display: the chance a path needed care and, among those,
     * the typical and high-end lifetime bill. Descriptive, never a recommendation. Null when the
     * run did not model care.
     *
     * @return array{sharePct: string, medianCost: int, p90Cost: int}|null
     */
    public static function careImpactPanel(?CareImpact $c): ?array
    {
        return $c === null ? null : [
            'sharePct' => self::formatPercent($c->shareOfPathsWithCare),
            'medianCost' => self::pounds($c->medianCareCost),
            'p90Cost' => self::pounds($c->p90CareCost),
        ];
    }

    /**
     * The longevity distribution for display: the last-survivor age spread, the planning
     * horizon in years, and the tail probabilities of reaching 95 / 100. Descriptive only —
     * a spread of outcomes, never a recommendation. Null when the run predates the field.
     *
     * @return array{ageP10: int, ageP50: int, ageP90: int, planYearsP50: int, planYearsP90: int, reaches95: string, reaches100: string}|null
     */
    public static function longevityPanel(?LongevityDistribution $l): ?array
    {
        if ($l === null) {
            return null;
        }

        return [
            'ageP10' => $l->lastSurvivorAgeP10,
            'ageP50' => $l->lastSurvivorAgeP50,
            'ageP90' => $l->lastSurvivorAgeP90,
            'planYearsP50' => $l->planYearsP50,
            'planYearsP90' => $l->planYearsP90,
            'reaches95' => self::formatPercent($l->reaches95),
            'reaches100' => self::formatPercent($l->reaches100),
        ];
    }

    /**
     * The historical sequence-of-returns stress test, shaped for the panel: how many of the
     * tested past start years the plan survived, the single worst start, and a few named
     * crises. "Years lasted" for a start that ran out is depletionYear - baseYear; a start
     * that survived shows how many years it was projected for. Every figure is the engine's.
     *
     * @return array<string, mixed>|null null when nothing was tested
     */
    public static function historicalStressTest(HistoricalBacktestResult $result, int $baseYear): ?array
    {
        if ($result->count() === 0) {
            return null;
        }

        $shape = function (?HistoricalBacktestOutcome $o) use ($baseYear): ?array {
            if ($o === null) {
                return null;
            }
            $ranOut = $o->depletionCalendarYear !== null;

            return [
                'startYear' => $o->startYear,
                'survived' => $o->essentialsAlwaysMet,
                'ranOut' => $ranOut,
                'yearsLasted' => $ranOut ? max(0, $o->depletionCalendarYear - $baseYear) : $o->planYears,
                'terminalUsable' => self::pounds($o->terminalUsableWealth),
            ];
        };

        // The canonical "retire just before the crash" start years (all within the tested range).
        $crisisLabels = [
            1929 => 'Wall Street Crash & Depression (1929)',
            1973 => 'Oil crisis & UK crash (1973–74)',
            2000 => 'Dot-com crash (2000)',
            2007 => 'Global financial crisis (2007–08)',
        ];
        $crises = [];
        foreach ($crisisLabels as $year => $label) {
            $outcome = $result->forStartYear($year);
            if ($outcome !== null) {
                $crises[] = ['label' => $label] + $shape($outcome);
            }
        }

        return [
            'tested' => $result->count(),
            'fromYear' => $result->outcomes[0]->startYear,
            'toYear' => $result->outcomes[$result->count() - 1]->startYear,
            'survivedCount' => $result->survivedCount(),
            'survivalPct' => (int) round($result->survivalRate() * 100),
            'worst' => $shape($result->worst()),
            'crises' => $crises,
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
    public static function burndown(array $plans, array $annotations = []): array
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

        // Mark the big life events (deaths, retirements, State Pension starts, the home sale) on
        // the comparison, the same annotations the single-scenario charts use ({@see
        // milestoneAnnotations}) — person-based events are shared across the compared plans.
        if ($annotations !== []) {
            $options['annotations'] = ['xaxis' => $annotations];
        }

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
    public static function ladder(ForecastResult $forecast, int $bufferMonths = 2): array
    {
        $bufferMonths = max(0, $bufferMonths);

        // Drop columns for sources that never occur, so the table stays readable.
        $active = array_values(array_filter(
            YearResult::INCOME_SOURCES,
            fn (string $source): bool => self::sourceOccurs($forecast, $source),
        ));

        // Show the capital-growth column only when the pots actually appreciate in some year
        // (an all-cash or fully-drawn plan has none), so the table stays clean otherwise.
        $showGrowth = false;
        foreach ($forecast->years as $year) {
            if (! $year->investmentGrowth()->isZero()) {
                $showGrowth = true;
                break;
            }
        }

        $rows = [];
        $floorBreachYear = null;
        foreach ($forecast->years as $year) {
            $income = [];
            foreach ($active as $source) {
                $income[$source] = ($year->incomeBySource[$source] ?? Money::zero())->format();
            }
            // Itemise the year's spend into its essential floor and the discretionary
            // remainder (discretionary = target − essential, floored at zero), so the spend
            // is traceable rather than a single opaque number. The two reconcile to the
            // target by construction (asserted in the ladder reconciliation test).
            $discretionary = $year->spendTarget->minus($year->essentialSpend)->minZero();

            // Surplus / drawing-down / shortfall, on usable money — the year's actual position.
            // Drawing from savings = the pension-lump-sum + drawdown + asset-drawdown sources;
            // a shortfall is spend that wasn't met even after drawing everything available.
            $savingsDraw = ($year->incomeBySource['pension_lump_sum'] ?? Money::zero())
                ->plus($year->incomeBySource['pension_drawdown'] ?? Money::zero())
                ->plus($year->incomeBySource['asset_drawdown'] ?? Money::zero());
            $status = ! $year->unmetSpend->isZero() ? 'shortfall'
                : ($savingsDraw->isPositive() ? 'drawing' : 'surplus');

            // Safety floor: usable money should stay above the user's buffer (default ~2 months
            // of that year's essentials), not just above zero. Flag the year it first dips below.
            $usable = $year->liquidWealth->plus($year->pensionWealth);
            $floor = $bufferMonths > 0 ? $year->essentialSpend->times($bufferMonths)->dividedBy(12) : Money::zero();
            $belowFloor = $usable->pence < $floor->pence;
            if ($belowFloor && $floorBreachYear === null) {
                $floorBreachYear = $year->calendarYear;
            }

            $rows[] = [
                'year' => $year->calendarYear,
                'ages' => implode(' / ', $year->ages),
                'income' => $income,
                'tax' => $year->totalTax->format(),
                'spend' => $year->spendTarget->format(),
                'essentialSpend' => $year->essentialSpend->format(),
                'discretionarySpend' => $discretionary->format(),
                'shortfall' => $year->unmetSpend->isZero() ? null : $year->unmetSpend->format(),
                // Capital growth left in the pots this year (share/fund appreciation, untaxed until
                // a GIA disposal) — the part of the return that grows wealth without paying out as
                // income. Sits beside "Investment income" (interest + dividends) to show the full
                // return. Can be negative in a down year.
                'investmentGrowth' => $year->investmentGrowth()->format(),
                'usableWealth' => $usable->format(),
                'totalWealth' => $year->totalWealth->format(),
                'status' => $status,
                'belowFloor' => $belowFloor,
            ];
        }

        return [
            'sources' => $active,
            'sourceLabels' => self::SOURCE_LABELS,
            'rows' => $rows,
            'showGrowth' => $showGrowth,
            'finalYear' => $forecast->finalCalendarYear,
            // The safety-floor headline: the buffer (in months of essentials), the first year
            // usable money drops below it (null = never), and the first year it runs out entirely.
            'bufferMonths' => $bufferMonths,
            'floorBreachYear' => $floorBreachYear,
            'depletionYear' => $forecast->depletionCalendarYear,
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

    /** When same-year milestones tie, order them by life sequence (sale → work → pension → death). */
    private const MILESTONE_ORDER = ['house_sale' => -1, 'retirement' => 0, 'pension_access' => 1, 'state_pension' => 2, 'death' => 3];

    /**
     * The life-event milestones timeline: *when* the major events happen across the
     * projection — each person retires, their State Pension starts, their first planned
     * pension withdrawal, and their modelled death — as a dated, aged list, so the user can
     * see what drives the year-by-year cashflow (e.g. why income steps down in a given year,
     * the question Rob's "what is the 2040 event" raised). Read-only and factual: every date
     * traces to one source — DOB + the relevant age, or the engine's single-source death year
     * ({@see ForecastResult::$deathCalendarYears}) — never a recommendation.
     *
     * Only events within the projection window are shown (a person already past an event has
     * no upcoming milestone for it). The house sale is a per-variant event (it only happens in
     * a sell variant), so it is added only when $homeSold is set, by the per-variant ladder
     * that carries the variant transforms; it is modelled at the start of the projection (the
     * proceeds are freed at year 0) and so is dated to the base year, with no per-person age.
     *
     * @return list<array{year: int, age: ?int, label: string, kind: string}>
     */
    public static function milestones(Household $household, ForecastResult $forecast, bool $homeSold = false): array
    {
        if ($forecast->years === []) {
            return [];
        }

        $baseYear = $forecast->years[0]->calendarYear;
        $finalYear = $forecast->finalCalendarYear;

        $events = [];
        $add = function (int $year, ?int $age, string $label, string $kind) use (&$events, $baseYear, $finalYear): void {
            if ($year >= $baseYear && $year <= $finalYear) {
                $events[] = ['year' => $year, 'age' => $age, 'label' => $label, 'kind' => $kind];
            }
        };

        // The home sale: a household-level event (not tied to one person), at the start of the
        // projection. Only present for a sell variant — the buy/rent ladder, never stay put.
        if ($homeSold) {
            $add($baseYear, null, 'The home is sold', 'house_sale');
        }

        foreach ($household->persons as $i => $person) {
            $name = self::personLabel($person, $i);
            $birthYear = (int) $person->dob->format('Y');

            // Retirement — only for someone still working with a planned retirement age.
            if ($person->plannedRetirementAge !== null
                && in_array($person->employmentStatus, [EmploymentStatus::Employed, EmploymentStatus::SelfEmployed], true)) {
                $add($birthYear + $person->plannedRetirementAge, $person->plannedRetirementAge, "{$name} retires", 'retirement');
            }

            // First planned pension withdrawal (earliest across this person's DC pots).
            $accessAge = self::firstWithdrawalAge($household, $person->id);
            if ($accessAge !== null) {
                $add($birthYear + $accessAge, $accessAge, "{$name} starts taking their pension", 'pension_access');
            }

            // State Pension start — the SPA computed from DOB (single source).
            $spaYear = (int) StatePensionAge::for($person->dob)->dateReached->format('Y');
            $add($spaYear, $spaYear - $birthYear, "{$name}'s State Pension starts", 'state_pension');

            // Modelled death — the engine's single-source death year.
            $deathYear = $forecast->deathCalendarYears[$person->id] ?? null;
            if ($deathYear !== null) {
                $add($deathYear, $deathYear - $birthYear, "{$name} dies", 'death');
            }
        }

        usort($events, fn (array $a, array $b): int => [$a['year'], self::MILESTONE_ORDER[$a['kind']] ?? 9]
            <=> [$b['year'], self::MILESTONE_ORDER[$b['kind']] ?? 9]);

        return $events;
    }

    /**
     * Milestone events ({@see milestones}) as ApexCharts x-axis annotations — a dated vertical
     * line per "big event" (retirement, State Pension start, first pension drawdown, death, the
     * home sale), so a chart shows *when* each step change happens, not just the curve. Colour-
     * coded by kind; the label is rotated vertical to stay legible when events fall in nearby
     * years. When two events fall in the *same* year their vertical labels are dodged (first at
     * the top, second at the bottom, further collisions nudged deeper) so they don't overlap into
     * an unreadable smear. Plain JSON (no functions), so it travels through @js into the options.
     *
     * @param  list<array{year: int, age: int|null, label: string, kind: string}>  $milestones
     * @return list<array<string, mixed>>
     */
    public static function milestoneAnnotations(array $milestones): array
    {
        $colour = [
            'retirement' => '#0284c7',
            'state_pension' => '#16a34a',
            'pension_access' => '#9333ea',
            'death' => '#dc2626',
            'house_sale' => '#d97706',
        ];

        // Dodge same-year collisions: the vertical labels would otherwise stack on one spot.
        // Alternate top/bottom of the plot, then push any further same-year label deeper in.
        $seenInYear = [];
        $annotations = [];
        foreach ($milestones as $m) {
            $n = $seenInYear[$m['year']] ?? 0;
            $seenInYear[$m['year']] = $n + 1;

            $position = $n % 2 === 0 ? 'top' : 'bottom';
            $offsetY = intdiv($n, 2) * ($position === 'top' ? 14 : -14);

            $c = $colour[$m['kind']] ?? '#94a3b8';
            $annotations[] = [
                'x' => $m['year'],
                'borderColor' => $c,
                'strokeDashArray' => 4,
                'label' => [
                    'text' => $m['label'],
                    'orientation' => 'vertical',
                    'position' => $position,
                    'offsetY' => $offsetY,
                    'borderColor' => $c,
                    'style' => ['fontSize' => '9px', 'color' => '#ffffff', 'background' => $c],
                ],
            ];
        }

        return $annotations;
    }

    /**
     * "Since your last run": how the headline figures moved between the two most recent
     * completed-run snapshots (which survive an input edit, so this shows what a change did,
     * not just Monte-Carlo seed noise). Each row is a figure whose displayed value changed;
     * `better` is true (green) / false (red) / null. Higher success and end wealth are better;
     * a later — or "never" — run-short year is better.
     * {@see Scenario::recordResultSnapshot()}.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return list<array{label: string, from: string, to: string, better: ?bool}>
     */
    public static function runDiff(array $current, array $previous): array
    {
        $rows = array_filter([
            self::diffFigure('Chance essentials are always met', $previous['successEssentials'] ?? null, $current['successEssentials'] ?? null, fn ($v): string => self::formatPercent((float) $v)),
            self::diffFigure('Chance the full budget is always met', $previous['successFullSpend'] ?? null, $current['successFullSpend'] ?? null, fn ($v): string => self::formatPercent((float) $v)),
            self::diffFigure('Spendable wealth at the end (median)', $previous['endWealthPence'] ?? null, $current['endWealthPence'] ?? null, fn ($v): string => Money::fromPence((int) $v)->format()),
            self::diffDepletion($previous['medianDepletionYear'] ?? null, $current['medianDepletionYear'] ?? null),
        ]);

        return array_values($rows);
    }

    /**
     * One diff row for a higher-is-better numeric figure — null if either side is absent or
     * the displayed value did not change.
     *
     * @return array{label: string, from: string, to: string, better: ?bool}|null
     */
    private static function diffFigure(string $label, mixed $from, mixed $to, callable $format): ?array
    {
        if ($from === null || $to === null) {
            return null;
        }
        $fromText = $format($from);
        $toText = $format($to);
        if ($fromText === $toText) {
            return null;
        }

        return ['label' => $label, 'from' => $fromText, 'to' => $toText, 'better' => $to > $from];
    }

    /**
     * The run-short-year row: a later year — or "never" (null) — is better.
     *
     * @return array{label: string, from: string, to: string, better: ?bool}|null
     */
    private static function diffDepletion(?int $from, ?int $to): ?array
    {
        if ($from === $to) {
            return null;
        }
        $text = fn (?int $year): string => $year === null ? 'never' : (string) $year;
        $rank = fn (?int $year): int => $year ?? PHP_INT_MAX;

        return ['label' => 'Median year the money runs short', 'from' => $text($from), 'to' => $text($to), 'better' => $rank($to) > $rank($from)];
    }

    /**
     * Input-sanity notes: a neutral heads-up where an entered value produced a drastic
     * modelling consequence the user might not have intended — surfaced so a surprising
     * result is understood, not silently wrong (the "wild numbers" a live edit can cause).
     * Each is a factual statement of what the forecast modelled and which input drove it,
     * never a recommendation. Empty when nothing is notable.
     *
     * Covered: (a) an employed person whose retirement age is at/below their current age, so
     * no salary is modelled; (b) a person modelled to die in the base year, which a
     * longevity/health setting below their current age produces (the engine floors a death
     * age at the current age). Both were live-edit foot-guns in Rob's 2026-06-29 walkthrough.
     *
     * @return list<array{kind: string, text: string}>
     */
    public static function inputNotes(Household $household, ForecastResult $forecast): array
    {
        if ($forecast->years === []) {
            return [];
        }

        $baseYear = $forecast->years[0]->calendarYear;

        $notes = [];
        foreach ($household->persons as $i => $person) {
            $name = self::personLabel($person, $i);
            $currentAge = $baseYear - (int) $person->dob->format('Y');

            // (a) Earnings dropped because the retirement age is at/below the current age.
            $working = in_array($person->employmentStatus, [EmploymentStatus::Employed, EmploymentStatus::SelfEmployed], true);
            if ($working && $person->grossSalary !== null && $person->grossSalary->isPositive()
                && $person->plannedRetirementAge !== null && $person->plannedRetirementAge <= $currentAge) {
                $notes[] = ['kind' => 'no_salary', 'text' => "No earnings are modelled for {$name}: their retirement age ({$person->plannedRetirementAge}) is at or below their current age ({$currentAge}), so the forecast includes no salary from them."];
            } elseif ($working && $person->grossSalary !== null && $person->grossSalary->isPositive()
                && $person->plannedRetirementAge === null) {
                // No retirement age on an earner — modelled as working indefinitely (the foot-gun
                // when a retirement age is simply left blank, e.g. V2's YCC earning for life).
                $notes[] = ['kind' => 'no_retirement_age', 'text' => "{$name} has no retirement age set, so the forecast models them earning their salary indefinitely. Set a retirement age if their pay will stop."];
            }

            // (b) Modelled to die in the base year — what a longevity/health age below the
            // current age produces, since the engine floors a death age at the current age.
            $deathYear = $forecast->deathCalendarYears[$person->id] ?? null;
            if ($deathYear !== null && $deathYear <= $baseYear) {
                $notes[] = ['kind' => 'early_death', 'text' => "{$name} is modelled to die in {$deathYear} (age {$currentAge}), the very start of the forecast. If that isn't intended, check their longevity or health setting — a value below the current age is treated as the current age."];
            }
        }

        // (c) The current home's mortgage is due for redemption within the plan — a forced
        // decision the "stay put" projection rests on. State the assumption + its consequence so
        // an impossible "keep paying forever" path is never left implied (factual, not advice).
        $home = $household->primaryResidence;
        $mortgage = $home?->outstandingMortgage;
        if ($home !== null && $home->mortgageRedemptionYear !== null && $mortgage !== null && $mortgage->isPositive()) {
            $year = $home->mortgageRedemptionYear;
            $amount = $mortgage->format();
            $text = match ($home->mortgageMaturityAction) {
                MortgageMaturityAction::Refinance => "This home's mortgage of {$amount} is due for redemption in {$year}; the forecast assumes it is refinanced (rolled into a new mortgage). If refinancing isn't available it would have to be repaid from savings or the home sold.",
                MortgageMaturityAction::RepayFromCapital => "This home's mortgage of {$amount} is due for redemption in {$year}; the forecast repays it from savings that year (a {$amount} one-off). If that capital isn't there, the year shows a shortfall — keeping the home is unaffordable.",
                MortgageMaturityAction::ForcedSale => "This home's mortgage of {$amount} is due for redemption in {$year} and is modelled as not refinanceable, so keeping the home is not an option. This report shows one strategy — weigh the realistic alternatives (sell-and-rent, buy somewhere cheaper, or let it out and rent elsewhere) as what-if scenarios on the Compare page.",
            };
            $notes[] = ['kind' => 'mortgage_redemption', 'text' => $text];
        }

        return $notes;
    }

    /** A person's display name if set, else "Person N" in household order. */
    private static function personLabel(Person $person, int $index): string
    {
        return $person->name ?? 'Person '.($index + 1);
    }

    /** The earliest planned pension-withdrawal age across a person's DC pots, or null. */
    private static function firstWithdrawalAge(Household $household, string $personId): ?int
    {
        $ages = [];
        foreach ($household->pensions as $pension) {
            if ($pension instanceof DcPension && $pension->ownerId === $personId) {
                foreach ($pension->withdrawalPlan as $withdrawal) {
                    $ages[] = $withdrawal->atAge;
                }
            }
        }

        return $ages === [] ? null : min($ages);
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

    /**
     * How to actually claim the Pension Credit the forecast models — surfaced only when the
     * projection credits it in some year. Pension Credit is means-tested (so it has to be
     * applied for, never automatic) and one of the most under-claimed benefits, so modelling
     * it as income without saying how to get it would leave money on the table. Factual
     * gov.uk signposting, not advice: the amount is means-tested and only the DWP can confirm
     * entitlement. Returns null when no year receives Pension Credit (nothing to claim).
     *
     * @return array{howToClaim: list<string>, passports: list<string>, source: string, verifiedOn: string}|null
     */
    public static function pensionCreditGuidance(ForecastResult $forecast): ?array
    {
        $received = false;
        foreach ($forecast->years as $year) {
            if (($year->incomeBySource['means_tested_benefit'] ?? Money::zero())->isPositive()) {
                $received = true;
                break;
            }
        }
        if (! $received) {
            return null;
        }

        return [
            'howToClaim' => [
                'Apply online at gov.uk/pension-credit, or call the Pension Credit claim line on 0800 99 1234 (textphone 0800 169 0133), Monday to Friday, 8am to 6pm.',
                'You can apply from 4 months before you reach State Pension age, and a claim can be backdated up to 3 months if you were already eligible — so claim as soon as you qualify.',
                'Have your National Insurance number, details of income, savings and investments, and your bank details to hand.',
            ],
            'passports' => [
                'Council Tax Reduction',
                'Housing Benefit if you rent',
                'a free TV licence if you are 75 or over',
                'help with NHS dental and optical costs',
                'Warm Home Discount and other cost-of-living help',
            ],
            'source' => 'https://www.gov.uk/pension-credit',
            'verifiedOn' => '2026-07-01',
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
        // PLSA assumes the home is owned outright, so its basis excludes the mortgage payment
        // AND the ownership costs (service charge / ground rent) — the two housing-linked
        // contingent subsets; everyday home running costs are included. Excluding them here keeps
        // the benchmark on the same basis the contingent-cost rule treats them (one definition).
        $spend = $household->expenseProfile->targetAnnualSpend()
            ->minus($household->expenseProfile->propertyCosts())
            ->minus($household->expenseProfile->mortgageCosts())
            ->minZero();
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
     * The house-sale explainer: a plain decomposition of what selling the current home
     * actually yields, and where the money goes. It surfaces the engine's single-source
     * breakdown objects so the headline "we'd get ~£X" traces to its parts and reconciles:
     *
     *  - the proceeds waterfall: sale price − outstanding mortgage − selling costs − CGT
     *    (£0 on a main home via PRR in v1) = net proceeds;
     *  - if selling and renting: the full net proceeds are invested;
     *  - if selling and buying cheaper: net − buy price − SDLT − moving = the surplus invested;
     *  - and the assumption the invested money then grows at (the blended real return), with
     *    a share paid out each year as taxable income (the income yield) rather than sitting idle.
     *
     * The selling-cost percentage is shown beside the £ figure so an out-of-range rate is
     * visible (e.g. a 20% entry showing as 20% of the sale price), not buried.
     *
     * Returns null when no sale is configured (sale price zero) — e.g. a stay-put plan — so
     * the section simply does not render. Factual throughout, never a recommendation.
     *
     * @return array{sellingCostsAssumed: bool, sellingCostBreakdown: list<array{label: string, value: string, detail: ?string}>, cgtDetail: ?array{gain: string, relievedGain: string, chargeableGain: string, allowanceUsed: string, taxableGain: string, ratePct: string}, proceeds: array{salePrice: string, mortgage: string, hasMortgage: bool, sellingCosts: string, cgt: string, cgtCharged: bool, netProceeds: string, clearsCosts: bool}, rent: array{invested: string, annualRent: ?string}, buy: ?array{netProceeds: string, buyPrice: string, sdlt: string, movingCosts: string, surplus: string, coversPurchase: bool}, blendedReturnPct: string, incomeYieldPct: string}|null
     */
    public static function saleExplainer(
        HousingProceeds $proceeds,
        HousingPurchase $purchase,
        HousingAction $action,
        float $blendedRealReturn,
        float $investmentIncomeYield,
    ): ?array {
        if (! $proceeds->salePrice->isPositive()) {
            return null;
        }

        // The selling cost is no longer a single rate but a set of components, each a % of the
        // sale or a flat fee. Show each line resolved to £ (from the reconciled breakdown) with
        // the basis it was entered on, so the total is not a black box. `assumed` = the engine
        // default applied because no components were entered.
        $assumed = $action->sellingCosts === null;
        $breakdown = [];
        foreach ($proceeds->sellingCostBreakdown as $i => $line) {
            $component = $action->sellingCosts[$i] ?? null;
            $detail = $component !== null && $component->value instanceof Percent
                ? self::ratePct($component->value->asPercent()).' of the sale price'
                : null;
            $breakdown[] = ['label' => $line['label'], 'value' => $line['amount']->format(), 'detail' => $detail];
        }

        // CGT working when the gain is only partly relieved (the home was let / not always the
        // main residence). Null = fully relieved (main home throughout), the reassuring £0 case.
        $cgtDetail = null;
        $d = $proceeds->capitalGainsDetail;
        if ($d !== null && $proceeds->capitalGainsTax->isPositive()) {
            $cgtDetail = [
                'gain' => $d->gain->format(),
                'relievedGain' => $d->privateResidenceReliefGain->format(),
                'chargeableGain' => $d->chargeableGain->format(),
                'allowanceUsed' => $d->annualExemptAmountUsed->format(),
                'taxableGain' => $d->taxableGain->format(),
                'ratePct' => self::ratePct($d->rate->asPercent()),
            ];
        }

        return [
            'sellingCostsAssumed' => $assumed,
            'sellingCostBreakdown' => $breakdown,
            'cgtDetail' => $cgtDetail,
            'proceeds' => [
                'salePrice' => $proceeds->salePrice->format(),
                'mortgage' => $proceeds->outstandingMortgage->format(),
                'hasMortgage' => $proceeds->outstandingMortgage->isPositive(),
                'sellingCosts' => $proceeds->sellingCosts->format(),
                'cgt' => $proceeds->capitalGainsTax->format(),
                'cgtCharged' => $proceeds->capitalGainsTax->isPositive(),
                'netProceeds' => $proceeds->netProceeds->format(),
                'clearsCosts' => $proceeds->clearsCosts(),
            ],
            // Sell & rent: the full net proceeds are invested; rent is then paid from income.
            'rent' => [
                'invested' => $proceeds->netProceeds->format(),
                'annualRent' => $action->annualRent !== null && $action->annualRent->isPositive() ? $action->annualRent->format() : null,
            ],
            // Sell & buy cheaper: only when a buy price is set (otherwise the plan is rent-only).
            // `shortfall` (feasibility flag) = how much the buy + its costs exceed the net proceeds
            // when they don't cover it — the engine floors the surplus at £0 and buys anyway, so
            // this makes an unaffordable "buy cheaper" visible rather than silently modelled.
            'buy' => $purchase->buyPrice->isPositive() ? [
                'netProceeds' => $purchase->netProceeds->format(),
                'buyPrice' => $purchase->buyPrice->format(),
                'sdlt' => $purchase->stampDuty->format(),
                'movingCosts' => $purchase->movingCosts->format(),
                'surplus' => $purchase->surplus->format(),
                'coversPurchase' => $purchase->coversPurchase(),
                'shortfall' => $purchase->coversPurchase() ? null
                    : $purchase->buyPrice->plus($purchase->stampDuty)->plus($purchase->movingCosts)->minus($purchase->netProceeds)->format(),
            ] : null,
            'blendedReturnPct' => self::ratePct($blendedRealReturn * 100),
            'incomeYieldPct' => self::ratePct($investmentIncomeYield * 100),
        ];
    }

    /**
     * The assumptions panel: the economic assumptions and housing-decision inputs the
     * forecast actually runs on, surfaced so every figure on the page traces to a stated
     * basis (show-your-working). Reports the figures as facts — no judgement, no
     * recommendation.
     *
     * Investment growth is the allocation-weighted blended REAL return (above inflation),
     * read from the single source ({@see PortfolioAllocation::blendedRealReturn}); the asset
     * mix it is blended from is described so the figure is not a black box. House, rent and
     * salary growth are also REAL (above inflation); inflation itself is the CPI assumption;
     * the investment income yield is NOMINAL (the share of the return paid out and taxed each
     * year). Each row says which it is, so a real and a nominal figure are never confused.
     *
     * When the user has edited any figure into a custom set, $overrides carries the keys
     * they changed ({@see AssumptionOverrides}); the panel marks those rows as the user's
     * own figure and labels the set "(customised)", so a tuned assumption is visible rather
     * than passing as the named preset.
     *
     * @param  array<string, mixed>  $overrides  the sparse `assumptionOverrides` map (keys only matter)
     * @return array{setName: string, sourceNote: string, customised: bool, mix: string, economic: list<array{key: string, label: string, value: string, note: string, edited: bool}>, housing: list<array{label: string, value: string}>}
     */
    public static function assumptionsPanel(AssumptionSet $set, HousingAction $action, PortfolioAllocation $allocation, array $overrides = []): array
    {
        $blended = $allocation->blendedRealReturn($set);
        $changed = AssumptionOverrides::changedKeys($overrides);

        // Describe the mix the blended return is weighted from, straight from the weights +
        // asset-class names, so it can never drift from the figure it explains.
        $mixParts = [];
        foreach ($set->assetClasses as $i => $assetClass) {
            $weight = $allocation->weights[$i] ?? 0.0;
            if ($weight > 0.0) {
                $mixParts[] = self::ratePct($weight * 100).' '.lcfirst($assetClass->name);
            }
        }

        $economic = [
            ['key' => 'investmentGrowth', 'label' => 'Investment growth (blended, real)', 'value' => self::ratePct($blended * 100), 'note' => 'a year above inflation, for invested pots and proceeds'],
            ['key' => 'inflation', 'label' => 'Inflation (CPI)', 'value' => self::ratePct($set->inflationMean->asPercent()), 'note' => 'figures on this page are shown in today\'s money'],
            ['key' => 'houseGrowth', 'label' => 'House price growth (real)', 'value' => self::ratePct($set->houseGrowth->asPercent()), 'note' => 'a year above inflation'],
            ['key' => 'rentGrowth', 'label' => 'Rent growth (real)', 'value' => self::ratePct($set->rentInflation->asPercent()), 'note' => 'a year above inflation'],
            ['key' => 'salaryGrowth', 'label' => 'Salary growth (real)', 'value' => self::ratePct($set->salaryGrowth->asPercent()), 'note' => 'a year above inflation'],
            ['key' => 'incomeYield', 'label' => 'Investment income yield (nominal)', 'value' => self::ratePct($set->investmentIncomeYield->asPercent()), 'note' => 'the part of the return paid out and taxed each year; the rest is capital growth'],
        ];
        $economic = array_map(
            fn (array $row): array => [...$row, 'edited' => in_array($row['key'], $changed, true)],
            $economic,
        );

        // Selling costs: each component resolved to £ on its own basis (% of sale or flat fee),
        // or the engine's assumed default when none was entered. One row per component so the
        // total on the sale waterfall traces to a stated basis here.
        $housing = [];
        if ($action->sellingCosts === null) {
            $housing[] = ['label' => 'Selling costs', 'value' => self::ratePct(2.0).' of the sale price (assumed)'];
        } else {
            foreach ($action->sellingCosts as $component) {
                $basis = $component->value instanceof Percent
                    ? self::ratePct($component->value->asPercent()).' of sale'
                    : 'flat fee';
                $housing[] = ['label' => 'Selling cost — '.$component->label, 'value' => $component->amount($action->salePrice)->format().' ('.$basis.')'];
            }
        }
        if ($action->movingCosts !== null) {
            $housing[] = ['label' => 'Moving costs', 'value' => $action->movingCosts->format()];
        }
        if ($action->buyPrice !== null && $action->buyPrice->isPositive()) {
            $housing[] = ['label' => 'Cheaper home to buy', 'value' => $action->buyPrice->format()];
        }
        if ($action->annualRent !== null && $action->annualRent->isPositive()) {
            $housing[] = ['label' => 'Rent if you sell & rent', 'value' => $action->annualRent->format().' a year (projected renting cost, not current)'];
        }

        return [
            'setName' => $set->name,
            'sourceNote' => $set->sourceNote,
            'customised' => $changed !== [],
            'mix' => implode(' / ', $mixParts),
            'economic' => $economic,
            'housing' => $housing,
        ];
    }

    /**
     * A rate as a trimmed percentage string: 2 -> "2%", 1.76 -> "1.76%", 8.75 -> "8.75%".
     * Rounds to two decimals (basis-point figures never need more) and drops trailing zeros.
     */
    private static function ratePct(float $percent): string
    {
        return rtrim(rtrim(number_format(round($percent, 2), 2, '.', ''), '0'), '.').'%';
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

    /** The display label for a housing-strategy key (stay_put / buy_outright / rent). */
    public static function strategyLabel(string $variant): string
    {
        return self::LABELS[$variant] ?? $variant;
    }
}
