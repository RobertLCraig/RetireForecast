<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Compliance\Interpretation;
use App\DecisionSupport\AdviceCostComparison;
use App\DecisionSupport\CapacityForLoss;
use App\DecisionSupport\ProtectionGap;
use App\Enums\ScenarioStatus;
use App\Export\ExportDisclaimer;
use App\Forecast\AssumptionComparison;
use App\Forecast\BuilderStateDelta;
use App\Forecast\LadderContext;
use App\Forecast\LumpSumTaxShock;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Forecast\WhatIfChanges;
use App\Forecast\WithdrawalStrategyComparison;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RetireForecast\FinanceEngine\Money\Money;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Runs a saved scenario and shows the result. A preview runs synchronously (a quick,
 * responsive read); the full 10,000-path run is queued and the page polls its
 * progress, with a cancel button, so a long run never blocks or runs silently.
 *
 * When a run finishes, its three variant results are presented as headline text, a
 * Monte Carlo fan chart and a buy-vs-rent comparison, each backed by an accessible
 * data table. Nothing here ranks the options or recommends one.
 */
#[Layout('components.layouts.app')]
class ScenarioResults extends Component
{
    public Scenario $scenario;

    public ?int $runId = null;

    /** Paths for the synchronous preview (the full queued run uses the engine's 10,000). */
    public int $previewPaths = 1000;

    /**
     * Chart wealth basis. False (default) plots USABLE wealth (excl. the home) — the
     * spendable money that actually runs out, the honest "will it last" view for a couple
     * not planning to sell again; true counts the home too. Flips both the fan and the
     * strategy-comparison chart (and their tables); the headline cards show both regardless.
     */
    public bool $includeHome = false;

    /**
     * Which housing strategy the year-by-year cashflow ladder (and its life-event milestones)
     * shows: stay_put | buy_outright | rent. Defaults to the scenario's own chosen variant.
     * The raw ladder used to project the household as-entered (always stay put), ignoring the
     * sale; this lets the user read each strategy's year-by-year picture. Clamped on render to
     * a strategy the inputs actually configure (see ladderContext()).
     */
    public string $ladderVariant = 'stay_put';

    /**
     * Live what-if sliders (exploratory, never saved): adjustments applied on top of this
     * scenario's inputs to a throwaway deterministic re-forecast, so the reader can feel how
     * sensitive the outcome is to each lever without building a what-if. All zero = the scenario
     * as it stands. Retirement is ± years on each working person; spend is ± % on every line;
     * return is ± percentage points on the blended real return; longevity is ± years of life.
     */
    public int $slideRetire = 0;

    public int $slideSpend = 0;

    public int $slideReturn = 0;

    public int $slideLongevity = 0;

    public function resetSliders(): void
    {
        $this->slideRetire = 0;
        $this->slideSpend = 0;
        $this->slideReturn = 0;
        $this->slideLongevity = 0;
    }

    /**
     * Save the current lever settings as a proper what-if: apply them to the base plan's
     * form-state, diff to a sparse override delta ({@see BuilderStateDelta}), and store it as an
     * ordinary delta-child (the same shape a hand-built or quick what-if is), then open it.
     * Replaces the old throwaway live-slider preview — a lever change is now always a real,
     * comparable scenario. Levers that change nothing save nothing (no empty what-if).
     */
    public function makeWhatIf(): mixed
    {
        $base = $this->scenario->baseScenario();
        $baseState = $base->effectiveBuilderState();
        $overrides = BuilderStateDelta::diff($baseState, $this->applySliders($baseState, $base));

        if ($overrides === []) {
            session()->flash('status', 'Move a lever first — there is nothing to save yet.');

            return null;
        }

        $child = new Scenario;
        $child->user_id = auth()->id();
        $child->parent_scenario_id = $base->id;
        $child->setRelation('parent', $base);
        $child->overrides = ['name' => $this->uniqueWhatIfName($base, $this->sliderSummary())] + $overrides;
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        return redirect()->route('scenarios.results', $child)
            ->with('status', 'What-if created from your adjustments. Compare it with the base.');
    }

