<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Compliance\Interpretation;
use App\Enums\ScenarioStatus;
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

    /** How many plans the last "re-run all" click queued (0 = none yet). */
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
     */
    public function runFullFamily(): void
    {
        $runner = app(SimulationRunner::class);
        $this->runIds = $this->plans()->map(fn (Scenario $plan): int => $runner->dispatch($plan)->id)->all();
        $this->familyQueued = count($this->runIds);
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

        // One deterministic projection per plan, reused for both the summary table and the
        // wealth-over-time burndown overlay (so the chart can't drift from the table). Each
        // plan is projected on ITS OWN housing strategy (not the raw stay-put basis), so a
        // buy-vs-rent comparison's columns actually differ — the same per-variant single source
        // the results-page cashflow ladder uses (#6), keyed by the plan's chosen variant.
        $forecasts = $this->plans()->map(fn (Scenario $plan): array => [
            'scenario' => $plan,
            'forecast' => $forecaster->deterministicVariants($plan)[$plan->variant->value],
        ]);

        $plans = $forecasts->map(fn (array $pf): array => $this->summarise($pf['scenario'], $pf['forecast'], $forecaster));

        // Mark the big life events on the comparison chart, from the base plan's timeline (deaths,
        // retirements, State Pension starts are shared across the compared plans; the home sale is
        // the base's). The same annotations the single-scenario charts carry.
        $baseForecast = $forecasts->first()['forecast'];
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
        $narrative = Gate::allows('interpret')
            ? Interpretation::compareNarrative(
                $forecasts->map(fn (array $pf): array => ['name' => $pf['scenario']->name, 'forecast' => $pf['forecast']])->all(),
            )
            : [];

        // Contextual "get help" panel: the mortgage column shows if any compared plan involves a
        // mortgage (an owed balance or a buy funded by one); the CGT column if any sells a home that
        // was ever let (partial-PRR CGT). The pensions & money column always shows.
        $showMortgage = $forecasts->contains(fn (array $pf): bool => ($pf['scenario']->toHousehold()->primaryResidence?->outstandingMortgage?->isPositive() ?? false) || $pf['scenario']->toHousingAction()->buyMortgageRate !== null);
        $showCgt = $forecasts->contains(fn (array $pf): bool => $pf['scenario']->toHousehold()->primaryResidence?->everLet ?? false);

        return view('livewire.scenario-compare', [
            'base' => $this->base,
            'plans' => $plans,
            'burndown' => $burndown,
            'narrative' => $narrative,
            'sourcesShowMortgage' => $showMortgage,
            'sourcesShowCgt' => $showCgt,
            // Live progress for the "re-run all" batch, from the same plans the table shows.
            'familyRun' => $this->familyProgress($forecasts->map(fn (array $pf): Scenario => $pf['scenario'])),
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

    /** The base plan first, then its ready what-if children. */
    private function plans(): Collection
    {
        return collect([$this->base])->concat(
            $this->base->children()->where('status', ScenarioStatus::Ready)->latest()->get(),
        );
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
            'orphans' => $plan->orphanedOverrides(),
            'editUrl' => route('scenarios.edit', $plan),
            'resultsUrl' => route('scenarios.results', $plan),
        ];
    }

    /**
     * For a "sell & buy cheaper" plan, how much the purchase (buy price + SDLT + moving) exceeds
     * the net sale proceeds — the extra capital the household would need from elsewhere, which the
     * engine otherwise assumes away by flooring the surplus at £0. Null when the plan is not a buy,
     * or the sale covers it. Read from the single engine source ({@see HousingComparison::buyOutcome}).
     */
    private function buyShortfall(Scenario $plan, ScenarioForecaster $forecaster): ?string
    {
        if ($plan->variant->value !== 'buy_outright') {
            return null;
        }

        $outcome = $forecaster->housingComparison($plan)->buyOutcome($plan->toHousehold(), $plan->toHousingAction());
        // Covered from cash, or the gap is funded by a buy mortgage → not an affordability warning.
        if ($outcome->coversPurchase() || $outcome->mortgage->isPositive()) {
            return null;
        }

        return $outcome->buyPrice->plus($outcome->stampDuty)->plus($outcome->movingCosts)->minus($outcome->netProceeds)->format();
    }

    /**
     * For a "sell & buy cheaper" plan where the purchase costs more than the sale frees and a buy
     * mortgage funds the gap, a note of the loan taken and its interest-only cost — so a buy above
     * the proceeds reads as financed, not unaffordable. Null when the plan is not a mortgaged buy.
     */
    private function buyMortgage(Scenario $plan, ScenarioForecaster $forecaster): ?string
    {
        if ($plan->variant->value !== 'buy_outright') {
            return null;
        }

        $action = $plan->toHousingAction();
        $outcome = $forecaster->housingComparison($plan)->buyOutcome($plan->toHousehold(), $action);
        if (! $outcome->mortgage->isPositive() || $action->buyMortgageRate === null) {
            return null;
        }

        $interest = $outcome->mortgage->applyRate($action->buyMortgageRate);

        return "Funded by a {$outcome->mortgage->format()} interest-only mortgage on the new home (~{$interest->format()}/yr).";
    }
}
