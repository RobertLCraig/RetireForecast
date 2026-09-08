<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Compliance\Interpretation;
use App\DecisionSupport\CombinationComparison;
use App\DecisionSupport\CombinationComparisonData;
use App\Enums\SimulationStatus;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Forecast\WhatIfChanges;
use App\Models\Scenario;
use App\Models\SimulationRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * Compares a base plan with its delta-child what-ifs side by side (Phase C2). Each
 * plan is run through the deterministic central projection — so the comparison shows
 * immediately, no Monte Carlo run needed — and the figures are laid out in one
 * accessible table: housing choice, whether essentials are covered every year, whether
 * the money lasts, and the usable / total wealth left.
 *
 * The neutral table never ranks the plans — the figures are shown per plan for the reader
 * to draw their own conclusion. A directive "which to lean towards" narrative is added only
 * behind the walled-off `interpret` ability (on in personal-use mode), produced by
 * {@see Interpretation::compareNarrative()}, so the guidance-only partition holds otherwise.
 */
#[Layout('components.layouts.app')]
class ScenarioCompare extends Component
{
    public Scenario $base;

    /**
     * When on, plans whose usable-wealth line falls below £0 at any point (they run out of
     * spendable money — the deterministic depletion the "Money lasts: No" column reports) are
     * dropped from every surface here, so the reader can focus on the plans that actually last.
     */
    public bool $hideNonViable = false;

    /**
     * How many plans the last "re-run all" click actually queued (0 = none yet). A plan whose
     * inputs have not moved since its last run is served that run instead of recomputing, so
     * this counts the fresh runs, not the plans compared.
     */
    public int $familyQueued = 0;

    /**
     * The full-run IDs currently being tracked for live progress — the last "re-run all" batch,
     * plus any family run already in flight when the page loaded. Polled while any is unfinished
     * so a background Monte Carlo run never runs silently. Public (so it survives poll requests)
     * and therefore treated as tamperable: {@see trackedRuns()} re-scopes it to the owner.
     *
     * @var list<int>
     */
    public array $runIds = [];

    public function mount(Scenario $scenario): void
    {
        abort_unless($scenario->user_id === auth()->id(), 403);

        // Compare is base-centric: opening it on a what-if compares its base's family.
        $this->base = $scenario->isChild() ? $scenario->parent : $scenario;

        // Pick up any family run already in flight (launched here before a reload, or from a
        // plan's own results page), so arriving on Compare mid-run shows live progress.
        $this->runIds = $this->inFlightFamilyRunIds();
    }

    /**
     * Queue a fresh full (10,000-path) Monte Carlo run for every plan being compared — the base
     * plus its ready what-if children. The comparison table itself is the live deterministic
     * projection (already current), but each plan's own results page shows its stored Monte Carlo
     * run; after a model change or a new assumption those go stale, so this refreshes the whole
     * set in one click rather than opening each plan. The runs execute in the background on the
     * worker; a queued-count note confirms.
     *
     * A plan whose inputs are unchanged since its last full run is handed that run back rather
     * than recomputed ({@see SimulationRunner::dispatch}), so re-clicking this costs nothing. The
     * batch still tracks every plan, but only the genuinely fresh runs are counted queued.
     */
    public function runFullFamily(): void
    {
        $runner = app(SimulationRunner::class);
        $runs = $this->plans()->map(fn (Scenario $plan): SimulationRun => $runner->dispatch($plan));
        $this->runIds = $runs->map(fn (SimulationRun $run): int => $run->id)->all();
        $this->familyQueued = $runs->filter(fn (SimulationRun $run): bool => $run->wasRecentlyCreated)->count();
    }

    /** wire:poll target while the batch is in flight; the render pass re-reads its progress. */
    public function refreshFamily(): void
    {
        // intentionally empty — render() reloads the tracked runs from the database
    }

    /** Cancel every still-running plan in the tracked batch (each stops at its next progress tick). */
    public function cancelFamily(): void
    {
        $runner = app(SimulationRunner::class);
        foreach ($this->trackedRuns() as $run) {
            if (! $run->status->isTerminal()) {
                $runner->cancel($run);
            }
        }
    }