    /** A short description of the current lever settings — the what-if's name and on-screen summary. */
    private function sliderSummary(): string
    {
        $parts = [];
        if ($this->slideRetire !== 0) {
            $parts[] = 'retire '.($this->slideRetire > 0 ? '+'.$this->slideRetire : (string) $this->slideRetire).' yr';
        }
        if ($this->slideSpend !== 0) {
            $parts[] = 'spend '.($this->slideSpend > 0 ? '+' : '').$this->slideSpend.'%';
        }
        if ($this->slideReturn !== 0) {
            $parts[] = 'return '.($this->slideReturn > 0 ? '+' : '').$this->slideReturn.' pts';
        }
        if ($this->slideLongevity !== 0) {
            $parts[] = 'live '.($this->slideLongevity > 0 ? '+' : '').$this->slideLongevity.' yr';
        }

        return $parts === [] ? 'No adjustment yet — move a lever to build a what-if.' : ucfirst(implode(', ', $parts));
    }

    /** Keep repeated lever what-ifs distinct: "Retire +2 yr", then "… (2)", "… (3)". */
    private function uniqueWhatIfName(Scenario $base, string $name): string
    {
        $existing = $base->children()->pluck('name')->all();
        if (! in_array($name, $existing, true)) {
            return $name;
        }
        $n = 2;
        while (in_array("{$name} ({$n})", $existing, true)) {
            $n++;
        }

        return "{$name} ({$n})";
    }

    /**
     * Apply the what-if sliders to a copy of the effective form-state: ± retirement years on
     * each working person, ± years of life (the offset lever), ± % on every spend line, and a
     * shift of the blended real return by the slider's percentage points (an investmentGrowth
     * override on top of the scenario's current blend). The same levers the quick what-ifs and
     * editable assumptions use, so a slider and a saved what-if move the forecast identically.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function applySliders(array $state, Scenario $forBlend): array
    {
        foreach ($state['people'] ?? [] as $i => $person) {
            if ($this->slideRetire !== 0
                && in_array($person['employmentStatus'] ?? '', ['employed', 'self_employed'], true)
                && is_numeric($person['plannedRetirementAge'] ?? '')) {
                $state['people'][$i]['plannedRetirementAge'] = (string) max(50, min(80, (int) $person['plannedRetirementAge'] + $this->slideRetire));
            }

            if ($this->slideLongevity !== 0) {
                $current = is_numeric($person['longevityValue'] ?? '') ? (int) $person['longevityValue'] : 0;
                $state['people'][$i]['longevityMode'] = ($person['longevityMode'] ?? 'peer') === 'fixed_age' ? 'fixed_age' : 'offset_years';
                $state['people'][$i]['longevityValue'] = (string) ($current + $this->slideLongevity);
            }
        }

        if ($this->slideSpend !== 0) {
            $factor = 1 + $this->slideSpend / 100;
            foreach ($state['expenseLines'] ?? [] as $i => $line) {
                if (is_numeric($line['amount'] ?? '')) {
                    $state['expenseLines'][$i]['amount'] = (string) round(((float) $line['amount']) * $factor, 2);
                }
            }
        }

        if ($this->slideReturn !== 0) {
            $forecaster = app(ScenarioForecaster::class);
            $baseBlended = $forecaster->settings($forBlend)->allocation()
                ->blendedRealReturn($forecaster->assumptions($forBlend)) * 100;
            $state['assumptionOverrides'] = $state['assumptionOverrides'] ?? [];
            $state['assumptionOverrides']['investmentGrowth'] = (string) round($baseBlended + $this->slideReturn, 2);
        }

        return $state;
    }

    public function mount(Scenario $scenario): void
    {
        abort_unless($scenario->user_id === auth()->id(), 403);

        // A draft is an in-progress build with no runnable result yet; send it back to the
        // builder rather than trying to forecast incomplete inputs.
        if ($scenario->status === ScenarioStatus::Draft) {
            $this->redirectRoute('scenarios.edit', $scenario);

            return;
        }

        $this->scenario = $scenario;
        $this->runId = $scenario->simulationRuns()->latest()->value('id');
        // Open the cashflow ladder on the scenario's own chosen strategy; render() clamps it
        // to one the inputs configure (e.g. stay put when no sale is set).
        $this->ladderVariant = $scenario->variant->value;
    }

    public function preview(): void
    {
        $this->runId = $this->runner()->preview($this->scenario, paths: $this->previewPaths)->id;
    }

    public function runFull(): void
    {
        $this->runId = $this->runner()->dispatch($this->scenario)->id;
    }

    public function cancel(): void
    {
        if ($run = $this->currentRun()) {
            $this->runner()->cancel($run);
        }
    }

    /** wire:poll target while a run is in flight; re-rendering re-reads its progress. */
    public function refreshRun(): void
    {
        // intentionally empty: the render pass reloads the run from the database
    }

