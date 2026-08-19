<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Compliance\Interpretation;
use App\DecisionSupport\AdviceCostComparison;
use App\DecisionSupport\ProtectionGap;
use App\Enums\ScenarioStatus;
use App\Export\ChartSvg;
use App\Forecast\AssumptionComparison;
use App\Forecast\LadderContext;
use App\Forecast\LumpSumTaxShock;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Forecast\WhatIfChanges;
use App\Forecast\WithdrawalStrategyComparison;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Money\Money;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams a scenario's results as a downloadable PDF summary.
 *
 * The report is a COMPLETE print of the results page, not a digest: every section the screen
 * renders is assembled here from the SAME {@see ResultPresenter} calls the Livewire component
 * makes, so the printed report cannot drift from what the user saw (the displayed-figure
 * provenance rule), and nothing the reader relied on is silently missing when they share the
 * PDF with family or an adviser. The ScenarioPdfTest feature test holds a per-section
 * completeness guard — it reads the screen's own view data and fails when a section the
 * results page renders is not also exported here — so a new screen section cannot quietly
 * skip the print.
 *
 * The screen's charts are ApexCharts canvases, which dompdf (no JavaScript) cannot draw. They
 * are re-rendered server-side as vector SVG by {@see ChartSvg} from the very option blobs the
 * screen charts are initialised with, so a printed chart plots the identical numbers.
 *
 * Only the genuinely interactive controls are omitted, because they carry no figures to print:
 * the run buttons, the what-if lever sliders, the "How far can we go?" explorer (its sliders
 * start at a lever's mid-range and its limits are queued on demand, so there is nothing to
 * print until the reader drives it) and the assistant panel. The core report is deterministic,
 * so it is always available without a Monte Carlo run; a completed run adds its sections.
 */
class ScenarioPdfController extends Controller
{
    public function download(Scenario $scenario): Response
    {
        abort_unless($scenario->user_id === auth()->id(), 403);
        // A draft has no runnable result; there is nothing to print.
        abort_if($scenario->status === ScenarioStatus::Draft, 404);

        $pdf = Pdf::loadView('pdf.results', ['reports' => [$this->data($scenario)]])->setPaper('a4', 'landscape');

        return $pdf->download("retireforecast-scenario-{$scenario->id}.pdf");
    }

    /**
     * Every ready scenario in one PDF — bases newest-first (the dashboard's order), each
     * followed by its what-if children, one report per page. Drafts have nothing to print
     * and are excluded, as on the single download.
     */
    public function downloadAll(): Response
    {
        $reports = $this->reports(auth()->user());

        abort_if($reports === [], 404);

        $pdf = Pdf::loadView('pdf.results', ['reports' => $reports])->setPaper('a4', 'landscape');

        return $pdf->download('retireforecast-all-scenarios.pdf');
    }

    /**
     * One report data set per ready scenario, in export order. Public so the view-render
     * test exercises the exact data the export produces.
     *
     * @return list<array<string, mixed>>
     */
    public function reports(User $user): array
    {
        return $user->scenarios()
            ->where('status', ScenarioStatus::Ready)
            ->whereNull('parent_scenario_id')
            ->with(['children' => fn ($q) => $q->where('status', ScenarioStatus::Ready)->latest()])
            ->latest()
            ->get()
            ->flatMap(fn (Scenario $base) => collect([$base])->concat($base->children))
            ->map(fn (Scenario $scenario): array => $this->data($scenario))
            ->all();
    }

