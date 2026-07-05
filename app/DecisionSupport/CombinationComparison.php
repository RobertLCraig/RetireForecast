<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Compliance\Interpretation;
use App\Forecast\ResultPresenter;
use App\Forecast\WhatIfChanges;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * Decision-support Phase 3 — the combination-comparison surface. Lays a base plan and its
 * what-if children beside each other by their MONTE CARLO outcome (not the deterministic
 * Yes/No the Compare table shows — this is its own surface), as a plain word-band chip
 * ("Very likely to last") over a net-position sparkline, with the exact figures kept in the
 * analyst drill-down. No decimals in the headline: 94.9% and 95.0% are Monte Carlo noise, so
 * the decision-maker reads a word, not a spurious percentage.
 *
 * This presenter is deliberately NEUTRAL — it never ranks the plans and never labels one
 * "strongest". The rows come back in the order they were given (base first). Best-first
 * ordering and the "strongest/weakest" narrative are advice, and live behind the walled-off
 * `interpret` gate ({@see Interpretation::combinationRanking()}); the caller
 * reorders only when that gate allows. The surprising-lever callout below is a FACTUAL
 * observation (a longer life raising the odds because the binding risk is survivor income),
 * not a recommendation, so it stays on the guidance side.
 */
final class CombinationComparison
{
    /**
     * How much a plan's success must exceed the base's before a counterintuitive direction is
     * called out (2 percentage points), so ordinary Monte Carlo scatter never trips the callout.
     */
    private const SURPRISE_MARGIN = 0.02;

    /**
     * Build the neutral comparison view model from the compared plans. Each entry pairs a
     * scenario with its deterministic forecast (already computed for the Compare table, reused
     * here for the modelled death year the callout needs) and its latest completed Monte Carlo
     * result (null when the plan has not been simulated yet).
     *
     * @param  list<array{scenario: Scenario, forecast: ForecastResult, mc: ?SimulationResult}>  $plans
     * @return array{rows: list<array<string, mixed>>, anyMissing: bool, callout: ?string}
     */
    public static function build(array $plans): array
    {
        $rows = array_map(self::row(...), $plans);

        $baseRow = null;
        foreach ($rows as $row) {
            if ($row['isBase']) {
                $baseRow = $row;
                break;
            }
        }

        $anyMissing = false;
        foreach ($rows as $row) {
            if (! $row['hasRun']) {
                $anyMissing = true;
                break;
            }
        }

        return [
            'rows' => $rows,
            'anyMissing' => $anyMissing,
            'callout' => self::surprisingLever($rows, $baseRow),
        ];
    }

    /**
     * One plan's row: its neutral identity (name, base flag, what-if changes) plus, once it has
     * a completed run, the word-band chip, the net-position sparkline, and the analyst figures.
     * `sortKey` is the raw success probability the caller sorts by ONLY in advice mode (−1 with
     * no run, so an unsimulated plan sinks below any simulated one when a ranking is allowed).
     *
     * @param  array{scenario: Scenario, forecast: ForecastResult, mc: ?SimulationResult}  $plan
     * @return array<string, mixed>
     */
    private static function row(array $plan): array
    {
        $scenario = $plan['scenario'];
        $forecast = $plan['forecast'];
        $mc = $plan['mc'];

        $row = [
            'name' => $scenario->name,
            'isBase' => ! $scenario->isChild(),
            'changes' => WhatIfChanges::of($scenario),
            'resultsUrl' => route('scenarios.results', $scenario),
            'hasRun' => $mc !== null,
            // The modelled last death year (max across the household), for the surprising-lever
            // callout; null when the forecast records no death in the horizon.
            'deathYear' => $forecast->deathCalendarYears === [] ? null : max($forecast->deathCalendarYears),
            'chip' => null,
            'sparkline' => null,
            'figures' => null,
            'sortKey' => -1.0,
        ];

        if ($mc === null) {
            return $row;
        }

        return [
            ...$row,
            'chip' => ResultPresenter::lastsBand($mc->successProbabilityEssentials),
            'sparkline' => self::sparkline($mc),
            'figures' => self::figures($mc),
            'sortKey' => $mc->successProbabilityEssentials,
        ];
    }