    public function downloadFanCsv(): ?StreamedResponse
    {
        // Export the run whose results are on screen (the latest completed one), so the
        // CSV always matches the displayed fan table.
        $run = $this->resultsRun();
        if (! $run) {
            return null;
        }

        $presented = ResultPresenter::build($this->resultsByVariant($run), $this->scenario->variant->value, $this->includeHome, $this->scenario->toHousehold());
        $fan = $presented['fan'];

        return response()->streamDownload(function () use ($fan): void {
            $out = fopen('php://output', 'wb');
            foreach (ExportDisclaimer::LINES as $line) {
                fputcsv($out, [$line]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Year', 'P10', 'P25', 'P50', 'P75', 'P90']);
            foreach ($fan['rows'] as $row) {
                fputcsv($out, [$row['year'], $row['p10'], $row['p25'], $row['p50'], $row['p75'], $row['p90']]);
            }
            fclose($out);
        }, "fan-chart-{$fan['variant']}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * The per-strategy cashflow context: a deterministic forecast for each housing strategy,
     * which strategies the inputs make worth offering, and the currently selected one (clamped
     * to an offered strategy). One source for the rendered ladder/milestones, the CSV and the
     * printable PDF ({@see LadderContext}), so they can never show different strategies.
     */
    private function ladderContext(): LadderContext
    {
        return LadderContext::for(app(ScenarioForecaster::class), $this->scenario, $this->ladderVariant);
    }

    public function downloadLadderCsv(): StreamedResponse
    {
        $ctx = $this->ladderContext();
        $selected = $ctx->selected;
        $ladder = ResultPresenter::ladder($ctx->selectedForecast(), $this->scenario->safetyBufferMonths());

        return response()->streamDownload(function () use ($ladder): void {
            $out = fopen('php://output', 'wb');
            foreach (ExportDisclaimer::LINES as $line) {
                fputcsv($out, [$line]);
            }
            fputcsv($out, []);
            $header = ['Year', 'Age(s)'];
            foreach ($ladder['sources'] as $source) {
                $header[] = $ladder['sourceLabels'][$source];
            }
            $header = [...$header, 'Tax', 'Spend', 'Essential spend', 'Discretionary spend', 'Unmet spend',
                'To spend per month', 'of which essential per month', 'of which free per month',
                'Available capital (cash + investments)', 'Pension capital (taxable when drawn)',
                'Investment growth (capital)', 'Usable wealth (excl. home)', 'Total wealth (incl. home equity)'];
            fputcsv($out, $header);

            foreach ($ladder['rows'] as $row) {
                $line = [$row['year'], $row['ages']];
                foreach ($ladder['sources'] as $source) {
                    $line[] = $row['income'][$source];
                }
                $line = [...$line, $row['tax'], $row['spend'], $row['essentialSpend'], $row['discretionarySpend'], $row['shortfall'] ?? '',
                    $row['monthlyAllowance'], $row['monthlyEssential'], $row['monthlyFree'],
                    $row['availableCapital'], $row['pensionCapital'],
                    $row['investmentGrowth'], $row['usableWealth'], $row['totalWealth']];
                fputcsv($out, $line);
            }
            fclose($out);
        }, "cashflow-ladder-{$selected}.csv", ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        // Two distinct runs: $run is the latest of any status (drives the live
        // progress / status / cancel UI), while $resultsRun is the latest *completed*
        // run (drives the presented results). Keeping them separate means a newer
        // failed/cancelled run shows its status without hiding the last good result —
        // and the PDF, which reads the same latestCompletedRun(), can't diverge.
        $run = $this->currentRun();
        $resultsRun = $this->resultsRun();
        $presented = null;
        $interpretation = null;

        if ($resultsRun) {
            $resultsByVariant = $this->resultsByVariant($resultsRun);
            $presented = ResultPresenter::build($resultsByVariant, $this->scenario->variant->value, $this->includeHome, $this->scenario->toHousehold());

            // Advice-style readouts only for an admin-granted user; the public default
            // stays neutral. The directive wording lives solely in Interpretation.
            if (Gate::allows('interpret')) {
                $interpretation = Interpretation::readouts($resultsByVariant);
            }
        }

        $forecaster = app(ScenarioForecaster::class);

        // Withdrawal sequencing: what the household's draw order costs in lifetime tax vs filling
        // the tax-free bands first (on the base household). Neutral figures always; the directive
        // steer only behind the interpret gate.
        $comparison = WithdrawalStrategyComparison::for($forecaster, $this->scenario);
        $withdrawal = $comparison->panel();
        if ($withdrawal !== null) {
            $withdrawal['steer'] = Gate::allows('interpret') ? Interpretation::withdrawalSequencingNarrative($comparison) : null;
        }

        // Per-strategy cashflow: a deterministic forecast for each housing strategy (single
        // source — the same variant households the Monte Carlo comparison runs). The ladder +
        // its milestones follow the selected strategy; the income-floor / input-sanity notes
        // stay on the raw (stay-put) household, which is the household exactly as entered.
        $ladderContext = $this->ladderContext();
        $selectedStrategy = $ladderContext->selected;
        $ladderForecast = $ladderContext->selectedForecast();
        $forecast = $ladderContext->stayPutForecast();
        // A sell variant frees the home's value into investments at year 0 — the house-sale
        // milestone the timeline shows only for those strategies (never stay put).
        $homeSold = $ladderContext->homeSold();

        // The deterministic home-sale decomposition + the assumptions behind it, so every
        // headline figure traces to its inputs (show-your-working). Single-sourced from the
        // engine (HousingProceeds / HousingPurchase) and reconciled to the forecast.
        $household = $this->scenario->toHousehold();
        $action = $this->scenario->toHousingAction();
        $assumptions = $forecaster->assumptions($this->scenario);
        $allocation = $forecaster->settings($this->scenario)->allocation();
        $housing = $forecaster->housingComparison($this->scenario);

        // Mark the big life events on the charts: a vertical annotation at each milestone year
        // for the chosen strategy (deaths / retirements are person-based; the home sale is
        // variant-specific), so the curves show *when* each step change happens.
        if ($presented !== null) {
            $primaryVariant = $this->scenario->variant->value;
            $primaryForecast = $ladderContext->forecasts[$primaryVariant] ?? $forecast;
            $chartMilestones = ResultPresenter::milestones($household, $primaryForecast, in_array($primaryVariant, ['buy_outright', 'rent'], true));
            // Add the milestone verticals to the fan chart's annotations WITHOUT clobbering the
            // below-zero shortfall band ResultPresenter::fan() may already have set on annotations.yaxis.
            $presented['fan']['options']['annotations']['xaxis'] = ResultPresenter::milestoneAnnotations($chartMilestones);
        }

        // "Since your last run": diff the two most recent completed-run snapshots (they survive
        // an input edit, so this shows what a change did, not seed noise) — same strategy only.
        $snapshots = $this->scenario->result_snapshots ?? [];
        $runDiff = [];
        if (count($snapshots) >= 2) {
            $latest = $snapshots[count($snapshots) - 1];
            $prior = $snapshots[count($snapshots) - 2];
            if (($latest['variant'] ?? null) === ($prior['variant'] ?? null)) {
                $runDiff = ResultPresenter::runDiff($latest, $prior);
            }
        }

        // The three hero time-series charts (income staircase / wealth composition / costs)
        // for the selected strategy — built from the SAME deterministic forecast the ladder
        // reads, so they can't drift from it. Overlay the same life-event verticals the ladder
        // milestones mark, so a step change (retirement, State Pension, a sale) is legible.
        $ladderMilestones = ResultPresenter::milestones($household, $ladderForecast, homeSold: $homeSold);
        $timeSeries = ResultPresenter::timeSeriesCharts($ladderForecast);
        $milestoneAnnotations = ResultPresenter::milestoneAnnotations($ladderMilestones);
        foreach (['income', 'wealth', 'costs'] as $chartKey) {
            $timeSeries[$chartKey]['options']['annotations']['xaxis'] = $milestoneAnnotations;
        }

        return view('livewire.scenario-results', [
            'run' => $run,
            'resultsRun' => $resultsRun,
            'runDiff' => $runDiff,
            'presented' => $presented,
            'interpretation' => $interpretation,
            'withdrawal' => $withdrawal,
            // For a what-if (delta-child): what it changed from its base, so the page reads
            // as a variation of the base rather than an independent plan. Null for a base.
            'whatIf' => $this->scenario->isChild() ? [
                'baseName' => $this->scenario->parent->name,
                'baseUrl' => route('scenarios.results', $this->scenario->parent),
                'changes' => WhatIfChanges::of($this->scenario),
                'orphans' => $this->scenario->orphanedOverrides(),
            ] : null,
            // Headline output #1: deterministic, independent of any Monte Carlo run.
            'shock' => app(LumpSumTaxShock::class)->assess($this->scenario),
            // Compare-assumptions overlay: also deterministic, so it shows immediately.
            'sensitivity' => app(AssumptionComparison::class)->compare($this->scenario),
            // The 3-tier spending budget echoed back from the form-state (essential /
            // discretionary / self-investment), reconciling to the forecast's spend.
            'budget' => ResultPresenter::expenseBreakdown($this->scenario->effectiveBuilderState(), $this->scenario->toHousehold()),
            // Where that spending lands against the PLSA Retirement Living Standards
            // (Minimum / Moderate / Comfortable) — on the PLSA basis (excludes rent,
            // includes home running costs), reusing the same ExpenseProfile.
            'plsa' => ResultPresenter::plsaBenchmark($this->scenario->toHousehold()),
            // Essential spending vs secure (guaranteed-for-life) income at the mature point.
            'incomeFloor' => ResultPresenter::incomeFloor($forecast),
            // Historical sequence-of-returns stress test: how the plan would have fared starting
            // into each past year (1929 / 1973-74 / 2000 / 2007). Deterministic, shows pre-run.
            'stressTest' => ResultPresenter::historicalStressTest(
                $forecaster->historicalBacktest($this->scenario),
                $forecaster->settings($this->scenario)->baseYear,
            ),
            // How to claim the Pension Credit the forecast models (only when it credits any) —
            // it is means-tested, so it has to be applied for, and is heavily under-claimed.
            'pensionCredit' => ResultPresenter::pensionCreditGuidance($forecast),
            // Inheritance Tax on the estate at death (only when the IHT toggle is on): the estate
            // valued, the nil-rate bands applied, and the tax due at each death — relationship-
            // status aware. Deterministic, so it shows immediately like the ladder.
            'iht' => ResultPresenter::ihtPanel($forecast->iht, $household),
            // Deterministic year-by-year cashflow ladder (income by source -> tax -> spend
            // -> wealth) for the selected housing strategy. Shows immediately, before any run.
            'ladder' => ResultPresenter::ladder($ladderForecast, $this->scenario->safetyBufferMonths()),
            // The three hero time-series charts for the selected strategy (income / wealth /
            // costs over time), each with a <details> table twin reconciling to the ladder.
            'timeSeries' => $timeSeries,
            // Live what-if sliders: a throwaway deterministic re-forecast with the adjustments applied.
            'canMakeWhatIf' => ! $this->scenario->isChild(),
            'sliderSummary' => $this->sliderSummary(),
            // The housing strategies worth offering + which is selected, for the ladder picker.
            'ladderStrategies' => $ladderContext->strategies,
            'ladderSelected' => $selectedStrategy,
            'ladderSelectedLabel' => ResultPresenter::strategyLabel($selectedStrategy),
            // Life-event milestones (when each person retires / SP starts / takes a pension /
            // dies, and — for a sell strategy — when the home is sold), so the year-by-year
            // cashflow is legible: what drives each step change.
            'milestones' => $ladderMilestones,
            // Input-sanity heads-up where an entered value did something drastic (no salary
            // because retirement age <= current age; a death floored to the base year).
            // The housing action is passed only when the strategy on display actually buys, so a
            // stay-put or sell-and-rent plan is never disclosed a bought home's assumed upkeep,
            // moving costs or depreciation — figures its projection never charges.
            'inputNotes' => ResultPresenter::inputNotes(
                $household,
                $forecast,
                ResultPresenter::housingActionFor($action, $selectedStrategy),
            ),
            // Temporary "new in this build" review markers — the recent additions are mostly
            // new rows / notes inside existing cards, so point at where each one shows. Prune
            // these as they stop being new.
            'whatsNew' => [
                '<strong>Pension Credit</strong> is now modelled — see the <a href="#sec-ladder" class="font-medium underline">year-by-year cashflow</a> and the <a href="#sec-income-floor" class="font-medium underline">secure-income floor</a>.',
                '<strong>Feasibility &amp; input-sanity flags</strong> (mortgage due for redemption, no retirement age) — see <a href="#sec-input-notes" class="font-medium underline">the notes above</a>.',
            ],
            // Show-your-working: the assumptions every figure rests on, and (if a sale is
            // configured) where the sale proceeds come from and go. Both deterministic.
            'assumptions' => ResultPresenter::assumptionsPanel(
                $assumptions,
                $action,
                $allocation,
                $this->scenario->effectiveBuilderState()['assumptionOverrides'] ?? [],
            ),
            // Where the money COMES FROM: the entered income sources and capital pots (the
            // counterpart to the spending plan, which was echoed back from day one while the
            // income side never was), plus how each source switches on and off over time.
            'incomePlan' => ResultPresenter::incomePlan($household, $forecast),
            // The protection gap: what a death next year would do to the survivor, what any
            // employer death-in-service cover would pay, and the life cover that would restore
            // the plan — including what changes when that cover CEASES at retirement. Pinned to
            // the strategy the ladder is showing, so the two cannot disagree about which plan is
            // being stressed. Null for a one-person household (no survivor to protect).
            'protection' => app(ProtectionGap::class)->forScenario($this->scenario, $selectedStrategy),
            // Capacity for loss: how far everything they own could fall before the essential
            // spending floor stops being met. Pinned to the strategy on display, like the
            // protection panel. It reports "already breached" as its own state, because a plan
            // that is short of its essentials has no room at all, which is not the same message
            // as a capacity of nothing left.
            'capacityForLoss' => app(CapacityForLoss::class)->forScenario($this->scenario, $selectedStrategy),
            // What paying for advice would cost this plan: the same projection run twice, once
            // bearing the charges it already bears and once with an adviser's ongoing fee on top.
            // A cost comparison, not a verdict on advice — the panel says what it cannot value.
            'adviceCost' => app(AdviceCostComparison::class)->forScenario($this->scenario, $selectedStrategy),
            // The sale waterfall is shown only when the strategy on display actually SELLS.
            // A stay-put plan was being handed a page of "if you sell" mechanics it does not
            // do, because the presenter only asks whether a sale price was entered — and a
            // base scenario carries one so Compare can run the sell variants.
            'saleExplainer' => $ladderContext->homeSold()
                ? ResultPresenter::saleExplainer(
                    $housing->saleProceeds($household, $action),
                    $housing->buyOutcome($household, $action),
                    $action,
                    $allocation->blendedRealReturn($assumptions),
                    $assumptions->investmentIncomeYield->asFraction(),
                )
                : null,
            // Care isn't modelled unless the toggle is on (the default is off). A default-off
            // ~1-in-4 six-figure risk left off the page with no mention is a silent omission, so
            // say so — and point at the toggle — rather than let "the money lasts" read as if care
            // were free. Suppressed once a run actually models care (the care panel then shows).
            'careNotModelled' => ! $forecaster->settings($this->scenario)->modelCareCost && empty($presented['careImpact'] ?? null),
            // Contextual "get help" panel: the mortgage column shows when this plan involves a
            // mortgage (an owed balance or a buy funded by one); the CGT column when it would sell a
            // home that was ever let (partial-PRR CGT). The pensions & money column always shows.
            'sourcesShowMortgage' => ($household->primaryResidence?->outstandingMortgage?->isPositive() ?? false) || $action->buyMortgageRate !== null,
            // CGT only arises on a disposal, so the CGT signposting follows the sale too — a
            // stay-put plan has no gain to report.
            'sourcesShowCgt' => ($household->primaryResidence?->everLet ?? false) && $ladderContext->homeSold(),
            // Does the strategy on display sell the home? Gates every sale-specific block, so a
            // stay-put plan is never given sale mechanics, buy prices or a rent figure it doesn't use.
            'salePlanned' => $ladderContext->homeSold(),
        ])->title('Forecast results');
    }

    private function runner(): SimulationRunner
    {
        return app(SimulationRunner::class);
    }

    private function currentRun(): ?SimulationRun
    {
        // Scope by owner: $runId is public and tamperable, so a forged id must not load
        // another user's run even though mount() already vetted the scenario.
        return $this->runId
            ? SimulationRun::with('results')->where('user_id', auth()->id())->find($this->runId)
            : null;
    }

    /**
     * The run whose RESULTS are presented: the scenario's latest completed run (the one
     * single source the PDF also reads). It ignores the tamperable $runId entirely and
     * is owner-safe because mount() already vetted the scenario. So a newer
     * failed/cancelled/in-flight run never hides the last good result.
     */
    private function resultsRun(): ?SimulationRun
    {
        return $this->scenario->latestCompletedRun();
    }

    /** @return Collection<string, Result> */
    private function resultsByVariant(SimulationRun $run): Collection
    {
        return $run->results->keyBy(fn (Result $r): string => $r->variant->value);
    }
}