    /**
     * Assemble the report data — the same variable set App\Livewire\ScenarioResults hands its
     * view, section for section. Public so the view-render test exercises the exact data the
     * controller produces (no second, drift-prone assembly in the test).
     *
     * @return array<string, mixed>
     */
    public function data(Scenario $scenario): array
    {
        $forecaster = app(ScenarioForecaster::class);

        // The cashflow ladder follows the scenario's own chosen strategy, clamped to one the
        // inputs actually configure — through the SAME resolver the results page uses, so the
        // printed ladder can never be projected on a different strategy from the screen's.
        // The income-floor / input-sanity readouts stay on the raw (stay-put) household.
        $ladderContext = LadderContext::for($forecaster, $scenario);
        $ladderForecast = $ladderContext->selectedForecast();
        $forecast = $ladderContext->stayPutForecast();

        $household = $scenario->toHousehold();
        $action = $scenario->toHousingAction();
        $housing = $forecaster->housingComparison($scenario);
        $assumptions = $forecaster->assumptions($scenario);
        $allocation = $forecaster->settings($scenario)->allocation();

        // The SAME run the results page presents (latest completed), so the PDF can never
        // print a Monte Carlo summary the screen is hiding. Two presentations, not one: on
        // screen the fan's basis is a live "Include home value" checkbox, and a printed page
        // cannot be toggled — so the report prints BOTH bases as separate charts (spendable
        // money excluding the home, then total wealth including home equity) rather than
        // silently picking one and dropping the other view the reader can see on screen.
        [$presented, $presentedTotal, $mcRun] = $this->monteCarlo($scenario, $household);

        // Life-event milestones drive both the timeline table and the dated verticals overlaid
        // on every chart; one list, so a numbered marker on a chart always resolves in the table.
        $milestones = ResultPresenter::milestones($household, $ladderForecast, homeSold: $ladderContext->homeSold());
        $milestoneAnnotations = ResultPresenter::milestoneAnnotations($milestones);

        $timeSeries = ResultPresenter::timeSeriesCharts($ladderForecast);
        foreach (['income', 'wealth', 'costs'] as $chartKey) {
            $timeSeries[$chartKey]['options']['annotations']['xaxis'] = $milestoneAnnotations;
        }

        // Both fans carry the primary variant's milestones, matching the screen (which reads
        // the scenario's own variant there, not the ladder's clamped selection).
        if ($presented !== null) {
            $primaryVariant = $scenario->variant->value;
            $primaryForecast = $ladderContext->forecasts[$primaryVariant] ?? $forecast;
            $fanAnnotations = ResultPresenter::milestoneAnnotations(
                ResultPresenter::milestones($household, $primaryForecast, in_array($primaryVariant, ['buy_outright', 'rent'], true)),
            );
            $presented['fan']['options']['annotations']['xaxis'] = $fanAnnotations;
            $presentedTotal['fan']['options']['annotations']['xaxis'] = $fanAnnotations;
        }

        // Withdrawal sequencing: what the household's draw order costs in lifetime tax vs
        // filling the tax-free bands first. Same shape the screen partial reads.
        $comparison = WithdrawalStrategyComparison::for($forecaster, $scenario);
        $withdrawal = $comparison->panel();
        if ($withdrawal !== null) {
            $withdrawal['steer'] = Gate::allows('interpret') ? Interpretation::withdrawalSequencingNarrative($comparison) : null;
        }

        // "Since your last run": the same two-snapshot diff the screen shows.
        $snapshots = $scenario->result_snapshots ?? [];
        $runDiff = [];
        if (count($snapshots) >= 2) {
            $latest = $snapshots[count($snapshots) - 1];
            $prior = $snapshots[count($snapshots) - 2];
            if (($latest['variant'] ?? null) === ($prior['variant'] ?? null)) {
                $runDiff = ResultPresenter::runDiff($latest, $prior);
            }
        }

        return [
            'scenario' => $scenario,
            'householdName' => $scenario->householdName(),
            'generatedAt' => now()->format('j F Y'),
            // For a what-if (delta-child): what it changed from its base, so the printed report
            // reads as a variation of the base rather than an independent plan.
            'whatIf' => $scenario->isChild() ? [
                'baseName' => $scenario->parent->name,
                'changes' => WhatIfChanges::of($scenario),
                'orphans' => $scenario->orphanedOverrides(),
            ] : null,
            // Input-sanity + assumed-figure disclosure notes. These carry the "no invisible
            // figures" disclosures, so dropping them from the print would leave the reader
            // with figures they cannot interrogate — exactly what the hard rule forbids.
            // The housing action is passed only when the strategy in this report actually buys, so
            // a stay-put or sell-and-rent plan is never disclosed a bought home's assumed upkeep,
            // moving costs or depreciation — figures its projection never charges.
            'inputNotes' => ResultPresenter::inputNotes(
                $household,
                $forecast,
                ResultPresenter::housingActionFor($action, $ladderContext->selected),
            ),
            'runDiff' => $runDiff,
            // Care isn't modelled unless the toggle is on; say so rather than let "the money
            // lasts" read as if care were free.
            'careNotModelled' => ! $forecaster->settings($scenario)->modelCareCost && empty($presented['careImpact'] ?? null),
            // Monte Carlo headline + longevity / care / IHT spread + the run's provenance, only
            // if a completed run exists, so a 1,000-path preview can't masquerade as the 10k report.
            'presented' => $presented,
            // The second (total-wealth) basis of the same run — the screen's checked "Include
            // home value" state, printed as its own chart and table.
            'presentedTotal' => $presentedTotal,
            'mcRun' => $mcRun,
            'fanChart' => $presented === null ? null : ChartSvg::dataUri($presented['fan']['options']),
            'fanChartTotal' => $presentedTotal === null ? null : ChartSvg::dataUri($presentedTotal['fan']['options']),
            // Advice-style readouts only for a gate-allowed user; the public default stays neutral.
            'interpretation' => $presented === null || ! Gate::allows('interpret')
                ? null
                : Interpretation::readouts($this->resultsByVariant($scenario->latestCompletedRun())),
            'shock' => app(LumpSumTaxShock::class)->assess($scenario),
            'sensitivity' => app(AssumptionComparison::class)->compare($scenario),
            'budget' => ResultPresenter::expenseBreakdown($scenario->effectiveBuilderState(), $household),
            'plsa' => ResultPresenter::plsaBenchmark($household),
            'incomeFloor' => ResultPresenter::incomeFloor($forecast),
            'pensionCredit' => ResultPresenter::pensionCreditGuidance($forecast),
            // Inheritance Tax on the estate (only when the toggle is on) — same deterministic
            // source as the screen, so the printed figure matches.
            'iht' => ResultPresenter::ihtPanel($forecast->iht, $household),
            'withdrawal' => $withdrawal,
            // Historical sequence-of-returns stress test: how the plan would have fared starting
            // into each past year. Deterministic, so it prints without a run.
            'stressTest' => ResultPresenter::historicalStressTest(
                $forecaster->historicalBacktest($scenario),
                $forecaster->settings($scenario)->baseYear,
            ),
            // Show-your-working: the assumptions every figure in this report rests on.
            'assumptions' => ResultPresenter::assumptionsPanel(
                $assumptions,
                $action,
                $allocation,
                $scenario->effectiveBuilderState()['assumptionOverrides'] ?? [],
            ),
            // Where the money COMES FROM: the entered income sources and capital pots, plus how
            // each source switches on and off across the projection.
            'incomePlan' => ResultPresenter::incomePlan($household, $forecast),
            // The protection gap: what a death next year does to the survivor, what any employer
            // death-in-service cover pays, and the cover that would restore the plan — pinned to
            // the same strategy this report prints, as on screen.
            'protection' => app(ProtectionGap::class)->forScenario($scenario, $ladderContext->selected),
            // What paying for advice would cost this plan — the same two runs as on screen.
            'adviceCost' => app(AdviceCostComparison::class)->forScenario($scenario, $ladderContext->selected),
            // Where a sale's proceeds come from and go — the funding waterfall (net proceeds,
            // savings drawn, mortgage, unfunded gap), single-sourced from the engine's own
            // decomposition, exactly as the results page shows it. Printed ONLY when the
            // strategy in this report actually sells: a base scenario carries a sale price so
            // Compare can run the sell variants, which was giving a stay-put plan a page of
            // "if you sell" mechanics it does not do.
            'salePlanned' => $ladderContext->homeSold(),
            'saleExplainer' => $ladderContext->homeSold()
                ? ResultPresenter::saleExplainer(
                    $housing->saleProceeds($household, $action),
                    $housing->buyOutcome($household, $action),
                    $action,
                    $allocation->blendedRealReturn($assumptions),
                    $assumptions->investmentIncomeYield->asFraction(),
                )
                : null,
            'milestones' => $milestones,
            // The three hero time-series charts (income staircase / wealth composition / costs),
            // each printed as the picture PLUS its full table twin, as on screen.
            'timeSeries' => $timeSeries,
            'timeSeriesCharts' => [
                'income' => ChartSvg::dataUri($timeSeries['income']['options']),
                'wealth' => ChartSvg::dataUri($timeSeries['wealth']['options']),
                'costs' => ChartSvg::dataUri($timeSeries['costs']['options']),
            ],
            'ladder' => ResultPresenter::ladder($ladderForecast, $scenario->safetyBufferMonths()),
            'ladderSelectedLabel' => $ladderContext->selectedLabel(),
            // Contextual "get help" contacts: mortgage line when this plan involves a mortgage, CGT
            // line when it would sell a home that was ever let (partial-PRR CGT).
            'sourcesShowMortgage' => ($household->primaryResidence?->outstandingMortgage?->isPositive() ?? false) || $action->buyMortgageRate !== null,
            // CGT only arises on a disposal, so the CGT signposting follows the sale too.
            'sourcesShowCgt' => ($household->primaryResidence?->everLet ?? false) && $ladderContext->homeSold(),
        ];
    }