    public function render(): View
    {
        $forecaster = app(ScenarioForecaster::class);

        // One assembly of the compared plans — each with its own-variant deterministic forecast
        // (so a buy-vs-rent comparison's columns actually differ, the same per-variant source the
        // results-page ladder uses) AND its latest completed Monte Carlo result. Shared by the
        // deterministic table, the burndown overlay, and the Phase-3 combination comparison, and
        // built the same way the CSV download builds its plan set, so nothing can drift.
        $plansDataFull = CombinationComparisonData::assemble($this->base, $forecaster);

        // The base's own forecast drives the shared milestone annotations even when the base row
        // is itself hidden by the filter below, so capture it before any hide filter is applied.
        $baseForecast = $plansDataFull[0]['forecast'];

        // "Non-viable" = the deterministic usable-wealth line falls below £0 at some point (the plan
        // runs out of spendable money — the same depletion the "Money lasts: No" column reports and
        // the burndown draws crossing the axis). The toggle drops those plans from every surface
        // here (table, burndown, Monte-Carlo cards) so nothing conjured shows beside the plans that
        // last. The toggle itself only appears when there is at least one non-viable plan to hide.
        $isNonViable = static fn (array $pf): bool => $pf['forecast']->depletionCalendarYear !== null;
        $anyNonViable = collect($plansDataFull)->contains($isNonViable);

        $plansData = $this->hideNonViable
            ? array_values(array_filter($plansDataFull, static fn (array $pf): bool => ! $isNonViable($pf)))
            : $plansDataFull;
        $hiddenCount = count($plansDataFull) - count($plansData);

        $forecasts = collect($plansData);

        $plans = $forecasts->map(fn (array $pf): array => $this->summarise($pf['scenario'], $pf['forecast'], $forecaster));

        // Mark the big life events on the comparison chart, from the base plan's timeline (deaths,
        // retirements, State Pension starts are shared across the compared plans; the home sale is
        // the base's). The same annotations the single-scenario charts carry.
        $annotations = ResultPresenter::milestoneAnnotations(ResultPresenter::milestones(
            $this->base->toHousehold(),
            $baseForecast,
            in_array($this->base->variant->value, ['buy_outright', 'rent'], true),
        ));

        $burndown = ResultPresenter::burndown(
            $forecasts->map(fn (array $pf): array => ['name' => $pf['scenario']->name, 'forecast' => $pf['forecast']])->all(),
            $annotations,
        );

        // Advice-style "why" narrative ranking the compared plans (the buy-vs-rent recommendation).
        // Walled off behind the `interpret` ability — on for everyone in personal-use mode
        // (config/compliance.php), the per-user grant otherwise. Empty = neutral guidance only.
        $interpret = Gate::allows('interpret');
        $narrative = $interpret
            ? Interpretation::compareNarrative(
                $forecasts->map(fn (array $pf): array => ['name' => $pf['scenario']->name, 'forecast' => $pf['forecast']])->all(),
            )
            : [];

        // Decision-support Phase 3: the same plans compared on their MONTE CARLO outcome as
        // plain word-band chips + net-position sparklines (its own surface — never mixed into the
        // deterministic Yes/No grid above). Neutral and UNORDERED by default; best-first ordering
        // and the "which to lean towards" narrative appear only when `interpret` allows, because a
        // best-first list is itself advice (invisible to the phrasing lint). Same gate as above.
        $comparison = CombinationComparison::build($plansData);
        $combinationRanking = [];
        if ($interpret) {
            [$comparison['rows'], $combinationRanking] = $this->rankCombination($comparison['rows'], $plansData);
        }

        // Contextual "get help" panel: the mortgage column shows if any compared plan involves a
        // mortgage (an owed balance or a buy funded by one); the CGT column if any sells a home that
        // was ever let (partial-PRR CGT). The pensions & money column always shows.
        $showMortgage = $forecasts->contains(fn (array $pf): bool => ($pf['scenario']->toHousehold()->primaryResidence?->outstandingMortgage?->isPositive() ?? false) || $pf['scenario']->toHousingAction()->buyMortgageRate !== null);
        $showCgt = $forecasts->contains(fn (array $pf): bool => $pf['scenario']->toHousehold()->primaryResidence?->everLet ?? false);
        // The benefits & debt column shows if any compared plan runs short, or carries a mortgage:
        // a household comparing plans that all fail was pointed at the investment world and
        // nowhere else (board card 0052).
        $showBenefitsDebt = $showMortgage
            || $forecasts->contains(fn (array $pf): bool => ResultPresenter::priorityDebtGuidance($pf['forecast']) !== null);

        return view('livewire.scenario-compare', [
            'base' => $this->base,
            'plans' => $plans,
            // The lifespan every figure in the single-path table runs to, and the odds it leaves
            // (board card 0061). Read off the base plan's own settings, which is what the table
            // is ranked on; the same sentence the results page and the PDF show.
            'planningHorizonBasis' => ResultPresenter::planningHorizonBasis($forecaster->settings($this->base)),
            // The "hide non-viable" toggle: whether any plan is non-viable (so the control shows
            // at all), whether it is on, how many rows it is currently hiding, and the full plan
            // count (so the "Re-run all" button still names every plan — the run covers them all).
            'anyNonViable' => $anyNonViable,
            'hideNonViable' => $this->hideNonViable,
            'hiddenCount' => $hiddenCount,
            'planCount' => count($plansDataFull),
            'burndown' => $burndown,
            'narrative' => $narrative,
            'sourcesShowMortgage' => $showMortgage,
            'sourcesShowCgt' => $showCgt,
            'sourcesShowBenefitsDebt' => $showBenefitsDebt,
            // Live progress for the "re-run all" batch, from the same plans the table shows.
            'familyRun' => $this->familyProgress($forecasts->map(fn (array $pf): Scenario => $pf['scenario'])),
            // Phase-3 combination comparison (its own surface, below the deterministic table).
            'comparison' => $comparison,
            'combinationRanked' => $interpret,
            'combinationRanking' => $combinationRanking,
            'combinationCsvUrl' => route('scenarios.compare.csv', $this->base),
        ])->title('Compare what-ifs');
    }