    /**
     * The analyst drill-down figures for one plan: the chance essentials/full spend last, how
     * often the money runs short (and the typical year), and the spread of usable wealth left.
     * These carry the decimals the chip deliberately hides.
     *
     * @return array<string, mixed>
     */
    private static function figures(SimulationResult $mc): array
    {
        $usable = $mc->usableWealthPercentiles;

        return [
            'successEssentials' => ResultPresenter::formatPercent($mc->successProbabilityEssentials),
            'successFullSpend' => ResultPresenter::formatPercent($mc->successProbabilityFullSpend),
            'runsShort' => ResultPresenter::formatPercent($mc->depletionRate),
            'runsShortYear' => $mc->medianDepletionYear,
            'p10Usable' => isset($usable['p10']) ? $usable['p10']->format() : null,
            'medianUsable' => isset($usable['p50']) ? $usable['p50']->format() : null,
            'paths' => $mc->nPaths,
        ];
    }

    /**
     * A net-position sparkline: the Monte Carlo MEDIAN spendable position per year, continued
     * below £0 by the cumulative shortfall (the same net-position series the results-page fan and
     * Compare burndown plot, so a glance here can't contradict them). Falls back to the usable
     * fan for a run persisted before the net-position series existed. The chip word + the
     * drill-down figures carry the meaning in text; the sparkline is a glanceable reinforcement,
     * described for assistive tech by the aria-label the view builds from `runsShortYear`/`endYear`.
     *
     * @return array{options: array<string, mixed>, dipsNegative: bool, runsShortYear: ?int, endYear: ?int}
     */
    private static function sparkline(SimulationResult $mc): array
    {
        $series = $mc->netPositionFanChart !== [] ? $mc->netPositionFanChart : $mc->usableFanChart;

        $data = [];
        $dipsNegative = false;
        $runsShortYear = null;
        foreach ($series as $point) {
            $pounds = (int) round($point['p50']->pence / 100);
            $data[] = ['x' => $point['calendarYear'], 'y' => $pounds];
            if ($pounds < 0) {
                $dipsNegative = true;
                $runsShortYear ??= $point['calendarYear'];
            }
        }

        $options = [
            'chart' => ['type' => 'line', 'height' => 48, 'sparkline' => ['enabled' => true]],
            'series' => [['name' => 'Net position', 'data' => $data]],
            'colors' => ['#4f46e5'],
            'stroke' => ['width' => 2, 'curve' => 'straight'],
            'tooltip' => ['enabled' => false],
            // A slate £0 baseline so the eye reads where the line crosses into shortfall.
            'annotations' => ['yaxis' => [['y' => 0, 'borderColor' => '#94a3b8', 'strokeDashArray' => 2]]],
        ];

        return [
            'options' => $options,
            'dipsNegative' => $dipsNegative,
            'runsShortYear' => $runsShortYear,
            'endYear' => $data === [] ? null : $data[count($data) - 1]['x'],
        ];
    }

    /**
     * A neutral, factual callout when a plan that models LIVING LONGER (a later modelled last
     * death) comes out with a HIGHER chance the money lasts than the base — the counterintuitive
     * signal at the heart of this feature (the binding risk is survivor income after the first
     * death, which a longer life does not by itself worsen). Returns the first such observation,
     * or null when nothing surprising shows. Phrased as an observation, never a recommendation.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $baseRow
     */
    private static function surprisingLever(array $rows, ?array $baseRow): ?string
    {
        if ($baseRow === null || ! $baseRow['hasRun'] || $baseRow['deathYear'] === null) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row['isBase'] || ! $row['hasRun'] || $row['deathYear'] === null) {
                continue;
            }

            $livesLonger = $row['deathYear'] > $baseRow['deathYear'];
            $lastsBetter = $row['sortKey'] >= $baseRow['sortKey'] + self::SURPRISE_MARGIN;
            if ($livesLonger && $lastsBetter) {
                return "Something worth noticing: “{$row['name']}” models living longer, yet the chance the money covers the essentials comes out higher than the base plan, not lower. On these figures the binding risk is the surviving partner's income after the first death, so a longer life does not by itself make the money run short sooner.";
            }
        }

        return null;
    }
}