    /**
     * The Monte Carlo presentation on BOTH wealth bases, plus the producing run's provenance,
     * from the scenario's latest completed run (the one source the results page also reads).
     * The household is passed so the fan's table and axis carry the people's ages, as on screen.
     *
     * Two presentations because the screen's basis is an interactive checkbox: excluding the
     * home (the honest "will it last" view, the screen default) and including home equity (the
     * net-worth view). A printed page cannot toggle, so the report carries both.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null, 2: array<string, mixed>|null}
     */
    private function monteCarlo(Scenario $scenario, Household $household): array
    {
        $run = $scenario->latestCompletedRun();

        if (! $run instanceof SimulationRun) {
            return [null, null, null];
        }

        $results = $this->resultsByVariant($run);
        $build = fn (bool $includeHome): array => ResultPresenter::build(
            $results,
            $scenario->variant->value,
            includeHome: $includeHome,
            household: $household,
        );

        $mcRun = [
            'mode' => $run->mode->value,
            'paths' => $run->n_paths,
            'seed' => $run->seed,
            'date' => $run->updated_at?->format('j F Y'),
        ];

        return [$build(false), $build(true), $mcRun];
    }

    /** @return Collection<string, Result> */
    private function resultsByVariant(SimulationRun $run): Collection
    {
        return $run->results->keyBy(fn (Result $r): string => $r->variant->value);
    }
}