    /**
     * Live progress for the tracked batch: each plan's status + percentage, an aggregate, and
     * whether any run is still in flight (so the view polls only while it must). Empty when
     * nothing is tracked, so the panel — and the polling — appear exactly while runs execute.
     *
     * @param  Collection<int, Scenario>  $scenarios  the compared plans, in display order
     * @return array{active: bool, total: int, done: int, failed: int, overallPct: int, awaitingWorker: bool, rows: list<array{name: string, status: string, pct: int, terminal: bool, failed: bool}>}
     */
    private function familyProgress(Collection $scenarios): array
    {
        $runs = $this->trackedRuns()->keyBy('scenario_id');

        $rows = [];
        $sumPct = 0;
        $done = 0;
        $failed = 0;
        $active = false;
        $awaitingWorker = false;

        foreach ($scenarios as $plan) {
            $run = $runs->get($plan->id);
            if ($run === null) {
                continue;
            }

            $terminal = $run->status->isTerminal();
            $didNotFinish = in_array($run->status, [SimulationStatus::Failed, SimulationStatus::Cancelled], true);
            // Done reports 100; a still-running plan reports its live percentage.
            $pct = $run->status === SimulationStatus::Done ? 100 : (int) $run->progress_pct;

            $rows[] = [
                'name' => $plan->name,
                'status' => ucfirst($run->status->value),
                'pct' => $pct,
                'terminal' => $terminal,
                'failed' => $didNotFinish,
            ];
            $sumPct += $pct;
            $done += $run->status === SimulationStatus::Done ? 1 : 0;
            $failed += $didNotFinish ? 1 : 0;
            $active = $active || ! $terminal;
            $awaitingWorker = $awaitingWorker || $run->isAwaitingWorker();
        }

        $total = count($rows);

        return [
            'active' => $active,
            'total' => $total,
            'done' => $done,
            'failed' => $failed,
            'overallPct' => $total > 0 ? (int) round($sumPct / $total) : 0,
            'awaitingWorker' => $awaitingWorker,
            'rows' => $rows,
        ];
    }

