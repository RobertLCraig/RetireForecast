<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Enums\ScenarioVariant;
use App\Import\MoneyText;
use App\Models\Result;
use Illuminate\Support\Collection;
use RetireForecast\FinanceEngine\Benchmark\RetirementLivingStandards;
use RetireForecast\FinanceEngine\Dto\Household;
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
     * @return array<string, mixed>
     */
    public static function build(Collection $resultsByVariant, string $primaryVariant): array
    {
        $variants = [];
        foreach (self::ORDER as $key) {
            $result = $resultsByVariant->get($key);
            if ($result instanceof Result) {
                $variants[$key] = self::headline($key, $result->simulationResult());
            }
        }

        $primary = array_key_exists($primaryVariant, $variants) ? $primaryVariant : array_key_first($variants);

        return [
            'variants' => $variants,
            'primary' => $primary,
            'fan' => self::fan($primary, $resultsByVariant->get($primary)->simulationResult()),
            'comparison' => self::comparison($resultsByVariant),
        ];
    }

    /** @return array<string, mixed> */
    private static function headline(string $variant, SimulationResult $r): array
    {
        return [
            'key' => $variant,
            'label' => self::LABELS[$variant],
            'successEssentials' => self::pct($r->successProbabilityEssentials),
            'successFullSpend' => self::pct($r->successProbabilityFullSpend),
            'depletionRate' => self::pct($r->depletionRate),
            'medianDepletionYear' => $r->medianDepletionYear ?? null,
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
     * The fan chart for one variant: 10–90 and 25–75 percentile bands plus the median
     * line, with a fully populated table of the same figures.
     *
     * @return array<string, mixed>
     */
    private static function fan(string $variant, SimulationResult $r): array
    {
        $band = fn (string $lo, string $hi): array => array_map(
            fn (array $y): array => ['x' => $y['calendarYear'], 'y' => [self::pounds($y[$lo]), self::pounds($y[$hi])]],
            $r->fanChart,
        );
        $line = array_map(
            fn (array $y): array => ['x' => $y['calendarYear'], 'y' => self::pounds($y['p50'])],
            $r->fanChart,
        );

        $rows = array_map(fn (array $y): array => [
            'year' => $y['calendarYear'],
            'p10' => $y['p10']->format(),
            'p25' => $y['p25']->format(),
            'p50' => $y['p50']->format(),
            'p75' => $y['p75']->format(),
            'p90' => $y['p90']->format(),
        ], $r->fanChart);

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
            'xaxis' => ['type' => 'numeric', 'tickAmount' => 8, 'decimalsInFloat' => 0, 'title' => ['text' => 'Calendar year']],
            'yaxis' => ['title' => ['text' => 'Total wealth (real £)'], 'labels' => ['formatter' => null]],
            'legend' => ['position' => 'top'],
        ];

        return [
            'variant' => $variant,
            'label' => self::LABELS[$variant],
            'options' => $options,
            'rows' => $rows,
        ];
    }

    /**
     * Buy-vs-rent: median terminal wealth per variant as bars, plus a table carrying
     * the success probabilities and depletion rate the bars do not show.
     *
     * @param  Collection<string, Result>  $resultsByVariant
     * @return array<string, mixed>
     */
    private static function comparison(Collection $resultsByVariant): array
    {
        $categories = [];
        $medianWealth = [];
        $rows = [];

        foreach (self::ORDER as $key) {
            $result = $resultsByVariant->get($key);
            if (! $result instanceof Result) {
                continue;
            }
            $r = $result->simulationResult();
            $categories[] = self::LABELS[$key];
            $medianWealth[] = self::pounds($r->terminalWealthPercentiles['p50']);
            $rows[] = [
                'label' => self::LABELS[$key],
                'successEssentials' => self::pct($r->successProbabilityEssentials),
                'successFullSpend' => self::pct($r->successProbabilityFullSpend),
                'depletionRate' => self::pct($r->depletionRate),
                'medianUsable' => self::usableMedian($r),
                'medianTerminal' => $r->terminalWealthPercentiles['p50']->format(),
            ];
        }

        $options = [
            'chart' => ['type' => 'bar', 'height' => 320, 'toolbar' => ['show' => false]],
            'colors' => ['#3b82f6'],
            'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '45%']],
            'series' => [['name' => 'Total wealth left, incl. home (real £)', 'data' => $medianWealth]],
            'xaxis' => ['categories' => $categories],
            'yaxis' => ['title' => ['text' => 'Real £']],
            'dataLabels' => ['enabled' => false],
            'legend' => ['show' => false],
        ];

        return ['options' => $options, 'rows' => $rows];
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

    private static function pct(float $fraction): string
    {
        return round($fraction * 100).'%';
    }

    public static function variantLabel(ScenarioVariant $variant): string
    {
        return self::LABELS[$variant->value];
    }
}
