<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Compliance\Interpretation;
use App\Enums\ScenarioStatus;
use App\Forecast\AssumptionComparison;
use App\Forecast\LumpSumTaxShock;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
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

    /** Prepended to every CSV export so a downloaded figure never travels without its disclaimer. */
    private const EXPORT_DISCLAIMER = [
        'RetireForecast — guidance only, not financial advice.',
        'These figures illustrate the consequences of the inputs and assumptions you entered; they are not a personal recommendation.',
        'Free, impartial guidance: Pension Wise and MoneyHelper (moneyhelper.org.uk), or an FCA-regulated adviser.',
    ];

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
            foreach (self::EXPORT_DISCLAIMER as $line) {
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

    public function downloadLadderCsv(): StreamedResponse
    {
        $ladder = ResultPresenter::ladder(app(ScenarioForecaster::class)->deterministic($this->scenario));

        return response()->streamDownload(function () use ($ladder): void {
            $out = fopen('php://output', 'wb');
            foreach (self::EXPORT_DISCLAIMER as $line) {
                fputcsv($out, [$line]);
            }
            fputcsv($out, []);
            $header = ['Year', 'Age(s)'];
            foreach ($ladder['sources'] as $source) {
                $header[] = $ladder['sourceLabels'][$source];
            }
            $header = [...$header, 'Tax', 'Spend', 'Essential spend', 'Discretionary spend', 'Unmet spend', 'Usable wealth (excl. home)', 'Total wealth (incl. home)'];
            fputcsv($out, $header);

            foreach ($ladder['rows'] as $row) {
                $line = [$row['year'], $row['ages']];
                foreach ($ladder['sources'] as $source) {
                    $line[] = $row['income'][$source];
                }
                $line = [...$line, $row['tax'], $row['spend'], $row['essentialSpend'], $row['discretionarySpend'], $row['shortfall'] ?? '', $row['usableWealth'], $row['totalWealth']];
                fputcsv($out, $line);
            }
            fclose($out);
        }, 'cashflow-ladder.csv', ['Content-Type' => 'text/csv']);
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

        // One deterministic central projection feeds both the cashflow ladder and the
        // income-floor readout, so they read the same single source (no second run).
        $forecast = $forecaster->deterministic($this->scenario);

        // The deterministic home-sale decomposition + the assumptions behind it, so every
        // headline figure traces to its inputs (show-your-working). Single-sourced from the
        // engine (HousingProceeds / HousingPurchase) and reconciled to the forecast.
        $household = $this->scenario->toHousehold();
        $action = $this->scenario->toHousingAction();
        $assumptions = $forecaster->assumptions($this->scenario);
        $allocation = $forecaster->settings($this->scenario)->allocation();
        $housing = $forecaster->housingComparison($this->scenario);

        return view('livewire.scenario-results', [
            'run' => $run,
            'resultsRun' => $resultsRun,
            'presented' => $presented,
            'interpretation' => $interpretation,
            // Headline output #1: deterministic, independent of any Monte Carlo run.
            'shock' => app(LumpSumTaxShock::class)->assess($this->scenario),
            // Compare-assumptions overlay: also deterministic, so it shows immediately.
            'sensitivity' => app(AssumptionComparison::class)->compare($this->scenario),
            // The 3-tier spending budget echoed back from the form-state (essential /
            // discretionary / self-investment), reconciling to the forecast's spend.
            'budget' => ResultPresenter::expenseBreakdown($this->scenario->effectiveBuilderState()),
            // Where that spending lands against the PLSA Retirement Living Standards
            // (Minimum / Moderate / Comfortable) — on the PLSA basis (excludes rent,
            // includes home running costs), reusing the same ExpenseProfile.
            'plsa' => ResultPresenter::plsaBenchmark($this->scenario->toHousehold()),
            // Essential spending vs secure (guaranteed-for-life) income at the mature point.
            'incomeFloor' => ResultPresenter::incomeFloor($forecast),
            // Deterministic year-by-year cashflow ladder (income by source -> tax -> spend
            // -> wealth). Shows immediately, before any Monte Carlo run.
            'ladder' => ResultPresenter::ladder($forecast),
            // Show-your-working: the assumptions every figure rests on, and (if a sale is
            // configured) where the sale proceeds come from and go. Both deterministic.
            'assumptions' => ResultPresenter::assumptionsPanel($assumptions, $action, $allocation),
            'saleExplainer' => ResultPresenter::saleExplainer(
                $housing->saleProceeds($household, $action),
                $housing->buyOutcome($household, $action),
                $action,
                $allocation->blendedRealReturn($assumptions),
                $assumptions->investmentIncomeYield->asFraction(),
            ),
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