    /** The tracked batch runs, re-scoped to the owner ($runIds is public and tamperable). */
    private function trackedRuns(): Collection
    {
        if ($this->runIds === []) {
            return collect();
        }

        return SimulationRun::where('user_id', auth()->id())
            ->whereIn('id', $this->runIds)
            ->get();
    }

    /**
     * The latest run per compared plan that is still in flight — what's running right now,
     * used to restore the progress panel when Compare is opened mid-run.
     *
     * @return list<int>
     */
    private function inFlightFamilyRunIds(): array
    {
        return $this->plans()
            ->map(fn (Scenario $plan): ?SimulationRun => $plan->simulationRuns()->latest()->first())
            ->filter(fn (?SimulationRun $run): bool => $run !== null && ! $run->status->isTerminal())
            ->map(fn (SimulationRun $run): int => $run->id)
            ->values()
            ->all();
    }

    /** The base plan first, then its ready what-if children (the one home for the compared set). */
    private function plans(): Collection
    {
        return CombinationComparisonData::plans($this->base);
    }

    /**
     * Best-first ordering for the Phase-3 combination comparison, reached only when `interpret`
     * allows (ordering is advice). Sorts the rows by the SAME comparator {@see Interpretation::combinationRanking()}
     * ranks by — most futures covering the essentials, then the full spend, then the most usable
     * wealth left — so the row order and the "which to lean towards" narrative can never disagree;
     * unsimulated plans (no figures) sink to the end. Rows share $plansData's order, so each row's
     * raw Monte Carlo figures are read by index. Returns the reordered rows and the narrative lines.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{scenario: Scenario, forecast: ForecastResult, mc: ?SimulationResult}>  $plansData
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function rankCombination(array $rows, array $plansData): array
    {
        $median = static fn (?SimulationResult $mc): int => $mc !== null && isset($mc->usableWealthPercentiles['p50'])
            ? $mc->usableWealthPercentiles['p50']->pence
            : PHP_INT_MIN;

        $indexed = [];
        foreach ($rows as $i => $row) {
            $mc = $plansData[$i]['mc'];
            $indexed[] = [
                'row' => $row,
                'key' => $mc === null
                    ? [0, -1.0, -1.0, PHP_INT_MIN]
                    : [1, $mc->successProbabilityEssentials, $mc->successProbabilityFullSpend, $median($mc)],
            ];
        }
        usort($indexed, static fn (array $a, array $b): int => $b['key'] <=> $a['key']);
        $ordered = array_map(static fn (array $x): array => $x['row'], $indexed);

        $figures = [];
        foreach ($plansData as $plan) {
            if ($plan['mc'] !== null) {
                $figures[] = [
                    'name' => $plan['scenario']->name,
                    'successEssentials' => $plan['mc']->successProbabilityEssentials,
                    'successFullSpend' => $plan['mc']->successProbabilityFullSpend,
                    'depletionRate' => $plan['mc']->depletionRate,
                    'medianUsablePence' => $median($plan['mc']) === PHP_INT_MIN ? 0 : $median($plan['mc']),
                ];
            }
        }

        return [$ordered, Interpretation::combinationRanking($figures)['lines']];
    }

    /**
     * One plan's deterministic headline figures, framed neutrally (no ranking).
     *
     * @return array<string, mixed>
     */
    private function summarise(Scenario $plan, ForecastResult $forecast, ScenarioForecaster $forecaster): array
    {
        return [
            'name' => $plan->name,
            'isBase' => ! $plan->isChild(),
            // What this what-if changed from the base (empty for the base itself), so the
            // comparison says not just how each plan turns out but what makes it different.
            'changes' => WhatIfChanges::of($plan),
            'variant' => ResultPresenter::variantLabel($plan->variant),
            // Buy-cheaper affordability: when a "sell & buy" plan's purchase costs more than the
            // sale frees, the engine floors the surplus at £0 and buys anyway — so surface the
            // shortfall here, or the comparison could crown a plan the household can't actually
            // afford (only the buy-cheaper variant buys; rent / stay never do). Null when covered.
            'buyShortfall' => $this->buyShortfall($plan, $forecaster),
            'buyMortgage' => $this->buyMortgage($plan, $forecaster),
            'essentialsMet' => $forecast->essentialsAlwaysMet,
            'fullSpendMet' => $forecast->fullSpendAlwaysMet,
            'moneyLasts' => $forecast->depletionCalendarYear === null,
            'depletionYear' => $forecast->depletionCalendarYear,
            'finalYear' => $forecast->finalCalendarYear,
            'usableWealth' => $forecast->terminalUsableWealth->format(),
            'totalWealth' => $forecast->terminalTotalWealth->format(),
            // The two figures a reader plans against, so plans can be compared on what they could
            // actually SPEND rather than on terminal wealth (which includes a home they can't
            // spend). Includes the survivor step-down — where these households actually fail.
            'spendable' => ResultPresenter::spendableSummary($forecast),
            // Inheritance Tax due across the household's deaths, when this plan models it (else null).
            'ihtDue' => $forecast->iht?->total->format(),
            'orphans' => $plan->orphanedOverrides(),
            'editUrl' => route('scenarios.edit', $plan),
            'resultsUrl' => route('scenarios.results', $plan),
        ];
    }

