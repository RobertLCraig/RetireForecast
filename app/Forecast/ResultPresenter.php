<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Enums\ScenarioVariant;
use App\Import\MoneyText;
use App\Models\Result;
use App\Models\Scenario;
use Illuminate\Support\Collection;
use RetireForecast\FinanceEngine\Benchmark\RetirementLivingStandards;
use RetireForecast\FinanceEngine\Benefits\SupportForMortgageInterest;
use RetireForecast\FinanceEngine\Care\CareAssumptions;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\HistoricalBacktestOutcome;
use RetireForecast\FinanceEngine\Forecast\HistoricalBacktestResult;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Housing\HousingProceeds;
use RetireForecast\FinanceEngine\Housing\HousingPurchase;
use RetireForecast\FinanceEngine\Iht\IhtOutcome;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\CareImpact;
use RetireForecast\FinanceEngine\MonteCarlo\IhtDistribution;
use RetireForecast\FinanceEngine\MonteCarlo\LongevityDistribution;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\Property\AmortisationSchedule;
use RetireForecast\FinanceEngine\StatePension\StatePensionAge;
use RetireForecast\FinanceEngine\StatePension\StatePensionUprating;
use RetireForecast\FinanceEngine\Support\WarningCode;

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
        // One-off, so deliberately NOT in SECURE_SOURCES: a gift never inflates the income floor.
        'capital_receipt' => 'One-off receipt',
        // Likewise one-off: the employer's group-life lump sum lands once, on a death in service.
        'death_in_service' => 'Death-in-service payout',
    ];

    /**
     * The income sources that count as a secure floor: income that lasts for life and
     * does not depend on a pot lasting or on investment returns — guaranteed pensions
     * (DB, State Pension), purchased annuities and any tax-free income (e.g. DLA, which
     * must NOT be dropped — see the completeness rule). Salary is excluded (it is earned
     * and stops at retirement); pension lump sums and drawdown, and savings drawn, are
     * excluded (they deplete the pot).
     */
    private const SECURE_SOURCES = ['defined_benefit', 'state_pension', 'other_taxable', 'tax_free_income'];

    /**
     * Income the forecast credits but nobody is guaranteed: reported BESIDE the floor, never
     * inside it (board card 0046). Pension Credit is means-tested, so it has to be claimed, and
     * around a third of eligible pensioner households never claim it; even once claimed it moves
     * with income, with capital, with a change of circumstances and with a review. Counting it in
     * the guaranteed floor told a household that essentials it may never receive a penny towards
     * were covered for life, which is the one thing that readout exists to answer.
     */
    private const CONTINGENT_SOURCES = ['means_tested_benefit'];

    /** The 3-tier budget categories, in display order, with their labels. */
    /**
     * PUBLIC so a caller can check a rendered tier against its canonical name without restating it
     * (`scenarios:audit` does exactly that). A tier heading was once silently overwritten with its
     * own last spend line, and the only safe check is against the constant that owns the name.
     */
    public const EXPENSE_TIERS = [
        'essential' => 'Essential',
        'discretionary' => 'Discretionary',
        'self_investment' => 'Self-investment',
    ];

    /**
     * @param  Collection<string, Result>  $resultsByVariant  keyed by variant value
     * @param  bool  $includeHome  false (default) plots USABLE wealth (excl. the home) —
     *                             the spendable money that actually runs out; true plots
     *                             TOTAL wealth (incl. the home's equity, net of any
     *                             mortgage owed). The home is an illiquid
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
            // The spread of Inheritance Tax across the sampled futures (null unless IHT is modelled).
            'ihtDistribution' => self::ihtDistributionPanel($primarySim->ihtDistribution),
        ];
    }

    /**
     * The Monte Carlo IHT spread for display: the share of futures leaving any IHT, and the median
     * vs high-end (p90) total across all futures. Complements the deterministic IHT panel (a single
     * representative-death figure) with the range longevity + returns produce. Null when not modelled.
     *
     * @return array{sharePct: string, median: int, p90: int}|null
     */
    public static function ihtDistributionPanel(?IhtDistribution $d): ?array
    {
        return $d === null ? null : [
            'sharePct' => self::formatPercent($d->shareWithAnyIht),
            'median' => self::pounds($d->medianIht),
            'p90' => self::pounds($d->p90Iht),
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
     * The Inheritance Tax outcome for the panel: the estate valued at the final death, the
     * nil-rate bands applied, the tax due at each death and the total — in real (today's money)
     * terms, as the engine computed them. Relationship status is stated plainly so the reader
     * sees the assumption. Null when IHT is not modelled (the toggle off). Education only: it
     * shows the headline bands, not a full estate computation (gifts, trusts, reliefs excluded).
     *
     * @return array<string, mixed>|null
     */
    public static function ihtPanel(?IhtOutcome $iht, Household $household): ?array
    {
        if ($iht === null) {
            return null;
        }

        $couple = count($household->persons) === 2;
        $second = $iht->secondDeath;

        $panel = [
            'couple' => $couple,
            'relationship' => $couple
                ? ($household->relationshipStatus === RelationshipStatus::MarriedOrCivilPartnership ? 'married' : 'cohabiting')
                : 'single',
            'total' => self::pounds($iht->total),
            'anyTaxDue' => $iht->total->isPositive(),
            'pensionsIncluded' => $second->pensionsIncluded,
            'secondDeath' => [
                'estate' => self::pounds($second->totalEstate),
                'nrb' => self::pounds($second->nilRateBandUsed),
                'rnrb' => self::pounds($second->residenceNilRateBandUsed),
                'taxable' => self::pounds($second->taxableEstate),
                'tax' => self::pounds($second->tax),
            ],
            'firstDeath' => null,
        ];

        if ($iht->firstDeath !== null) {
            $first = $iht->firstDeath;
            $panel['firstDeath'] = [
                'estate' => self::pounds($first->totalEstate),
                'tax' => self::pounds($first->tax),
                // Spouse exemption vs simply under the threshold: the engine flags the exempt case.
                'spouseExempt' => in_array(
                    WarningCode::IHT_SPOUSE_EXEMPTION,
                    array_map(static fn ($w) => $w->code, $first->warnings),
                    true,
                ),
            ];
        }

        return $panel;
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
            // The "nearly always" companion to the all-or-nothing figure above: one short year in
            // fifty takes that one to 0%, which reads as a plan that never worked. Null (shown as
            // a dash) for a run stored before the measure existed, never 0% — see the mapper.
            'successFullSpendMostYears' => $r->successProbabilityFullSpendMostYears === null
                ? null
                : self::formatPercent($r->successProbabilityFullSpendMostYears),
            'fullSpendMostYearsThreshold' => (int) round(SimulationResult::FULL_SPEND_MOST_YEARS_THRESHOLD * 100),
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

        $basisLabel = $usableBasis ? 'Spendable money, excl. home' : 'Total wealth, incl. home equity';

        // Anchor the axis at £0 so "do we hit zero?" reads honestly — UNLESS the net-position
        // series dips below zero (a household that runs out), in which case let the axis extend
        // negative to show the depth of the shortfall. p10 is the lowest band, so it decides.
        $dipsNegative = false;
        foreach ($series as $y) {
            if ($y['p10']->isNegative()) {
                $dipsNegative = true;
                break;
            }
        }
        $yaxis = ['forceNiceScale' => true, 'title' => ['text' => $basisLabel.' (real £)']];
        if (! $dipsNegative) {
            $yaxis['min'] = 0;
        }

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
            'yaxis' => $yaxis,
            'legend' => ['position' => 'top'],
        ];

        // Shade everything below £0 light red when the net-position fan runs into shortfall, so
        // that territory reads at a glance. The results page later merges its milestone x-axis
        // annotations into this same key (it adds annotations.xaxis, keeping this band).
        if ($dipsNegative) {
            $options['annotations'] = ['yaxis' => self::belowZeroBand()];
        }

        return [
            'variant' => $variant,
            'label' => self::LABELS[$variant],
            'usableBasis' => $usableBasis,
            'basisLabel' => $basisLabel,
            'dipsNegative' => $dipsNegative,
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
        // The spendable (excl-home) view prefers the net-position fan, which continues below
        // £0 by the cumulative shortfall so a household that runs out shows how deep the gap
        // gets rather than flatlining at zero. Falls back to the usable fan (floored at £0) for
        // a run persisted before the net-position fan existed, then to the total fan.
        if (! $includeHome && $r->netPositionFanChart !== []) {
            return [$r->netPositionFanChart, true];
        }
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
     * @return array{options: array<string, mixed>, rows: list<array<string, mixed>>, years: list<int>, lineRows: list<array{year: int, cells: array<string, ?string>}>, strategies: list<array{key: string, label: string}>, usableBasis: bool, basisLabel: string, dipsNegative: bool}
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

        $basisLabel = $usableBasis ? 'Median spendable money, excl. home' : 'Median total wealth, incl. home equity';

        // A strategy whose median future runs out shows a net-position median below £0; let the
        // axis extend negative so that depth is visible rather than clipped to a flat zero.
        $dipsNegative = false;
        foreach ($byVariant as $median) {
            foreach ($median as $point) {
                if ($point['pounds'] < 0) {
                    $dipsNegative = true;
                    break 2;
                }
            }
        }
        $yaxis = ['forceNiceScale' => true, 'title' => ['text' => $basisLabel.' (real £)']];
        if (! $dipsNegative) {
            $yaxis['min'] = 0;
        }

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
            'yaxis' => $yaxis,
            'legend' => ['position' => 'top'],
        ];

        // Shade everything below £0 light red when a strategy's median runs into shortfall, so
        // that territory reads at a glance (this chart carries no other annotations).
        if ($dipsNegative) {
            $options['annotations'] = ['yaxis' => self::belowZeroBand()];
        }

        return [
            'options' => $options,
            'rows' => $rows,
            'years' => $years,
            'lineRows' => $lineRows,
            'strategies' => $strategies,
            'usableBasis' => $usableBasis,
            'basisLabel' => $basisLabel,
            'dipsNegative' => $dipsNegative,
        ];
    }

    /**
     * A light-red y-axis region shading everything below £0, so a chart that dips into
     * shortfall marks that territory at a glance (used by the fan, strategy-comparison and
     * burndown charts). Returns the `annotations.yaxis` list (one region).
     *
     * The band runs from the zero line down to a floor far below any real forecast: ApexCharts
     * clamps a y-axis region to the plot area and clips it to the grid mask, so this sentinel
     * floor simply fills to the bottom of the chart whatever the auto axis minimum turns out to
     * be — no need to compute the axis min server-side. Only added when a series actually dips
     * negative, so a solvent chart shows no empty band.
     *
     * @return list<array<string, mixed>>
     */
    private static function belowZeroBand(): array
    {
        return [[
            'y' => 0,
            'y2' => -1_000_000_000,
            'fillColor' => '#ef4444',
            'opacity' => 0.09,
            'borderColor' => 'transparent',
        ]];
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
        $dipsNegative = false;
        foreach ($plans as $plan) {
            // Net position continues the usable-wealth line below £0 once assets are exhausted:
            // usable (liquid + pension) minus the cumulative shortfall the plan could not fund.
            // Equal to usable wealth while solvent (unmet is zero), so it reconciles to the
            // cashflow ladder's usable-wealth column year-for-year until the money runs out,
            // then shows how deep the funding gap gets instead of flatlining at zero.
            $netByYear = [];
            $cumulativeUnmet = Money::zero();
            foreach ($plan['forecast']->years as $year) {
                $cumulativeUnmet = $cumulativeUnmet->plus($year->unmetSpend);
                $netByYear[$year->calendarYear] = $year->liquidWealth->plus($year->pensionWealth)->minus($cumulativeUnmet);
            }

            $data = [];
            $cells = [];
            foreach ($years as $calendarYear) {
                $net = $netByYear[$calendarYear] ?? null;
                $data[] = ['x' => $calendarYear, 'y' => $net !== null ? self::pounds($net) : null];
                $cells[$calendarYear] = $net?->format();
                $dipsNegative = $dipsNegative || ($net !== null && $net->isNegative());
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

        // Annotations layer. Two things share it:
        //  - the big life-event verticals (deaths, retirements, State Pension starts, the home
        //    sale), the same annotations the single-scenario charts use ({@see
        //    milestoneAnnotations}); person-based events are shared across the compared plans.
        //  - a light-red band shading everything below £0, drawn only when a plan actually runs
        //    out, so the shortfall region ("savings are gone, this is the funding gap") reads at
        //    a glance rather than as an easily-missed dip past the axis.
        $chartAnnotations = [];
        if ($annotations !== []) {
            $chartAnnotations['xaxis'] = $annotations;
        }
        if ($dipsNegative) {
            $chartAnnotations['yaxis'] = self::belowZeroBand();
        }
        if ($chartAnnotations !== []) {
            $options['annotations'] = $chartAnnotations;
        }

        return ['options' => $options, 'years' => $years, 'rows' => $rows, 'dipsNegative' => $dipsNegative];
    }

    /**
     * Every money figure the ENGINE supplied for itself because the user left the input blank, stated
     * plainly so the reader can see and challenge it.
     *
     * The standing rule this serves: **the model must never use a figure the user cannot see or
     * interrogate.** A default that silently moves the result is indistinguishable, to a reader, from
     * a number we made up.
     *
     * Each value is read from the constant that OWNS it ({@see HousingComparison}), never restated
     * here — a disclosure that drifts from the figure actually used would be worse than none.
     *
     * When adding a new engine-side default, add it here too; `AssumedFiguresDisclosureTest` fails
     * on any known default that reaches a result without appearing in this list.
     *
     * @return list<string>
     */
    /**
     * The housing action that actually bears on a projection of `$variant` — null unless the plan
     * BUYS a home.
     *
     * A base scenario carries a buy price so the Compare page can run every variant against it, so
     * "was a buy price entered?" is the wrong question to gate a disclosure on. The bought home's
     * defaults — its assumed upkeep, the assumed moving costs, a depreciation override — reach a
     * projection only when that projection buys. Disclosing them on a stay-put or sell-and-rent
     * plan asserts a cost the model never charges, which is the *opposite* of what the
     * no-invisible-figures rule exists for: it makes the reader plan around a figure that isn't
     * there. One home for the rule, so the results page, the PDF and `scenarios:audit` agree on
     * which notes a plan should carry.
     */
    public static function housingActionFor(?HousingAction $action, string $variant): ?HousingAction
    {
        return $variant === ScenarioVariant::BuyOutright->value ? $action : null;
    }

    /**
     * Does the plan on display KEEP the current home? The sibling of {@see housingActionFor}, for
     * the defaults that belong to the home already owned rather than to one being bought: a
     * buy-cheaper or sell-and-rent projection strips the service charge with the flat, so telling
     * its reader what we assumed about that charge asserts a cost the model never charges.
     *
     * Null (no variant given) means "the household as entered", which keeps its home.
     */
    public static function keepsCurrentHome(?string $variant): bool
    {
        return $variant === null || $variant === ScenarioVariant::StayPut->value;
    }

    public static function assumedFigures(Household $household, ?HousingAction $action, ?ForecastResult $forecast = null, ?string $variant = null, ?AssumptionSet $set = null, ?ForecastSettings $settings = null): array
    {
        $out = [];

        // The above-CPI escalation of the home-ownership cost bucket (service charge, ground rent,
        // levies), where the reader gave no rate of their own. Until card 0028 a blank meant "rises
        // with CPI": a figure nobody entered, that the evidence rules out, and that compounds
        // quietly into thousands a year of real spend by the survivor's years. The rate and the
        // pounds it grows are both READ from the engine, so this cannot drift from what was charged.
        $profile = $household->expenseProfile;
        if (self::keepsCurrentHome($variant) && $profile->propertyCostsGrowthIsAssumed()) {
            $rate = $profile->propertyCostsRealGrowth();
            $pct = rtrim(rtrim(number_format($rate->asPercent(), 2), '0'), '.');
            $out[] = "You didn't say how fast your home-ownership costs rise, so we've assumed {$pct}% a year above "
                ."inflation on the {$profile->propertyCosts()->format()} a year you pay while you own this home "
                .'(service charge, ground rent and levies). Those costs are not ordinary shopping: block insurance, '
                .'building-safety work and the energy a communal bill buys have all risen faster than prices since '
                .'2019, so charging them at plain inflation would flatter the plan, most of all in the years one of '
                .'you is on their own. If your managing agent has given you a different figure, enter it: over a long '
                .'plan the gap between this and inflation-only is thousands of pounds a year.';
        }

        // What letting the home costs, where the reader gave no rate of their own. Until card 0030
        // a let property earned its rent GROSS, and a quarter of gross rent is the difference
        // between a let that pays and one that loses money every month. The rates are READ from the
        // constants that own them, so re-sourcing one moves this sentence with it.
        $home = $household->primaryResidence;
        $assumedLetting = self::keepsCurrentHome($variant) ? ($home?->assumedLettingRates() ?? []) : [];
        if ($assumedLetting !== []) {
            $covers = [
                'management' => 'for letting-agent management, VAT included',
                'void' => 'for the weeks the property stands empty between tenants',
                'maintenance' => 'for repairs, the inventory, and the gas and electrical safety certificates',
            ];
            $parts = [];
            foreach ($assumedLetting as $which => $rate) {
                $parts[] = self::ratePct($rate->asPercent()).' '.$covers[$which];
            }
            $last = array_pop($parts);
            $list = $parts === [] ? (string) $last : implode(', ', $parts).' and '.$last;
            $total = self::ratePct($home?->lettingCostRate()->asPercent() ?? 0.0);
            $out[] = "You didn't say what letting this property costs you, so we've taken "
                .$list.' off the rent, '
                ."{$total} of it in all. Gross rent is the one figure a landlord never receives, and leaving "
                .'those costs out does not just flatter a letting plan: on a single flat they are usually about a '
                .'quarter of the rent, which is enough to turn what looks like money coming in into money going '
                .'out. If you self-manage, or you have a long-standing tenant, enter your own figures.';
        }

        // What a Defined Benefit pension's increases were taken to mean. Board card 0035 made both
        // escalation dropdowns live, and two of the choices a reader can make are not taken wholly
        // at their word: a FIXED basis with no rate falls back to the engine's, and RPI is modelled
        // at CPI because no wedge between them is sourced. Both compound on guaranteed income for
        // the whole plan, and a reader told nothing would believe the model heard them.
        $fixedIsAssumed = false;
        $rpiChosen = false;
        foreach ($household->pensions as $pension) {
            if (! $pension instanceof DbPension) {
                continue;
            }
            $fixedIsAssumed = $fixedIsAssumed || $pension->fixedEscalationIsAssumed();
            $rpiChosen = $rpiChosen
                || $pension->revaluationBasis === PensionEscalationBasis::Rpi
                || $pension->escalationInPayment === PensionEscalationBasis::Rpi;
        }
        if ($fixedIsAssumed) {
            // READ from the constant that owns it, so re-sourcing the rate moves this sentence.
            $fixed = self::ratePct(Percent::fromBasisPoints(DbPension::DEFAULT_FIXED_ESCALATION_BPS)->asPercent());
            $out[] = "You set one of your defined-benefit pensions to a FIXED increase but didn't say what rate, "
                ."so we've used {$fixed} a year. Scheme rules commonly grant 3% or 5%, and we take the lower of the "
                .'two so the plan is not flattered. Over thirty years the difference between them roughly doubles the '
                .'pension, so this is worth getting right: your scheme booklet or your annual statement will say '
                .'which rate applies, and you can enter it on the pension.';
        }
        if ($rpiChosen) {
            $out[] = 'You set one of your defined-benefit pensions to increase with RPI. We increase it at CPI '
                .'instead: RPI is being brought into line with CPIH from 2030, so on a plan of this length the two '
                .'are the same for nearly all of it, and we have no published figure for the gap in the years before '
                .'that. It means an RPI pension is modelled slightly LOW rather than slightly high, which is the '
                .'direction we err in. If your scheme is RPI-linked and you want the difference modelled, say so.';
        }

        // How widely the home's value is modelled as swinging. The set's house volatility is an
        // INDEX figure, and an index averages a whole market, so the property-specific half of the
        // risk has already been diversified out of it. One flat can be re-rated by its block, its
        // lease or its street while the index does nothing, so the engine widens the figure for a
        // household whose home is a single property. Nobody enters that, and it moves the fan on
        // every homeowner plan. The multiple, the index figure and the widened figure are all READ
        // from the set that owns them, so a re-sourced volatility moves this sentence with it.
        $ownsAHome = (self::keepsCurrentHome($variant) && $household->primaryResidence !== null)
            || ($action?->buyPrice !== null && $action->buyPrice->isPositive());
        if ($ownsAHome && $set !== null && $set->singlePropertyVolatilityIsAssumed()) {
            $index = self::ratePct($set->houseGrowthVolatility?->asPercent() ?? 0.0);
            $property = self::ratePct($set->singlePropertyVolatility()?->asPercent() ?? 0.0);
            $multiple = rtrim(rtrim(number_format(AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE, 2), '0'), '.');
            $out[] = "The house-price swing we model, {$index} a year, is an INDEX figure: it is what a whole "
                .'market does on average, so the part of the risk that belongs to one particular home has already '
                ."been averaged out of it. Your home is one property, so we've widened it {$multiple} times, to "
                ."{$property} a year. Your flat can be re-rated by its block, its lease, its street or its "
                .'condition while the index does nothing, and a household whose wealth is mostly one home carries '
                .'all of that. It does not change the central projection, only how wide the range of outcomes '
                .'around it is. If you think your home is steadier or twitchier than that, enter your own figure.';
        }

        // How long the State Pension triple lock is assumed to last (board card 0038). Until that
        // card the projector raised the pension by the greater of inflation and 2.5% with no
        // source, no setting and nothing on any screen. With inflation modelled near 2% the floor
        // binds in most years, so the State Pension grew in REAL terms for the whole plan and the
        // Pension Credit guarantee, uprated by the same running factor, rose with it. Assuming a
        // contested policy holds for forty years is the OPTIMISTIC branch, which is the reverse
        // of how every other default here is set. The floor is READ from the enum that owns it.
        $hasStatePension = false;
        foreach ($household->pensions as $pension) {
            $hasStatePension = $hasStatePension || $pension instanceof StatePensionEntitlement;
        }
        if ($hasStatePension && $settings !== null && $settings->statePensionUpratingIsAssumed()) {
            $floor = self::ratePct(StatePensionUprating::floor()->asPercent());
            $out[] = "You didn't say how long the State Pension triple lock should be assumed to last, so we've "
                ."assumed it lasts for the whole of this plan: your State Pension rises by at least {$floor} a year "
                .'however low inflation goes. That is the cheerful assumption, and it is the one place we make one. '
                .'The floor is a government policy rather than a law, no government has promised it beyond the '
                .'current Parliament, and the plan here runs for decades. Because we model inflation at around two '
                ."percent, the {$floor} floor lifts your pension in most years, so it keeps growing in real terms "
                .'for life, and the Pension Credit guarantee rises with it, because that is uprated by the same '
                .'figure. If you would rather not plan on that, you can end the lock in a year of your choosing or '
                .'have the State Pension rise with prices alone. One thing works the other way: the real lock is the '
                .'highest of earnings, prices and the floor, and we do not model the earnings part, because we hold '
                .'no national wage series. So in a year when wages outrun both, this is on the cautious side.';
        }

        // How the invested money is split across asset classes. Nobody has ever entered this: the
        // engine falls back to a cautious 40/60 and no caller passes anything else, so the largest
        // single determinant of the whole answer is a figure the reader has never been shown. The
        // weights, the class names and the return they blend to are all READ from the engine.
        if ($settings !== null && $set !== null && $settings->allocationIsAssumed()) {
            $allocation = $settings->allocation();
            $parts = [];
            foreach ($allocation->weights as $i => $weight) {
                $name = $set->assetClasses[$i]->name ?? 'other';
                $parts[] = self::ratePct($weight * 100).' '.mb_strtolower($name);
            }
            $blended = self::ratePct($allocation->blendedRealReturn($set) * 100);
            $out[] = "You didn't say how your invested money is split between shares, bonds and cash, so we've "
                .'assumed a cautious mix of '.implode(', ', $parts).', and applied it to every pension, ISA and '
                .'investment account in the plan. On this assumption set that blends to a real return of '
                ."{$blended} a year above inflation, which is the figure your pots grow at. This is the single "
                .'biggest thing driving whether the money lasts, so it is worth knowing it is ours and not yours: '
                .'a mix with more shares in it would show more money and a wider range of outcomes, and one with '
                .'less would show the opposite. It is not yet something you can change on this screen.';
        }

        // Every figure behind the modelled care risk. They only reach a projection when the care
        // toggle is on, and then they set the size of the tail the toggle exists to show: the
        // chance of needing care, how long it runs, how often it is nursing rather than
        // residential, and what a week costs. All READ from the assumptions the sampler uses.
        if ($settings !== null && $settings->modelCareCost) {
            $care = CareAssumptions::default();
            $male = self::ratePct($care->probabilityOfCareMale * 100);
            $female = self::ratePct($care->probabilityOfCareFemale * 100);
            $mean = rtrim(rtrim(number_format($care->meanDurationYears, 2), '0'), '.');
            $nursing = self::ratePct($care->probabilityNursing * 100);
            $out[] = 'You asked us to model the risk of late-life care, and every figure in that model is ours, '
                ."not yours. We give a man a {$male} chance of needing residential or nursing care in later life "
                ."and a woman a {$female} chance, because women live longer and more often outlive the person who "
                ."would have cared for them. A spell lasts {$mean} years on average, capped at {$care->maxDurationYears}, "
                ."and we place it at the end of life. {$nursing} of spells are nursing rather than residential. "
                ."The bill is {$care->residentialWeekly->format()} a week residential and {$care->nursingWeekly->format()} "
                .'a week nursing, before the means test takes off what the council would pay. These come from '
                .'national studies, not from anything about you: your own family history, your health today and '
                .'where you live all move them, and the fees in London and the South East run twenty to thirty-five '
                .'per cent above these. Read the care numbers as the shape of a risk, not as a prediction.';
        }

        // Using the ISA allowance ("bed and ISA"). This one is not a blank input filled in, it is
        // an ACTION the engine performs on the household's behalf: money they hold in a taxable
        // account is moved into an ISA up to their unused allowance each year, so its growth and
        // dividends stop being taxed. It moves the result, nobody entered it, and it is exactly
        // the kind of figure this rule exists to surface. Read out of the forecast itself rather
        // than restated, so the reader is told what the projection actually did.
        if ($forecast !== null) {
            $sheltered = array_map(static fn (YearResult $y): int => $y->isaSheltered()->pence, $forecast->years);
            $total = array_sum($sheltered);
            if ($total > 0) {
                $firstYear = $forecast->years[array_key_first(array_filter($sheltered))];
                $lifetime = Money::fromPence($total)->format();
                $out[] = "We've assumed you use your ISA allowance on money you already hold outside one. Each year "
                    .'the forecast moves what it can from your general investment account into an ISA, so from then on '
                    ."its growth and dividends are tax-free: {$firstYear->isaSheltered()->format()} in {$firstYear->calendarYear}, "
                    ."and {$lifetime} across the plan. Nobody entered this: it is what a "
                    .'household holding money outside an ISA would normally do, and leaving it out would show you paying '
                    .'tax you would not really pay. Each move is a sale, so it is kept small enough that the gain stays '
                    .'inside your capital-gains allowance and costs nothing. If you would not do it, say so and we will '
                    .'model the money staying where it is.';
            }

            // The Money Purchase Annual Allowance. Not a blank input filled in either: a statutory
            // cap the projection starts applying the moment the plan takes money flexibly out of a
            // pension, which from then on shrinks what the contributions the reader DID enter can
            // buy. The panel that used to be the only mention of it needs a planned withdrawal
            // instruction to say anything, and a draw taken to meet a shortfall is not one — so on
            // an ordinary plan the cap bound and nothing said so. The engine writes the sentence
            // (it owns the figure and reads the statutory constant); this only says when it starts.
            foreach ($forecast->years as $year) {
                $mpaa = self::firstWarning($year, WarningCode::MPAA_TRIGGERED);
                if ($mpaa !== null) {
                    $out[] = "{$mpaa} In this plan that starts in {$year->calendarYear}.";
                    break;
                }
            }

            // The tenancy deposit (board card 0031). Nobody enters it, it is worked out from the
            // rent against the Tenant Fees Act cap, and it is charged as real money in the year the
            // tenancy starts, so it is exactly the kind of figure this rule exists to surface. The
            // engine writes the sentence, because it owns both the cap and the pounds.
            foreach ($forecast->years as $year) {
                $tenancy = self::firstWarning($year, WarningCode::TENANCY_UP_FRONT_COST);
                if ($tenancy !== null) {
                    $out[] = $tenancy;
                    break;
                }
            }
        }

        if ($action === null) {
            return $out;
        }

        // The bought home's upkeep: 1% of its value a year, when no figure was entered. On a £150,000
        // home that is £1,500/yr charged as an essential cost for the rest of the plan.
        if ($action->buyPrice !== null && $action->buyPrice->isPositive() && $action->buyRunningCosts === null) {
            $current = $household->primaryResidence?->runningCosts;
            if ($current === null || ! $current->isPositive() || $action->salePrice->isZero()) {
                $rate = Percent::fromBasisPoints(HousingComparison::HOME_MAINTENANCE_RATE_BPS);
                $amount = $action->buyPrice->applyRate($rate);
                $pct = rtrim(rtrim(number_format($rate->asPercent(), 2), '0'), '.');
                $out[] = "You didn't give running costs for the home you'd buy, so we've assumed {$pct}% of its "
                    ."value a year — {$amount->format()} a year for maintenance, insurance and council tax — and "
                    .'charged it as an essential cost for the whole plan. If you know the real figure (a service '
                    ."charge, or a park home's pitch fee) enter it: at this size, being out by half changes the "
                    .'plan by hundreds of pounds a year.';
            }
        }

        // The cost of moving, when no figure was entered.
        if ($action->buyPrice !== null && $action->buyPrice->isPositive() && $action->movingCosts === null) {
            $moving = Money::fromPence(HousingComparison::DEFAULT_MOVING_COSTS_PENCE);
            $out[] = "You didn't give a figure for moving costs, so we've assumed {$moving->format()} and taken it "
                .'off the money the sale frees. Removals, and any legal or survey fees not already in your selling '
                .'costs, come out of this.';
        }

        return $out;
    }

    /**
     * The message of the first warning of $code this year carries, or null if it carries none.
     * Lets a disclosure quote the ENGINE's own sentence, so the figure in it is the figure the
     * projection used and the two cannot drift.
     */
    private static function firstWarning(YearResult $year, string $code): ?string
    {
        foreach ($year->warnings as $warning) {
            if ($warning->code === $code) {
                return $warning->message;
            }
        }

        return null;
    }

    /**
     * Is this spend line the mortgage payment? Matches {@see HouseholdAssembler::autoCondition},
     * which classifies a line as `while_mortgaged` on the same substring — so the line whose amount
     * is substituted here is exactly the line the engine drops.
     */
    private static function isMortgageLine(string $label): bool
    {
        return str_contains(mb_strtolower($label), 'mortgage');
    }

    /**
     * The two figures a non-financial reader actually plans against, for one year: **capital in
     * hand** and **money per month**. Everything else this tool reports is annual, and net worth —
     * the number a reader reaches for — includes a home they cannot spend.
     *
     * ONE DEFINITION, here. Results, Compare, the affordability screen and the PDF all read this, so
     * a figure cannot drift between surfaces.
     *
     *  - **availableCapital** is `liquidWealth` (cash + GIA + ISA) ONLY: money spendable this year
     *    with no tax on withdrawal. Home equity is excluded — it is not spendable while they live
     *    there, and that exclusion is the point of the figure.
     *  - **pensionCapital** is carried SEPARATELY and labelled taxable. It is deliberately NOT added
     *    to available capital: £100,000 of pension is not £100,000 in the hand, and the ladder's
     *    existing `usableWealth` (liquid + pension) overstates it by exactly the tax due. Never
     *    reuse `usableWealth` for this.
     *  - **monthlyAllowance** is the spend the plan can actually FUND (`spendTarget − unmetSpend`),
     *    not the target. In a shortfall year the target is money the household does not have.
     *  - **monthlyFree** — funded spend above the essential floor — is the discretionary money: the
     *    holidays-and-treats budget, and the answer to "what could we afford to spend?".
     *
     * All figures are REAL (today's money), like the rest of the engine's output; every display must
     * say so. Monthly figures divide the annual pence ONCE (`intdiv`), so ×12 reconciles to the
     * annual row to within the rounding remainder rather than drifting from a re-parsed string.
     *
     * @return array<string, string> formatted money, ready to print
     */
    public static function spendableFor(YearResult $year): array
    {
        $funded = $year->spendTarget->minus($year->unmetSpend)->minZero();
        $essential = Money::min($year->essentialSpend, $funded);

        // Divide once, then derive the remainder — never three independent intdivs. Rounding each
        // part separately lets the parts disagree with their own total by a penny (seen on a year
        // with a 1p shortfall), which is precisely the total-drifts-from-its-parts defect the
        // data-layer rule forbids. "Free to choose" IS "what is left after essentials", so
        // computing it as the remainder matches its definition rather than fudging it.
        $monthlyAllowancePence = intdiv($funded->pence, 12);
        $monthlyEssentialPence = intdiv($essential->pence, 12);
        $monthlyFreePence = max(0, $monthlyAllowancePence - $monthlyEssentialPence);

        return [
            'availableCapital' => $year->liquidWealth->format(),
            'pensionCapital' => $year->pensionWealth->format(),
            'fundedSpend' => $funded->format(),
            'monthlyAllowance' => Money::fromPence($monthlyAllowancePence)->format(),
            'monthlyEssential' => Money::fromPence($monthlyEssentialPence)->format(),
            'monthlyFree' => Money::fromPence($monthlyFreePence)->format(),
        ];
    }

    /**
     * The per-scenario headline block: what they would have available and what they could spend a
     * month — now, and in the survivor years, which is where these households actually fail.
     *
     * The survivor figure is the point of the block. A couple's plan can look comfortable for a
     * decade and then halve when one of them dies, and a reader comparing plans on the opening year
     * alone will pick the wrong one. Null when nobody outlives their partner in the projection.
     *
     * @return array{now: array<string, string>, survivor: array<string, string>|null, survivorFromYear: int|null, finalYear: int}
     */
    public static function spendableSummary(ForecastResult $forecast): array
    {
        $years = $forecast->years;
        if ($years === []) {
            return ['now' => [], 'survivor' => null, 'survivorFromYear' => null, 'finalYear' => 0];
        }

        // The first year only one partner is left alive — the step down a reader must see.
        $survivorYear = null;
        foreach ($years as $year) {
            if ($year->aliveCount === 1 && count($year->ages) > 1) {
                $survivorYear = $year;
                break;
            }
        }

        return [
            'now' => self::spendableFor($years[0]),
            'survivor' => $survivorYear === null ? null : self::spendableFor($survivorYear),
            'survivorFromYear' => $survivorYear?->calendarYear,
            'finalYear' => $years[count($years) - 1]->calendarYear,
        ];
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
            fn (string $source): bool => self::sourceOccurs($forecast->years, $source),
        ));

        // Show the capital-growth column only when the pots actually appreciate in some year
        // (an all-cash or fully-drawn plan has none), so the table stays clean otherwise.
        // The ongoing charges taken out of those pots are totalled rather than given a column
        // of their own (the ladder is already at the width print can carry): one lifetime figure
        // in the note below the table, summed from the SAME per-year results, so what holding
        // the money costs is a number the reader can see rather than a quietly smaller growth
        // line. The growth column is gross of it, so growth − charges is the pots' net gain.
        $showGrowth = false;
        $chargesTotal = Money::zero();
        foreach ($forecast->years as $year) {
            if (! $year->investmentGrowth()->isZero()) {
                $showGrowth = true;
            }
            $chargesTotal = $chargesTotal->plus($year->investmentCharges());
        }

        $rows = [];
        $floorBreachYear = null;
        // Renting has a second gate the money-lasts projection cannot see: the tenancy has to be
        // GRANTED. Referencing is an income test, so a household sitting on the sale proceeds can
        // fail it outright (board card 0031). Collected here rather than recomputed, so the row
        // marks, the banner and the year it starts all read the engine's own per-year warning.
        $referencingYears = [];
        $referencingMessage = null;
        $tenancyUpFront = null;
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

            // The two figures a reader actually plans against: capital in hand, and money per month.
            // {@see spendableFor} — derived there so results, Compare, /afford and the PDF cannot drift.
            $spendable = self::spendableFor($year);

            $failsReference = self::firstWarning($year, WarningCode::RENT_REFERENCING_FAILED);
            if ($failsReference !== null) {
                $referencingYears[] = $year->calendarYear;
                $referencingMessage ??= $failsReference;
            }
            $tenancyUpFront ??= self::firstWarning($year, WarningCode::TENANCY_UP_FRONT_COST);

            $rows[] = [
                'year' => $year->calendarYear,
                'ages' => implode(' / ', $year->ages),
                'income' => $income,
                'tax' => $year->totalTax->format(),
                'spend' => $year->spendTarget->format(),
                'essentialSpend' => $year->essentialSpend->format(),
                'discretionarySpend' => $discretionary->format(),
                'availableCapital' => $spendable['availableCapital'],
                'pensionCapital' => $spendable['pensionCapital'],
                'monthlyAllowance' => $spendable['monthlyAllowance'],
                'monthlyEssential' => $spendable['monthlyEssential'],
                'monthlyFree' => $spendable['monthlyFree'],
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
                // This year's income would not pass a letting agent's standard reference at this
                // rent. Marked per row because it can come and go: it is the income that moves.
                'failsReference' => $failsReference !== null,
            ];
        }

        return [
            'sources' => $active,
            'sourceLabels' => self::SOURCE_LABELS,
            'rows' => $rows,
            'showGrowth' => $showGrowth,
            // What holding the invested money cost over the whole plan, in today's money. Summed
            // from the per-year figures the projector attached, so it cannot drift from them.
            'showCharges' => ! $chargesTotal->isZero(),
            'chargesTotal' => $chargesTotal->format(),
            'finalYear' => $forecast->finalCalendarYear,
            // The safety-floor headline: the buffer (in months of essentials), the first year
            // usable money drops below it (null = never), and the first year it runs out entirely.
            'bufferMonths' => $bufferMonths,
            'floorBreachYear' => $floorBreachYear,
            'depletionYear' => $forecast->depletionCalendarYear,
            // Whether a landlord would GRANT this tenancy, which is a different question from
            // whether the money lasts and is not answered by the capital the sale frees. Null on
            // any plan that pays no rent. The sentence is the engine's own (it owns the multiple
            // and the pounds), quoted rather than restated so the two cannot drift.
            'rentReferencing' => $referencingMessage === null ? null : [
                'firstYear' => min($referencingYears),
                'years' => count($referencingYears),
                'message' => $referencingMessage,
            ],
            // What starting the tenancy costs on day one: the deposit charged here plus the first
            // month's rent, which the year's rent line already carries. Null when no rent is paid.
            'tenancyUpFront' => $tenancyUpFront,
        ];
    }

    /** @param  list<YearResult>  $years */
    private static function sourceOccurs(array $years, string $source): bool
    {
        foreach ($years as $year) {
            $money = $year->incomeBySource[$source] ?? null;
            if ($money instanceof Money && $money->isPositive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The validated 8-slot categorical palette (dataviz reference instance, light
     * surface, in stacking order — worst adjacent CVD ΔE 9.1) plus a neutral "Other"
     * grey. Used for the stacked-area time-series charts; the three sub-3:1 slots
     * (magenta / yellow / aqua) meet the relief rule via the <details> table twin every
     * chart ships. Assigned in slot order so adjacency stays CVD-safe.
     */
    private const SERIES_COLOURS = ['#2a78d6', '#008300', '#e87ba4', '#eda100', '#1baf7a', '#eb6834', '#4a3aa7', '#e34948'];

    private const OTHER_COLOUR = '#898781';

    /**
     * The three hero time-series charts (C1 income staircase, C2 wealth composition,
     * C3 costs over time), each a stacked-area ApexCharts blob over the deterministic
     * projection years plus a reconciling <details> table. Built from the SAME
     * {@see ForecastResult::$years} the cashflow {@see ladder()} reads, so a chart can
     * never drift from the ladder (one definition — asserted in the reconciliation test).
     *
     * Figures are REAL (today's money) by default, like the ladder and fan. Passing $nominal
     * switches all three charts and their tables to the pounds of the year itself, read from
     * {@see YearResult::$nominal} — the projector's OWN pre-deflation figures, never these real
     * ones re-inflated here (which would drift from the engine by the rounding the deflation
     * threw away). A forecast whose years carry no twin cannot offer the view at all: the
     * returned `nominalAvailable` is then false and the real figures come back, so a caller can
     * hide the toggle rather than label real money as nominal. Either way a stacked band is
     * always ≥ £0 (incomes, wealth legs and spend are each non-negative), so the axis anchors at
     * zero with no shortfall band. The C1 stack is capped at the palette's eight hues: if more
     * than eight income sources occur across the horizon, the smallest-contributing fold into a
     * neutral "Other" band on the CHART — the income table + CSV still list every source, so
     * nothing is silently dropped (completeness).
     *
     * @return array{income: array<string, mixed>, wealth: array<string, mixed>, costs: array<string, mixed>, nominal: bool, nominalAvailable: bool, basisLabel: string, basisShort: string}
     */
    public static function timeSeriesCharts(ForecastResult $forecast, bool $nominal = false): array
    {
        $twins = array_map(fn (YearResult $y): ?YearResult => $y->nominal, $forecast->years);
        $available = $forecast->years !== [] && ! in_array(null, $twins, true);
        $showNominal = $nominal && $available;
        /** @var list<YearResult> $years */
        $years = $showNominal ? array_values(array_filter($twins)) : $forecast->years;

        // The per-year age labels for the x-axis, straight from the engine's own per-year
        // ages (YearResult::ages), so the chart axis reads the same ages the ladder does.
        $ageByYear = [];
        foreach ($years as $year) {
            $ageByYear[$year->calendarYear] = implode(' / ', $year->ages);
        }

        // The axis suffix and the on-screen wording come from one place, so a chart can never
        // be drawn on one basis and captioned as the other.
        $basis = $showNominal ? 'cash £' : 'real £';

        return [
            'income' => self::incomeStaircase($years, $ageByYear, $basis),
            'wealth' => self::wealthComposition($years, $ageByYear, $basis),
            'costs' => self::costsOverTime($years, $ageByYear, $basis),
            'nominal' => $showNominal,
            'nominalAvailable' => $available,
            'basisLabel' => $showNominal
                ? 'the pounds of each year (what the money is called at the time)'
                : "today's money (every year on the same yardstick)",
            'basisShort' => $showNominal ? 'cash pounds' : 'real pounds',
        ];
    }

    /**
     * C1 — the income staircase: a stacked area of every income source that occurs, over
     * time, so the salary → DB → State-Pension → drawdown handover reads at a glance.
     *
     * @param  list<YearResult>  $years
     * @return array{options: array<string, mixed>, sources: list<string>, sourceLabels: array<string, string>, rows: list<array<string, mixed>>, folded: list<string>}
     */
    private static function incomeStaircase(array $years, array $ageByYear, string $basis): array
    {
        // Every source that pays out in some year, in canonical order (no silent drop).
        $active = array_values(array_filter(
            YearResult::INCOME_SOURCES,
            fn (string $source): bool => self::sourceOccurs($years, $source),
        ));

        // Cap the CHART at the eight palette hues: keep the eight largest by horizon-total
        // (canonical order preserved), fold any remainder into a single "Other" band. The
        // table below still carries every source, so completeness is never lost.
        $charted = $active;
        $folded = [];
        if (count($active) > count(self::SERIES_COLOURS)) {
            $totals = [];
            foreach ($active as $source) {
                $sum = Money::zero();
                foreach ($years as $year) {
                    $sum = $sum->plus($year->incomeBySource[$source] ?? Money::zero());
                }
                $totals[$source] = $sum->pence;
            }
            arsort($totals);
            $keep = array_slice(array_keys($totals), 0, count(self::SERIES_COLOURS));
            $charted = array_values(array_filter($active, fn (string $s): bool => in_array($s, $keep, true)));
            $folded = array_values(array_filter($active, fn (string $s): bool => ! in_array($s, $keep, true)));
        }

        $series = [];
        $colours = [];
        foreach ($charted as $i => $source) {
            $series[] = [
                'name' => self::SOURCE_LABELS[$source],
                'data' => array_map(
                    fn (YearResult $y): array => ['x' => $y->calendarYear, 'y' => self::pounds($y->incomeBySource[$source] ?? Money::zero())],
                    $years,
                ),
            ];
            $colours[] = self::SERIES_COLOURS[$i];
        }
        if ($folded !== []) {
            $series[] = [
                'name' => 'Other income',
                'data' => array_map(function (YearResult $y) use ($folded): array {
                    $sum = Money::zero();
                    foreach ($folded as $source) {
                        $sum = $sum->plus($y->incomeBySource[$source] ?? Money::zero());
                    }

                    return ['x' => $y->calendarYear, 'y' => self::pounds($sum)];
                }, $years),
            ];
            $colours[] = self::OTHER_COLOUR;
        }

        // The complete per-source table (every active source, canonical order) — the
        // accessible source of truth the chart is a progressive enhancement over. The total
        // is the sum of the sources (the stacked height), which spans more than the taxable
        // grossIncome (it includes tax-free cash, savings drawn and one-off receipts).
        $rows = array_map(function (YearResult $y) use ($active): array {
            $income = [];
            $total = Money::zero();
            foreach ($active as $source) {
                $amount = $y->incomeBySource[$source] ?? Money::zero();
                $income[$source] = $amount->format();
                $total = $total->plus($amount);
            }

            return ['year' => $y->calendarYear, 'ages' => implode(' / ', $y->ages), 'income' => $income, 'total' => $total->format()];
        }, $years);

        return [
            'options' => self::stackedArea($series, $colours, "Income ({$basis})", $ageByYear),
            'sources' => $active,
            'sourceLabels' => self::SOURCE_LABELS,
            'rows' => $rows,
            'folded' => $folded,
        ];
    }

    /**
     * C2 — where your wealth is: a stacked area of the three wealth legs (pension, liquid
     * savings, home equity) over time. The three sum to {@see YearResult::$totalWealth} by
     * construction, so the stack total is the net-worth line.
     *
     * @param  list<YearResult>  $years
     * @return array{options: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    private static function wealthComposition(array $years, array $ageByYear, string $basis): array
    {
        $legs = [
            ['name' => 'Pensions', 'get' => fn (YearResult $y): Money => $y->pensionWealth],
            ['name' => 'Savings & investments', 'get' => fn (YearResult $y): Money => $y->liquidWealth],
            ['name' => 'Home equity', 'get' => fn (YearResult $y): Money => $y->homeEquity()],
        ];

        $series = [];
        foreach ($legs as $leg) {
            $series[] = [
                'name' => $leg['name'],
                'data' => array_map(
                    fn (YearResult $y): array => ['x' => $y->calendarYear, 'y' => self::pounds(($leg['get'])($y))],
                    $years,
                ),
            ];
        }

        $rows = array_map(fn (YearResult $y): array => [
            'year' => $y->calendarYear,
            'ages' => implode(' / ', $y->ages),
            'pension' => $y->pensionWealth->format(),
            'liquid' => $y->liquidWealth->format(),
            'homeEquity' => $y->homeEquity()->format(),
            'total' => $y->totalWealth->format(),
        ], $years);

        return [
            'options' => self::stackedArea($series, array_slice(self::SERIES_COLOURS, 0, 3), "Wealth ({$basis})", $ageByYear),
            'rows' => $rows,
        ];
    }

    /**
     * C3 — costs over time: a stacked area of essential vs discretionary spend, so the
     * age-varying spending smile is legible. The two sum to {@see YearResult::$spendTarget}
     * (discretionary = target − essential, floored), the same split the ladder itemises.
     *
     * @param  list<YearResult>  $years
     * @return array{options: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    private static function costsOverTime(array $years, array $ageByYear, string $basis): array
    {
        $discretionary = fn (YearResult $y): Money => $y->spendTarget->minus($y->essentialSpend)->minZero();

        $series = [
            [
                'name' => 'Essential',
                'data' => array_map(fn (YearResult $y): array => ['x' => $y->calendarYear, 'y' => self::pounds($y->essentialSpend)], $years),
            ],
            [
                'name' => 'Discretionary',
                'data' => array_map(fn (YearResult $y): array => ['x' => $y->calendarYear, 'y' => self::pounds($discretionary($y))], $years),
            ],
        ];

        $rows = array_map(fn (YearResult $y): array => [
            'year' => $y->calendarYear,
            'ages' => implode(' / ', $y->ages),
            'essential' => $y->essentialSpend->format(),
            'discretionary' => $discretionary($y)->format(),
            'total' => $y->spendTarget->format(),
        ], $years);

        return [
            'options' => self::stackedArea($series, array_slice(self::SERIES_COLOURS, 0, 2), "Spending ({$basis})", $ageByYear),
            'rows' => $rows,
        ];
    }

    /**
     * A stacked-area ApexCharts option blob shared by the three time-series charts: money on
     * a £-abbreviated y-axis anchored at zero (the basis is named in $yTitle), the x-axis relabelled
     * with ages ({@see agesByYear}), a 1px surface stroke between bands (the marks spec's
     * surface gap), and the same `moneyAxis` / `ageByYear` flags {@see \resources\js\charts.js}
     * resolves client-side. Milestone x-axis annotations are merged in by the caller.
     *
     * @param  list<array<string, mixed>>  $series
     * @param  list<string>  $colours
     * @return array<string, mixed>
     */
    private static function stackedArea(array $series, array $colours, string $yTitle, array $ageByYear): array
    {
        return [
            'chart' => ['type' => 'area', 'stacked' => true, 'height' => 340, 'toolbar' => ['show' => false]],
            'colors' => $colours,
            'series' => $series,
            'dataLabels' => ['enabled' => false],
            'stroke' => ['curve' => 'straight', 'width' => 1, 'colors' => ['#ffffff']],
            'fill' => ['type' => 'solid', 'opacity' => 0.85],
            'markers' => ['size' => 0],
            'moneyAxis' => true,
            'ageByYear' => $ageByYear === [] ? null : $ageByYear,
            'xaxis' => ['type' => 'numeric', 'tickAmount' => 8, 'decimalsInFloat' => 0, 'title' => ['text' => 'Calendar year']],
            'yaxis' => ['min' => 0, 'forceNiceScale' => true, 'title' => ['text' => $yTitle]],
            'legend' => ['position' => 'top'],
        ];
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

            // State Pension start — the SPA computed from DOB (single source), pushed out by any
            // whole years of deferral, because a deferred pension is not received (nor shown) until
            // the later claim. The engine delays the paid income the same way, so the milestone and
            // the line agree.
            $spaYear = (int) StatePensionAge::for($person->dob)->dateReached->format('Y');
            $claimYear = $spaYear + self::statePensionDeferralYears($household, $person->id);
            $add($claimYear, $claimYear - $birthYear, "{$name}'s State Pension starts", 'state_pension');

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
    public static function inputNotes(Household $household, ForecastResult $forecast, ?HousingAction $housingAction = null, ?string $variant = null, ?AssumptionSet $set = null, ?ForecastSettings $settings = null): array
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
                // when a retirement age is simply left blank, leaving a person earning for life).
                $notes[] = ['kind' => 'no_retirement_age', 'text' => "{$name} has no retirement age set, so the forecast models them earning their salary indefinitely. Set a retirement age if their pay will stop."];
            }

            // (b) Modelled to die in the base year — what a longevity/health age below the
            // current age produces, since the engine floors a death age at the current age.
            $deathYear = $forecast->deathCalendarYears[$person->id] ?? null;
            if ($deathYear !== null && $deathYear <= $baseYear) {
                $notes[] = ['kind' => 'early_death', 'text' => "{$name} is modelled to die in {$deathYear} (age {$currentAge}), the very start of the forecast. If that isn't intended, check their longevity or health setting — a value below the current age is treated as the current age."];
            }

            // (b3) A disability benefit passports far more than it pays, and the forecast models
            // exactly ONE of those consequences. Everything else it unlocks is real money the plan
            // is not being credited with, so a reader comparing plans off these figures has to be
            // told to go and claim it rather than left to assume it is in there (factual, not advice).
            if ($person->receivesDisabilityBenefit) {
                $from = $person->disabilityBenefitFromAge;
                $when = $from === null
                    ? 'from the start of the forecast'
                    : "from age {$from}";
                $notes[] = ['kind' => 'disability_benefit_passports', 'text' => "{$name} is modelled as receiving a disability "
                    ."benefit (Attendance Allowance, DLA or PIP) {$when}. Two things follow, and only the first is in these "
                    .'figures. In the figures: the benefit itself, if you entered it as a tax-free income stream, and the '
                    .'Pension Credit severe-disability addition it opens (a couple both need a qualifying benefit for that; '
                    .'one on its own gets nothing), plus the carer addition if a partner is ticked as caring for them. NOT in '
                    .'the figures: everything a disability benefit or Pension Credit then passports. That list is Support for '
                    .'Mortgage Interest, Council Tax Reduction, the Warm Home Discount, a free TV licence at 75, Cold Weather '
                    .'Payments and help with NHS costs. Together those are usually worth more per year than the benefit, so '
                    .'treat this plan as the cautious version and claim each of them separately.'];
            }
        }

        // (b2) A DC pension being contributed to with no relief method set. Every real UK pension
        // gives tax relief by SOME method, so modelling none understates the pot and overstates the
        // tax bill — and it does so invisibly, since the reader has no reason to suspect the
        // contribution was charged in full. Naming it is the same discipline as the assumed-figure
        // disclosures: a modelling choice the user cannot see is indistinguishable from one we
        // invented. Only raised where it actually bites (a live contribution by a living member).
        foreach ($household->pensions as $pension) {
            if (! $pension instanceof DcPension
                || $pension->reliefMethod !== null
                || ! $pension->ongoingContribution->isPositive()) {
                continue;
            }
            $who = self::personLabel($household->person($pension->ownerId), 0);
            $notes[] = ['kind' => 'no_relief_method', 'text' => "No tax relief is modelled on {$who}'s pension contributions of {$pension->ongoingContribution->format()} a year, because the scheme's relief method hasn't been set — so the forecast charges the full cost and gives none of the tax back. Most workplace schemes use \"net pay\" (taken from gross salary); set it on the pension to model the relief."];
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
                MortgageMaturityAction::ForcedSale => "This home's mortgage of {$amount} is due for redemption in {$year} and is modelled as not refinanceable, so the home is sold that year: the equity left after the mortgage, selling costs and any CGT is freed into your investments, the mortgage and property costs stop, and rent begins (enter a rent so the sell-and-rent cost is modelled). You can still compare selling now, buying somewhere cheaper or letting it out as what-if scenarios on the Compare page.",
            };
            $notes[] = ['kind' => 'mortgage_redemption', 'text' => $text];
        }

        // (c2) An equity-release lifetime mortgage rolling up with no payments: the balance
        // compounds and is repaid from the estate, so state the projected end balance and what it
        // leaves behind — otherwise the (gross) total-wealth line would flatter a plan whose home
        // equity has quietly been consumed by the rolled-up interest (factual, not advice).
        $rollUp = $home?->mortgageRollUpRate;
        if ($home !== null && $rollUp !== null && $mortgage !== null && $mortgage->isPositive()) {
            $finalYear = $forecast->years[count($forecast->years) - 1];
            $rate = rtrim(rtrim(number_format($rollUp->asPercent(), 2), '0'), '.');
            $notes[] = ['kind' => 'lifetime_mortgage_rollup', 'text' => "This home is modelled as an equity-release lifetime mortgage rolling up at {$rate}% a year with no "
                ."payments: the {$mortgage->format()} balance compounds untouched and is repaid from the estate when the "
                .'home is finally sold (capped at the home’s value — you can never owe more than it). By '
                ."{$forecast->finalCalendarYear} it grows to about {$finalYear->mortgageBalance()->format()} in today’s money, "
                ."so of the home’s {$finalYear->propertyWealth->format()} only about {$finalYear->homeEquity()->format()} would "
                .'be left to inherit. Freeing the monthly payment helps the money last, but the rolled-up interest is what it '
                .'costs what you leave behind — compare this against servicing the interest to see the trade-off.'];
        }

        // (c2b) Support for Mortgage Interest, beside the equity-release note above because it is
        // the same trade on far better terms and the reader is otherwise comparing lifetime
        // mortgages against nothing. Nobody enters the rate or the cap, and both move the answer,
        // so they are READ from the constants that own them (the no-invisible-figures rule) rather
        // than restated here. Raised only where the projection actually met something: a household
        // that never reaches Guarantee Credit is told nothing, since the help would not be there.
        $charged = null;
        $firstCharge = null;
        foreach ($forecast->years as $year) {
            if ($year->smiBalance()->isPositive()) {
                $firstCharge ??= $year;
                $charged = $year;
            }
        }
        if ($firstCharge !== null && $charged !== null) {
            $smiRate = self::ratePct(SupportForMortgageInterest::standardRate()->asPercent());
            $cap = SupportForMortgageInterest::eligibleCapitalLimit()->format();
            $notes[] = ['kind' => 'support_for_mortgage_interest', 'text' => 'Because this plan reaches Pension Credit '
                ."Guarantee Credit while you still own the home, it takes Support for Mortgage Interest from {$firstCharge->calendarYear}: "
                ."the government meets the interest on your mortgage, up to the first {$cap} of it, at its own standard rate of "
                ."{$smiRate} a year, and for a pensioner it also covers your service charge and ground rent. In the first year that "
                ."is {$firstCharge->smiBalance()->format()} you no longer have to find. There is no waiting period at pension age. "
                .'It is a LOAN, not a payment: what is met is secured by a charge on your home and repaid when the home is sold or '
                ."when you die, and it rolls up in the meantime, reaching about {$charged->smiBalance()->format()} by "
                ."{$charged->calendarYear} in today's money, which comes out of what you leave behind. That is the same trade a "
                .'lifetime mortgage makes, at roughly a third of the interest rate, which is why it is worth reading the two side by '
                .'side. It is modelled here only while Guarantee Credit is actually in payment, and it is not modelled on a '
                .'rolled-up loan you pay no interest on, because there is then no interest for it to meet. You have to apply for '
                .'it and agree to the charge; nothing is paid automatically.'];
        }

        // (c3) An ordinary capital-and-interest mortgage: unlike the two shapes above, the balance
        // amortises to zero and the instalment is charged as an essential cost until it does. It is
        // usually the single biggest claim on the household's cashflow, and three of its properties
        // are counter-intuitive enough to state outright rather than leave implicit: the payment is
        // fixed in cash terms (so it costs less in today's money every year), it does NOT shrink
        // when one partner dies, and it stops dead at the end of the term (factual, not advice).
        $repaymentTerms = $home?->repaymentTerms;
        if ($home !== null && $repaymentTerms !== null && $mortgage !== null && $mortgage->isPositive()) {
            $schedule = AmortisationSchedule::for($mortgage, $repaymentTerms);
            $instalments = self::distinctInstalments($schedule);
            $years = (int) round($repaymentTerms->termMonths / 12);
            $clears = $schedule->finalPaymentYear();

            $payment = count($instalments) > 1
                ? "{$instalments[0]} a month, stepping to {$instalments[1]} when the fixed deal ends,"
                : "{$instalments[0]} a month,";

            $notes[] = ['kind' => 'repayment_mortgage', 'text' => 'This home is modelled as a repayment (capital and interest) mortgage of '
                ."{$mortgage->format()} over {$years} years. The instalment is {$payment} charged as an essential cost until the loan "
                ."clears in {$clears} — after which the home is owned outright and the payment stops. Over the term you repay "
                ."{$schedule->totalRepaid()->format()} in all, of which {$schedule->totalInterest()->format()} is interest. Two things "
                .'to read carefully: the instalment is fixed in cash terms, so it costs a little less in today’s money every year (that '
                .'is why the mortgage line shrinks down the ladder); and it does NOT fall if one of you dies — the survivor owes the '
                .'lender exactly the same amount out of a smaller income, which is usually where a later-life mortgage becomes '
                .'unaffordable. Lender fees and any early-repayment charge are not included here.'];
        }

        // (c4) A home that LOSES value. A bought home can carry a negative real growth rate (a park
        // home depreciates), and a reader's whole mental model of a home is that it appreciates — so
        // state it, with what the home is worth by the end. Without this the wealth line quietly
        // falls and looks like a bug, or worse, goes unnoticed (factual, not advice).
        $buyGrowth = $housingAction?->buyGrowthOverride;
        if ($buyGrowth !== null && $buyGrowth->basisPoints < 0 && $home !== null) {
            $finalYear = $forecast->years[count($forecast->years) - 1];
            $rate = rtrim(rtrim(number_format(abs($buyGrowth->asPercent()), 2), '0'), '.');
            $notes[] = ['kind' => 'home_depreciates', 'text' => "This home is modelled as LOSING value — {$rate}% a year "
                .'above inflation, rather than rising like an ordinary house. That is the realistic assumption for a '
                .'park home: they are built to a standard revised every 8–10 years, which makes an older one hard to '
                ."resell, and the site owner is entitled to up to 10% of the sale price. By {$forecast->finalCalendarYear} "
                ."it is worth about {$finalYear->propertyWealth->format()} in today's money. The lower running costs may "
                .'well be worth it while you live there — but the trade is that far less is left to inherit, so compare '
                .'this against a plan that keeps bricks-and-mortar before deciding. The 10% sale commission is not '
                .'included in these figures.'];
        }

        // (c4b) LETTING CAVEATS. Card 0030: what this tool does and does not model about letting a
        // home used to live in a docblock, where the person reading the forecast could not see it.
        // Three of the gaps are large enough to decide the plan on their own, so they are stated on
        // the result: a minimum-energy-efficiency retrofit is a five-figure bill nobody has costed,
        // a lease usually forbids subletting without the freeholder's consent (so the plan may not
        // be available at all), and the council tax on the let flat is still charged to the
        // household although a tenant normally pays it. Factual, not advice.
        if ($home !== null && $home->isLet) {
            $notes[] = ['kind' => 'letting_caveats', 'text' => 'This plan lets your home out, and three things about that '
                .'are not in the figures. First, your lease: most leases need the freeholder\'s written consent before '
                .'you sublet and some forbid it outright, and a licence to sublet usually costs a fee, so check the '
                .'lease before you count on this plan at all. Second, energy efficiency: a let home has to meet a '
                .'minimum standard, and the proposed rise to EPC C by 2030 would put either a five-figure retrofit or '
                .'a formal exemption application in front of you, and neither is costed here. Third, council tax: we '
                .'keep charging you this home\'s running costs in full, council tax included, although a tenant '
                .'normally pays that, so the running costs here are on the cautious side. Two consequences of letting '
                .'ARE in the figures: the equity stops counting as your exempt main home for Pension Credit, and time '
                .'spent let reduces the Private Residence Relief on a later sale.'];
        }

        // (c5) ASSUMED FIGURES. Standing rule (Rob, 2026-07-30): the model must never use a figure
        // the user cannot see or interrogate. Where an input is left blank the engine supplies a
        // sensible default for itself — and until this note existed, two of them (a bought home's
        // upkeep and the cost of moving) silently moved the result with nothing on any screen to
        // show for it. Every such figure is enumerated here, with its value and why it applies, so a
        // reader can challenge it. Each value is READ from the one place that owns it, never restated.
        foreach (self::assumedFigures($household, $housingAction, $forecast, $variant, $set, $settings) as $assumed) {
            $notes[] = ['kind' => 'assumed_figure', 'text' => $assumed];
        }

        // (c5a) COMPUTED FIGURES. The sibling of the rule above, for a number the model WORKED OUT
        // from what the reader did enter rather than invented for itself (board card 0033). It is
        // not an assumption to challenge, but on a screen it reads exactly like user input, so the
        // rule that produced it has to be stated or the reader cannot check it. The one case is the
        // bought home's upkeep when the current home has upkeep of its own: it is scaled by the two
        // prices, and the 1%-of-value fallback beside it was disclosed while this was not.
        $currentUpkeep = $household->primaryResidence?->runningCosts;
        if ($housingAction?->buyPrice !== null && $housingAction->buyPrice->isPositive()
            && $housingAction->buyRunningCosts === null
            && $currentUpkeep !== null && $currentUpkeep->isPositive()
            && ! $housingAction->salePrice->isZero()) {
            // READ from the engine, never recomputed here, so the sentence cannot drift from the
            // figure the projection actually charges.
            $upkeep = HousingComparison::newHomeRunningCosts($household, $housingAction, $housingAction->buyPrice);
            $notes[] = ['kind' => 'computed_figure', 'text' => "We worked out the upkeep of the home you'd buy rather than "
                ."being told it: {$upkeep->format()} a year, charged as an essential cost for the whole plan. The rule is "
                ."your current home's {$currentUpkeep->format()} a year scaled by the two prices ({$housingAction->buyPrice->format()} "
                ."to buy against {$housingAction->salePrice->format()} to sell), on the reading that a cheaper home costs less "
                .'to keep. That is a guess about a property you have not chosen: maintenance, insurance and council tax do not '
                .'really track value, and a cheap flat can carry a service charge a costlier house never would. Enter the real '
                .'figure once you know it.'];
        }

        // (c6) A one-off CAPITAL lump the plan cannot fund in the year it falls: the unfunded part
        // of a home purchase, or a mortgage the plan redeems from capital it does not have. It used
        // to show only as a depressed full-spending probability — and because a year-0 purchase gap
        // is the same constant on every sampled path, that probability read exactly 0%, which says
        // "this plan never works" about a plan whose year-to-year spending is met throughout. Name
        // the cost and its size instead. The sentence is the ENGINE's own (it owns the figure the
        // projection charged), quoted rather than restated, so the two cannot drift.
        foreach ($forecast->years as $year) {
            foreach ($year->warnings as $warning) {
                if ($warning->code === WarningCode::UNFUNDED_ONE_OFF_COST) {
                    $notes[] = ['kind' => 'unfunded_one_off', 'text' => "In {$year->calendarYear}: {$warning->message}"];
                }
            }
        }

        // (c7) The CAPITAL CLIFF: savings above the Housing Benefit / Council Tax Support limit
        // end both. The engine has always built this warning and nothing ever collected it, while
        // METHODOLOGY.md told the reader it was flagged, so every sell-and-rent plan parked a large
        // sum and showed nothing (board card 0046). Reported ONCE, on the first year it bites: it
        // then holds for most of the plan, and a note repeated forty times is a note nobody reads.
        // The sentence is the ENGINE's, quoted rather than restated, so the two cannot drift.
        foreach ($forecast->years as $year) {
            $cliff = self::firstWarning($year, WarningCode::CAPITAL_CLIFF_HB_CTS);
            if ($cliff !== null) {
                $notes[] = ['kind' => 'capital_cliff', 'text' => "From {$year->calendarYear}: {$cliff}"];
                break;
            }
        }

        // (d) Cohabiting-couple survivor caveats. The married/civil-partner survivor rights the
        // engine implicitly assumes do NOT extend to a cohabiting partner, so flag where the
        // forecast may overstate what the survivor actually receives (no silent overstatement).
        if (count($household->persons) === 2 && $household->relationshipStatus === RelationshipStatus::Cohabiting) {
            $hasSurvivorDb = false;
            foreach ($household->pensions as $pension) {
                if ($pension instanceof DbPension && $pension->spousePensionFraction !== null && $pension->spousePensionFraction->basisPoints > 0) {
                    $hasSurvivorDb = true;
                    break;
                }
            }
            if ($hasSurvivorDb) {
                $notes[] = ['kind' => 'cohabiting_db_survivor', 'text' => "You're modelled as cohabiting (not married or in a civil partnership). This forecast pays a defined-benefit survivor's pension to the surviving partner, but many schemes pay a survivor's pension only to a spouse or civil partner (some pay a nominated cohabitant). Check the scheme rules — if it wouldn't be paid, the survivor's secure income is overstated."];
            }

            // State Pension inheritance is a spouse/civil-partner right this tool does not model for
            // anyone; a cohabiting survivor could not inherit any State Pension in any case.
            $notes[] = ['kind' => 'cohabiting_state_pension', 'text' => 'A cohabiting partner cannot inherit any State Pension (that right is for a spouse or civil partner only), and this tool does not model inheriting State Pension for anyone: each person\'s State Pension stops on their death.'];
        }

        // Spending "smile": spend varies with age rather than staying flat. Surface it so the
        // later-year change reads as intended, not as a bug, and name whose age it steps on.
        $profile = $household->expenseProfile;
        if ($profile->hasSmile()) {
            $bandAges = [];
            foreach ([...$profile->essentialSpendPath->bands, ...$profile->discretionarySpendPath->bands] as $band) {
                if ($band['fromAge'] > 0) {
                    $bandAges[] = $band['fromAge'];
                }
            }
            if ($bandAges !== []) {
                $lateAge = max($bandAges);
                $start = $profile->targetAnnualSpend();
                $late = $profile->targetAnnualSpendAt($lateAge);
                $direction = $late->lessThan($start) ? 'steps down' : ($late->greaterThan($start) ? 'steps up' : 'changes');
                $refName = self::personLabel($household->persons[0], 0);
                $notes[] = ['kind' => 'spending_smile', 'text' => "Your spending changes with age (a \"smile\"): the forecast {$direction} your spend with age rather than holding it flat, from {$start->format()} a year now to {$late->format()} by age {$lateAge} (today's money). The age steps track {$refName}."];
            }
        }

        // Above-CPI growth on the home-ownership cost lines: surface that those costs climb in
        // real terms year on year (the later-year squeeze reads as intended, not as a bug).
        $propertyGrowth = $profile->propertyCostsRealGrowth();
        if ($propertyGrowth->asFraction() > 0.0 && $profile->propertyCosts()->isPositive()) {
            $rate = rtrim(rtrim(number_format($propertyGrowth->asPercent(), 2), '0'), '.');
            $notes[] = ['kind' => 'property_costs_growth', 'text' => "Home-ownership costs (service charge, ground rent, levies — {$profile->propertyCosts()->format()} a year today) are modelled rising {$rate}% a year above inflation while you own the home, so they climb in real terms over the projection."];
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
     * Whole years a person defers their State Pension (0 if none): the deferral weeks on their
     * entitlement rounded to whole years, matching the engine's annual claim-year shift so the
     * milestone lands on the same year the paid income starts. DWP annualises at 52 weeks.
     */
    private static function statePensionDeferralYears(Household $household, string $personId): int
    {
        foreach ($household->pensions as $pension) {
            if ($pension instanceof StatePensionEntitlement && $pension->ownerId === $personId && $pension->deferralWeeks > 0) {
                return (int) round($pension->deferralWeeks / 52);
            }
        }

        return 0;
    }

    /**
     * The income-floor readout: essential spending vs secure (guaranteed-for-life,
     * non-pot) income, taken at the last year everyone is still alive — by then every
     * guaranteed source that ever starts is in payment and any salary has ended, so it
     * is the household's mature floor. Reports the coverage factually (a percentage and
     * the surplus or gap); it never says whether that is enough (no recommendation).
     *
     * The all-alive floor understates the binding risk on a couple: at the FIRST death a
     * State Pension is lost and a defined-benefit pension may drop to its survivor fraction,
     * while essential spending falls only by the survivor factor — so the survivor's coverage
     * can drop off a cliff this mature snapshot hides. So the readout also carries a
     * **survivor-year twin** (`survivor`) computed the same way at the deepest survivor year,
     * and the coverage `cliff` between the two (Phase 4, the survivor-cliff story). Null twin
     * when there is no survivor phase (a single person, or no death within the horizon).
     *
     * Returns null when the projection has no years to read.
     *
     * @return array{year: int, ages: string, essentialSpend: string, secureIncome: string, sources: list<array{label: string, amount: string}>, contingent: list<array{label: string, amount: string}>, contingentIncome: string, coveragePct: int, surplus: ?string, gap: ?string, fullyCovered: bool, survivor: array{year: int, ages: string, essentialSpend: string, secureIncome: string, sources: list<array{label: string, amount: string}>, contingent: list<array{label: string, amount: string}>, contingentIncome: string, coveragePct: int, surplus: ?string, gap: ?string, fullyCovered: bool}|null, cliff: ?int}|null
     */
    public static function incomeFloor(ForecastResult $forecast): ?array
    {
        $mature = self::matureSnapshot($forecast);
        if ($mature === null) {
            return null;
        }

        $floor = self::floorAt($mature);

        $survivorYear = self::survivorSnapshot($forecast);
        $survivor = $survivorYear === null ? null : self::floorAt($survivorYear);

        return $floor + [
            'survivor' => $survivor,
            // The cliff: how many points of secure-income coverage of essentials are lost at the
            // first death (signed — negative would mean the survivor's coverage actually rises).
            'cliff' => $survivor === null ? null : $floor['coveragePct'] - $survivor['coveragePct'],
        ];
    }

    /**
     * The essentials-vs-secure-income floor at one projected year: which guaranteed-for-life
     * sources are in payment, how much they total, and how far they cover essentials. The single
     * definition both the all-alive floor and the survivor-year twin read, so the two can only
     * differ by their year, never by how the figure is built.
     *
     * @return array{year: int, ages: string, essentialSpend: string, secureIncome: string, sources: list<array{label: string, amount: string}>, contingent: list<array{label: string, amount: string}>, contingentIncome: string, coveragePct: int, surplus: ?string, gap: ?string, fullyCovered: bool}
     */
    private static function floorAt(YearResult $year): array
    {
        $collect = static function (array $codes, ?Money &$total) use ($year): array {
            $lines = [];
            $total = Money::zero();
            foreach ($codes as $source) {
                $money = $year->incomeBySource[$source] ?? Money::zero();
                if ($money->isPositive()) {
                    $lines[] = ['label' => self::SOURCE_LABELS[$source], 'amount' => $money->format()];
                    $total = $total->plus($money);
                }
            }

            return $lines;
        };

        $sources = $collect(self::SECURE_SOURCES, $secure);
        $contingent = $collect(self::CONTINGENT_SOURCES, $contingentTotal);

        $essential = $year->essentialSpend;
        $shortfall = $essential->minus($secure);
        $surplus = $secure->minus($essential);
        $coverage = $essential->isPositive() ? (int) round($secure->pence / $essential->pence * 100) : 100;

        return [
            'year' => $year->calendarYear,
            'ages' => implode(' / ', $year->ages),
            'essentialSpend' => $essential->format(),
            'secureIncome' => $secure->format(),
            'sources' => $sources,
            // Reported beside the floor, deliberately outside every total above: contingent income
            // has to be visible (it is real money the forecast spends) without being counted as a
            // guarantee. See CONTINGENT_SOURCES.
            'contingent' => $contingent,
            'contingentIncome' => $contingentTotal->format(),
            'coveragePct' => $coverage,
            'surplus' => $surplus->isPositive() ? $surplus->format() : null,
            'gap' => $shortfall->isPositive() ? $shortfall->format() : null,
            'fullyCovered' => ! $shortfall->isPositive(),
        ];
    }

    /**
     * The deepest survivor year: the last projected year in which at least one person has died
     * AND at least one still lives (for a couple, the last year exactly one survives) — fully
     * mature for the survivor, every guaranteed source in payment. Null for a single-person
     * household or when no death falls within the horizon (no survivor phase to read).
     */
    private static function survivorSnapshot(ForecastResult $forecast): ?YearResult
    {
        $snapshot = null;
        foreach ($forecast->years as $year) {
            $total = count($year->ages);
            if ($total >= 2 && $year->aliveCount >= 1 && $year->aliveCount < $total) {
                $snapshot = $year;
            }
        }

        return $snapshot;
    }

    /**
     * How to actually claim Pension Credit. Pension Credit is means-tested (so it has to be
     * applied for, never automatic) and one of the most under-claimed benefits, so modelling
     * it as income without saying how to get it would leave money on the table. Factual
     * gov.uk signposting, not advice: the amount is means-tested and only the DWP can confirm
     * entitlement.
     *
     * TWO ways in, because keying it off a positive award alone was backwards (board card 0046):
     * the household sitting just above the line is the one a caseworker most wants a nil claim
     * from, and it was shown nothing at all. So a year the engine flagged as a near miss
     * ({@see WarningCode::PENSION_CREDIT_NEAR_MISS}) opens the prompt too, carrying the engine's
     * own sentence about why rather than restating its rule. Null only when no year is awarded
     * anything AND no year comes close (nothing to claim, and no noise).
     *
     * @return array{awarded: bool, nearMiss: list<string>, howToClaim: list<string>, passports: list<string>, source: string, verifiedOn: string}|null
     */
    public static function pensionCreditGuidance(ForecastResult $forecast): ?array
    {
        $received = false;
        $nearMiss = [];
        foreach ($forecast->years as $year) {
            if (($year->incomeBySource['means_tested_benefit'] ?? Money::zero())->isPositive()) {
                $received = true;
            }
            $message = self::firstWarning($year, WarningCode::PENSION_CREDIT_NEAR_MISS);
            if ($message !== null && $nearMiss === []) {
                $nearMiss[] = "In {$year->calendarYear}: {$message}";
            }
        }
        if (! $received && $nearMiss === []) {
            return null;
        }

        return [
            'awarded' => $received,
            'nearMiss' => $nearMiss,
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
     * A REPAYMENT MORTGAGE is the one cost that is not a form-state line: its instalment is computed
     * from the mortgage terms by {@see AmortisationSchedule}, and the "Mortgage" line is deliberately
     * zeroed so the two cannot double-count ({@see spendableFor} and DECISIONS 2026-07-29). Echoing
     * that £0 back unqualified reads as "the mortgage is not being charged" — which is wrong and was
     * mistaken for a bug in review. So when terms are set, the zeroed line is REPLACED by the
     * schedule's own first-full-year instalment, marked `computed`, and it counts in the subtotals.
     * The panel then totals what the household will actually pay, not just what it typed in.
     *
     * @param  array<string, mixed>  $state  the effective builder form-state
     * @return array{tiers: list<array{key: string, label: string, lines: list<array{label: string, amount: string, saved: bool, computed: bool}>, subtotal: string}>, spendingTotal: string, savingTotal: string, total: string, hasSaving: bool}
     */
    public static function expenseBreakdown(array $state, ?Household $household = null): array
    {
        $lines = $state['expenseLines'] ?? [];
        if ($lines === []) {
            $lines = self::flatFallbackLines($state['expense'] ?? []);
        }

        // The engine-computed mortgage instalment, when the home carries repayment terms. Taken at
        // the first FULL year of the term (the first calendar year can be a part year — a mortgage
        // completing in September pays four instalments, which is not the annual budget figure).
        $home = $household?->primaryResidence;
        $instalment = null;
        if ($home?->repaymentTerms !== null) {
            $terms = $home->repaymentTerms;
            $schedule = AmortisationSchedule::for($home->outstandingMortgage ?? Money::zero(), $terms);
            $instalment = $schedule->paymentIn($terms->firstPaymentMonth === 1
                ? $terms->firstPaymentYear
                : $terms->firstPaymentYear + 1);
        }

        $tiers = [];
        $spending = Money::zero();
        $spendingMonthly = Money::zero();
        $saving = Money::zero();
        $savingMonthly = Money::zero();
        foreach (self::EXPENSE_TIERS as $key => $label) {
            $tierLines = [];
            $subtotal = Money::zero();
            $subtotalMonthly = Money::zero();
            foreach ($lines as $line) {
                // The tier is read through the assembler's rule, not off the line, so cover of the
                // home is shown under Essentials exactly where the projection charges it. A
                // breakdown grouping by the stored category would understate the floor it prints.
                if (HouseholdAssembler::tierOf($line) !== $key) {
                    continue;
                }
                $amount = Money::fromPence(MoneyText::toPence((string) ($line['amount'] ?? '0')));
                $saved = $key === 'self_investment' && (bool) ($line['savedAsAsset'] ?? false);

                // Substitute the schedule's instalment for the zeroed "Mortgage" line, so the panel
                // shows the payment the projection actually charges.
                //
                // NOTE the variable name: this MUST NOT be `$label`, which is the enclosing loop's
                // TIER name ("Essentials", "Nice to haves"). Assigning to it here silently retitled
                // every tier with its own last line — "Essentials" became "Commute Fuel" — a bug
                // that shipped because the reconciliation tests only checked amounts.
                $lineLabel = (string) ($line['label'] ?? '');
                $computed = false;
                if ($instalment !== null && self::isMortgageLine($lineLabel)) {
                    $amount = $instalment;
                    $computed = true;
                    $instalment = null; // only ever substitute onto one line
                }

                // The monthly twin of every annual figure. Rounded per LINE and then summed into
                // the subtotals and totals, never divided again at the top — so the monthly
                // column adds up exactly as printed. (Dividing each total by 12 independently
                // lets the column disagree with its own parts by a penny or two, which is the
                // reconciliation rule this project treats as a defect, not a rounding detail.)
                $monthly = $amount->dividedBy(12);

                $tierLines[] = [
                    'label' => $lineLabel,
                    'amount' => $amount->format(),
                    'amountMonthly' => $monthly->format(),
                    'saved' => $saved,
                    'computed' => $computed,
                ];
                $subtotal = $subtotal->plus($amount);
                $subtotalMonthly = $subtotalMonthly->plus($monthly);
                // Saved self-investment builds net worth (a contribution), not spend; all
                // else is spend. One home per pound — exactly mirrors the assembler.
                if ($saved) {
                    $saving = $saving->plus($amount);
                    $savingMonthly = $savingMonthly->plus($monthly);
                } else {
                    $spending = $spending->plus($amount);
                    $spendingMonthly = $spendingMonthly->plus($monthly);
                }
            }
            if ($tierLines !== []) {
                $tiers[] = [
                    'key' => $key,
                    'label' => $label,
                    'lines' => $tierLines,
                    'subtotal' => $subtotal->format(),
                    'subtotalMonthly' => $subtotalMonthly->format(),
                ];
            }
        }

        return [
            'tiers' => $tiers,
            'spendingTotal' => $spending->format(),
            'spendingTotalMonthly' => $spendingMonthly->format(),
            'savingTotal' => $saving->format(),
            'savingTotalMonthly' => $savingMonthly->format(),
            'total' => $spending->plus($saving)->format(),
            'totalMonthly' => $spendingMonthly->plus($savingMonthly)->format(),
            'hasSaving' => $saving->isPositive(),
        ];
    }

    /**
     * The income-and-capital counterpart to {@see expenseBreakdown}: what the household has
     * coming IN, where the capital it can draw on actually sits, and how each source turns on
     * and off across the projection.
     *
     * The spending side was echoed back to the reader from the day the budget panel shipped;
     * the income side never was, so a reader could see what a plan spends but not what funds
     * it, nor when a salary stops and a pension starts. Three parts:
     *
     *  - `income`   the entered sources — salary, DB, State Pension, annuity/rental/other,
     *               one-off receipts — each with its own start and stop, per person;
     *  - `capital`  where the money is: cash / ISA / GIA / premium bonds, DC pension pots and
     *               home equity, with what is paid in each year and how it is taxed on the way
     *               out (£1 of pension is not £1 in the hand);
     *  - `timeline` how each source actually behaves in the projection — the first and last
     *               year it pays, the amount at each end, and its largest year. Derived from
     *               the SAME `incomeBySource` the ladder and the income chart read, so the
     *               three cannot disagree; a source that never pays is omitted, not shown as
     *               a row of zeroes.
     *
     * Monthly twins are rounded per row and summed, so the monthly column adds up as printed.
     *
     * @return array<string, mixed>
     */
    public static function incomePlan(Household $household, ForecastResult $forecast): array
    {
        $baseYear = $forecast->years[0]->calendarYear ?? (int) date('Y');

        $income = [];
        $capital = [];

        foreach ($household->persons as $i => $person) {
            $who = self::personLabel($person, $i);
            $birthYear = (int) $person->dob->format('Y');
            $yearOfAge = static fn (?int $age): ?int => $age === null ? null : $birthYear + $age;

            // Salary, until the planned retirement age (or indefinitely if none is set — the
            // foot-gun the input notes already flag, stated plainly here too).
            if ($person->grossSalary !== null && $person->grossSalary->isPositive()
                && in_array($person->employmentStatus, [EmploymentStatus::Employed, EmploymentStatus::SelfEmployed], true)) {
                $retireYear = $yearOfAge($person->plannedRetirementAge);
                $income[] = self::incomeRow(
                    'Salary', $who, $person->grossSalary, true,
                    'now',
                    $retireYear === null
                        ? 'no retirement age set — modelled indefinitely'
                        : "retires at {$person->plannedRetirementAge} ({$retireYear})",
                );
            }

            foreach ($household->pensions as $pension) {
                if ($pension->ownerId !== $person->id) {
                    continue;
                }

                if ($pension instanceof DbPension) {
                    $from = $yearOfAge($pension->normalRetirementAge);
                    $income[] = self::incomeRow(
                        'DB pension', $who, $pension->accruedAnnualPension, true,
                        "age {$pension->normalRetirementAge} ({$from})", 'for life',
                    );
                }

                if ($pension instanceof StatePensionEntitlement && $pension->weeklyForecast !== null) {
                    $spa = StatePensionAge::for($person->dob)->dateReached;
                    $claimYear = (int) $spa->format('Y') + intdiv($pension->deferralWeeks, 52);
                    $income[] = self::incomeRow(
                        'State Pension', $who, $pension->weeklyForecast->times(52), true,
                        // Already in payment for someone past State Pension age: "age 66 (2012)"
                        // reads as a future event for an 80-year-old already drawing it.
                        $claimYear <= $baseYear
                            ? 'now (in payment)'
                            : 'State Pension age ('.$claimYear.')'.($pension->deferralWeeks > 0 ? ', deferred' : ''),
                        'for life',
                    );
                }

                if ($pension instanceof DcPension) {
                    $capital[] = [
                        'label' => 'Pension pot',
                        'who' => $who,
                        'balance' => $pension->currentValue->format(),
                        'paidIn' => $pension->ongoingContribution->plus($pension->employerContribution)->isPositive()
                            ? $pension->ongoingContribution->plus($pension->employerContribution)->format().' a year'
                            : '—',
                        'access' => "from age {$pension->earliestAccessAge}",
                        'tax' => '25% tax-free, the rest taxed as income when drawn',
                    ];
                }
            }

            foreach ($household->incomeStreams as $stream) {
                if ($stream->ownerId !== $person->id) {
                    continue;
                }
                $income[] = self::incomeRow(
                    self::INCOME_STREAM_LABELS[$stream->type->value] ?? 'Other income',
                    $who,
                    $stream->grossAnnual,
                    $stream->taxable,
                    $stream->startAge <= ($baseYear - $birthYear) ? 'now' : "age {$stream->startAge} (".$yearOfAge($stream->startAge).')',
                    $stream->endAge === null ? 'for life' : "age {$stream->endAge} (".$yearOfAge($stream->endAge).')',
                );
            }
        }

        foreach ($household->capitalReceipts as $receipt) {
            $income[] = self::incomeRow(
                'One-off receipt: '.$receipt->label,
                self::ownerLabelFor($household, $receipt->ownerId),
                $receipt->amount, false,
                (string) $receipt->calendarYear, 'one-off',
            );
        }

        foreach ($household->accounts as $account) {
            $capital[] = [
                'label' => self::ACCOUNT_LABELS[$account->type->value] ?? 'Savings',
                'who' => self::ownerLabelFor($household, $account->ownerId),
                'balance' => $account->balance->format(),
                'paidIn' => $account->ongoingContributions?->isPositive()
                    ? $account->ongoingContributions->format().' a year'
                    : '—',
                'access' => 'any time',
                'tax' => self::ACCOUNT_TAX[$account->type->value] ?? '',
            ];
        }

        if ($household->primaryResidence !== null) {
            $home = $household->primaryResidence;
            $capital[] = [
                'label' => 'Home equity',
                'who' => 'household',
                'balance' => $home->currentValue->minus($home->outstandingMortgage ?? Money::zero())->format(),
                'paidIn' => '—',
                'access' => 'only by selling or borrowing against it',
                'tax' => 'no CGT on a main home; not spendable while you live in it',
            ];
        }

        return [
            'income' => $income,
            'capital' => $capital,
            'timeline' => self::incomeTimeline($forecast),
            'baseYear' => $baseYear,
            // No cash / ISA / GIA entered at all is a materially different position from "we
            // didn't list them": the plan then starts with nothing to draw on, and any liquid
            // wealth in the projection is surplus income accumulating. Say which it is rather
            // than printing an empty table the reader has to interpret.
            'hasSavings' => $household->accounts !== [],
        ];
    }

    /** One entered income source, with its annual and (per-row rounded) monthly figure. */
    private static function incomeRow(string $label, string $who, Money $annual, bool $taxable, string $from, string $until): array
    {
        return [
            'label' => $label,
            'who' => $who,
            'annual' => $annual->format(),
            'monthly' => $annual->dividedBy(12)->format(),
            'taxable' => $taxable,
            'from' => $from,
            'until' => $until,
        ];
    }

    private static function ownerLabelFor(Household $household, string $ownerId): string
    {
        foreach ($household->persons as $i => $person) {
            if ($person->id === $ownerId) {
                return self::personLabel($person, $i);
            }
        }

        return 'household';
    }

    /**
     * How each income source actually behaves across the projection: when it starts paying,
     * when it stops, what it pays at each end and in its biggest year. Read from the engine's
     * own per-year `incomeBySource`, so it reconciles cell-for-cell with the cashflow ladder
     * and the income chart rather than restating the inputs.
     *
     * @return list<array<string, mixed>>
     */
    private static function incomeTimeline(ForecastResult $forecast): array
    {
        $rows = [];

        foreach (YearResult::INCOME_SOURCES as $source) {
            $first = null;
            $last = null;
            $peak = null;

            foreach ($forecast->years as $year) {
                $amount = $year->incomeBySource[$source] ?? Money::zero();
                if (! $amount->isPositive()) {
                    continue;
                }
                $first ??= ['year' => $year->calendarYear, 'amount' => $amount];
                $last = ['year' => $year->calendarYear, 'amount' => $amount];
                if ($peak === null || $amount->greaterThan($peak['amount'])) {
                    $peak = ['year' => $year->calendarYear, 'amount' => $amount];
                }
            }

            // A source that never pays is left out entirely — a row of zeroes tells the reader
            // nothing and buries the ones that matter.
            if ($first === null) {
                continue;
            }

            $rows[] = [
                'label' => self::SOURCE_LABELS[$source],
                'firstYear' => $first['year'],
                'firstAmount' => $first['amount']->format(),
                'lastYear' => $last['year'],
                'lastAmount' => $last['amount']->format(),
                'peakYear' => $peak['year'],
                'peakAmount' => $peak['amount']->format(),
                'endsBeforeTheEnd' => $last['year'] < $forecast->finalCalendarYear,
            ];
        }

        return $rows;
    }

    /** Display names for the entered income-stream types. */
    private const INCOME_STREAM_LABELS = [
        'rental' => 'Rental income',
        'annuity' => 'Annuity',
        'disability_benefit' => 'Disability benefit',
        'other' => 'Other income',
    ];

    /** Display names for the capital pots. */
    private const ACCOUNT_LABELS = [
        'isa' => 'ISA',
        'gia' => 'General investment account',
        'cash' => 'Cash savings',
        'premium_bonds' => 'Premium Bonds',
    ];

    /** How each pot is taxed on the way out — the reason £1 in one is not £1 in another. */
    private const ACCOUNT_TAX = [
        'isa' => 'tax-free',
        'gia' => 'capital gains tax may apply when sold; income taxed each year',
        'cash' => 'interest taxed each year',
        'premium_bonds' => 'prizes tax-free',
    ];

    /**
     * The house-sale explainer: a plain decomposition of what selling the current home
     * actually yields, and where the money goes. It surfaces the engine's single-source
     * breakdown objects so the headline "we'd get ~£X" traces to its parts and reconciles:
     *
     *  - the proceeds waterfall: sale price − outstanding mortgage − selling costs − CGT
     *    (£0 on a main home via PRR in v1) = net proceeds;
     *  - if selling and renting: the full net proceeds are invested;
     *  - if selling and buying: net − buy price − SDLT − moving = the surplus invested; a buy
     *    above the proceeds is funded from documented sources only (a capital receipt arriving
     *    that year, then savings drawn, then an interest-only mortgage), and anything left is
     *    reported as the unfunded gap;
     *  - and the assumption the invested money then grows at (the blended real return), with
     *    a share paid out each year as taxable income (the income yield) rather than sitting idle.
     *
     * The selling-cost percentage is shown beside the £ figure so an out-of-range rate is
     * visible (e.g. a 20% entry showing as 20% of the sale price), not buried.
     *
     * Returns null when no sale is configured (sale price zero) — e.g. a stay-put plan — so
     * the section simply does not render. Factual throughout, never a recommendation.
     *
     * @return array{sellingCostsAssumed: bool, sellingCostBreakdown: list<array{label: string, value: string, detail: ?string}>, cgtDetail: ?array{gain: string, relievedGain: string, chargeableGain: string, allowanceUsed: string, taxableGain: string, ratePct: string}, proceeds: array{salePrice: string, mortgage: string, hasMortgage: bool, sellingCosts: string, cgt: string, cgtCharged: bool, netProceeds: string, clearsCosts: bool}, rent: array{invested: string, annualRent: ?string}, buy: ?array{netProceeds: string, buyPrice: string, sdlt: string, movingCosts: string, surplus: string, coversPurchase: bool, isFullyFunded: bool, fundedFromReceipts: ?string, fundedFromSavings: ?string, mortgage: ?string, mortgageInterest: ?string, unfundedGap: ?string}, blendedReturnPct: string, incomeYieldPct: string}|null
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
            // Sell & buy: only when a buy price is set (otherwise the plan is rent-only). Every
            // pound of the purchase traces to a documented source (the engine's funding
            // waterfall): the net proceeds, then savings drawn (cash → GIA → ISA), then an
            // interest-only mortgage — and anything left is the `unfundedGap`, money the plan
            // does NOT have, which the forecast charges as a year-0 cost so the plan visibly
            // fails rather than being handed the home for free.
            'buy' => $purchase->buyPrice->isPositive() ? [
                'netProceeds' => $purchase->netProceeds->format(),
                'buyPrice' => $purchase->buyPrice->format(),
                'sdlt' => $purchase->stampDuty->format(),
                'movingCosts' => $purchase->movingCosts->format(),
                'surplus' => $purchase->surplus->format(),
                'coversPurchase' => $purchase->coversPurchase(),
                'isFullyFunded' => $purchase->isFullyFunded(),
                'fundedFromReceipts' => $purchase->fundedFromReceipts->isPositive() ? $purchase->fundedFromReceipts->format() : null,
                'fundedFromSavings' => $purchase->fundedFromSavings->isPositive() ? $purchase->fundedFromSavings->format() : null,
                'mortgage' => $purchase->mortgage->isPositive() ? $purchase->mortgage->format() : null,
                'mortgageInterest' => ($purchase->mortgage->isPositive() && $action->buyMortgageRate !== null)
                    ? $purchase->mortgage->applyRate($action->buyMortgageRate)->format()
                    : null,
                'unfundedGap' => $purchase->unfundedGap->isPositive() ? $purchase->unfundedGap->format() : null,
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
            ['key' => 'careCostGrowth', 'label' => 'Care cost growth (real)', 'value' => self::ratePct($set->careCostRealGrowth()->asPercent()), 'note' => 'how fast care-home fees rise above inflation; care outruns general prices, so a late-life care spell costs more the later it falls'],
            ['key' => 'investmentCharge', 'label' => 'Investment charges (a year)', 'value' => self::ratePct($set->investmentCharge()->asPercent()), 'note' => 'platform and fund fees taken out of pensions, ISAs and investments each year; the growth rate above is before charges, and cash deposits pay none'],
        ];
        // Show-your-working for the fan's width: when house growth is stochastic, surface the
        // volatility it is sampled over so the home-equity spread traces to a stated figure
        // rather than appearing from nowhere. The index figure itself is not user-overridable, so
        // it never flags as edited; the figure the household's OWN home is modelled over is, and
        // sits beside it, because the two are different numbers and reading one for the other
        // would understate the risk of every plan that keeps a home.
        if ($set->houseGrowthVolatility !== null && $set->houseGrowthVolatility->basisPoints > 0) {
            array_splice($economic, 3, 0, [[
                'key' => 'houseVolatility',
                'label' => 'House price growth volatility (real, index)',
                'value' => self::ratePct($set->houseGrowthVolatility->asPercent()),
                'note' => 'the year-to-year spread of the market as a whole; the central projection uses the mean above',
            ]]);
            array_splice($economic, 4, 0, [[
                'key' => 'propertyVolatility',
                'label' => 'Your home\'s price swing (real)',
                'value' => self::ratePct($set->singlePropertyVolatility()?->asPercent() ?? 0.0),
                'note' => 'the spread the Monte Carlo moves YOUR home over, wider than the index because one home is not a market; a growth rate you entered for the home sets where this spread is centred, not how wide it is',
            ]]);
        }
        // Same show-your-working for salary: when salary growth is stochastic, surface the volatility
        // it is sampled over so a working household's earnings spread traces to a stated figure. Placed
        // right after the salary-growth row (whose index shifts by one if the house row was inserted).
        if ($set->salaryGrowthVolatility !== null && $set->salaryGrowthVolatility->basisPoints > 0) {
            $salaryIndex = array_key_first(array_filter($economic, fn (array $row): bool => $row['key'] === 'salaryGrowth'));
            array_splice($economic, $salaryIndex + 1, 0, [[
                'key' => 'salaryVolatility',
                'label' => 'Salary growth volatility (real)',
                'value' => self::ratePct($set->salaryGrowthVolatility->asPercent()),
                'note' => 'the year-to-year spread the Monte Carlo samples a working person\'s pay rises over; the central projection uses the mean above',
            ]]);
        }

        $economic = array_map(
            fn (array $row): array => [...$row, 'edited' => in_array($row['key'], $changed, true)],
            $economic,
        );

        // Selling costs: each component resolved to £ on its own basis (% of sale or flat fee),
        // or the engine's assumed default when none was entered. One row per component so the
        // total on the sale waterfall traces to a stated basis here.
        // Where nothing was itemised the engine charges its own all-in rate, and this row is the
        // only place a reader is told so. The rate and the pounds it comes to are both READ from
        // the constant that owns them (board card 0032 raised it, and a restated "2%" here would
        // have gone on claiming the old figure while the engine charged the new one).
        $housing = [];
        if ($action->sellingCosts === null) {
            $rate = Percent::fromBasisPoints(HousingProceeds::DEFAULT_SELLING_COST_RATE_BP);
            $housing[] = ['label' => 'Selling costs', 'value' => $action->salePrice->applyRate($rate)->format()
                .' ('.self::ratePct($rate->asPercent()).' of the sale price, assumed: agent, conveyancing, the '
                .'leasehold fees, the energy certificate and removals)'];
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
            $housing[] = ['label' => 'Home to buy', 'value' => $action->buyPrice->format()];
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

    /**
     * A probability that the money lasts, bucketed to a plain word band with no decimals —
     * 94.9% and 95.0% are Monte Carlo noise, so a decision-maker reads "Very likely to last",
     * not a spurious figure. One home for the banding, so a chip on the combination-comparison
     * surface and any other word-band readout can never disagree on where the boundaries fall.
     * `level` (strong→poor) drives the colour + icon the view gives it; the copy is factual and
     * never uses "safe" (the decision-support neutral-copy guardrail).
     *
     * @return array{level: string, word: string}
     */
    public static function lastsBand(float $successProbability): array
    {
        $p = max(0.0, min(1.0, $successProbability));

        return match (true) {
            $p >= 0.90 => ['level' => 'strong', 'word' => 'Very likely to last'],
            $p >= 0.75 => ['level' => 'good', 'word' => 'Likely to last'],
            $p >= 0.50 => ['level' => 'borderline', 'word' => 'Borderline'],
            $p >= 0.25 => ['level' => 'weak', 'word' => 'Likely to fall short'],
            default => ['level' => 'poor', 'word' => 'Very likely to fall short'],
        };
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

    /**
     * The distinct monthly instalments of an amortising mortgage, in the order they are charged —
     * one per rate tier (a 5-year fix then a reversion rate gives two). The final instalment is
     * trued up by pennies to clear the balance exactly, so it is excluded: it is an artefact of
     * rounding, not a payment the borrower would recognise from their illustration.
     *
     * @return list<string> formatted money, e.g. ['£1,318.54', '£1,384.65']
     */
    private static function distinctInstalments(AmortisationSchedule $schedule): array
    {
        $rows = $schedule->rows();
        $out = [];
        $seen = null;

        foreach (array_slice($rows, 0, max(0, count($rows) - 1)) as $row) {
            if ($row['payment'] !== $seen) {
                $seen = $row['payment'];
                $out[] = Money::fromPence($row['payment'])->format();
            }
        }

        return $out === [] ? [Money::fromPence($rows[0]['payment'] ?? 0)->format()] : $out;
    }
}