    /**
     * For a "sell & buy" plan, the part of the purchase no documented source funds — after the
     * net proceeds, the savings drawn and any configured buy mortgage. The engine charges this
     * gap as a year-0 cost (the plan visibly fails), so the row is flagged, not just modelled.
     * Null when the plan is not a buy or every pound traces to a source. Read from the single
     * engine source ({@see HousingComparison::buyOutcome}).
     */
    private function buyShortfall(Scenario $plan, ScenarioForecaster $forecaster): ?string
    {
        if ($plan->variant->value !== 'buy_outright') {
            return null;
        }

        $outcome = $forecaster->housingComparison($plan)
            ->buyOutcome($plan->toHousehold(), $plan->toHousingAction(), $forecaster->settings($plan)->baseYear);

        return $outcome->unfundedGap->isPositive() ? $outcome->unfundedGap->format() : null;
    }

    /**
     * For a "sell & buy" plan where the purchase costs more than the sale frees, a note of the
     * documented sources funding the gap — a capital receipt arriving that year, the savings
     * drawn (cash → GIA → ISA) and/or the interest-only mortgage taken (with its yearly cost),
     * in the order the engine spends them — so a buy above the proceeds reads
     * as financed by real money, never conjured. Null when the plan is not a buy or the
     * proceeds alone cover it.
     */
    private function buyMortgage(Scenario $plan, ScenarioForecaster $forecaster): ?string
    {
        if ($plan->variant->value !== 'buy_outright') {
            return null;
        }

        $action = $plan->toHousingAction();
        $outcome = $forecaster->housingComparison($plan)
            ->buyOutcome($plan->toHousehold(), $action, $forecaster->settings($plan)->baseYear);

        $parts = [];
        if ($outcome->fundedFromReceipts->isPositive()) {
            $parts[] = "{$outcome->fundedFromReceipts->format()} from a capital receipt arriving that year";
        }
        if ($outcome->fundedFromSavings->isPositive()) {
            $parts[] = "{$outcome->fundedFromSavings->format()} from savings";
        }
        if ($outcome->mortgage->isPositive() && $action->buyMortgageRate !== null) {
            $interest = $outcome->mortgage->applyRate($action->buyMortgageRate);
            $parts[] = "a {$outcome->mortgage->format()} interest-only mortgage on the new home (~{$interest->format()}/yr)";
        }

        return $parts === [] ? null : 'Funded by '.implode(' + ', $parts).'.';
    }
}
