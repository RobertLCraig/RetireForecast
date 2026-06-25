<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Compliance\Interpretation;
use App\Enums\ScenarioStatus;
use App\Enums\SimulationStatus;
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
        $run = $this->currentRun();
        if (! $run || $run->status !== SimulationStatus::Done) {
            return null;
        }

        $presented = ResultPresenter::build($this->resultsByVariant($run), $this->scenario->variant->value);
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
            $header = [...$header, 'Tax', 'Spend', 'Unmet spend', 'Usable wealth (excl. home)', 'Total wealth (incl. home)'];
            fputcsv($out, $header);

            foreach ($ladder['rows'] as $row) {
                $line = [$row['year'], $row['ages']];
                foreach ($ladder['sources'] as $source) {
                    $line[] = $row['income'][$source];
                }
                $line = [...$line, $row['tax'], $row['spend'], $row['shortfall'] ?? '', $row['usableWealth'], $row['totalWealth']];
                fputcsv($out, $line);
            }
            fclose($out);
        }, 'cashflow-ladder.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        $run = $this->currentRun();
        $presented = null;
        $interpretation = null;

        if ($run && $run->status === SimulationStatus::Done) {
            $resultsByVariant = $this->resultsByVariant($run);
            $presented = ResultPresenter::build($resultsByVariant, $this->scenario->variant->value);

            // Advice-style readouts only for an admin-granted user; the public default
            // stays neutral. The directive wording lives solely in Interpretation.
            if (Gate::allows('interpret')) {
                $interpretation = Interpretation::readouts($resultsByVariant);
            }
        }

        return view('livewire.scenario-results', [
            'run' => $run,
            'presented' => $presented,
            'interpretation' => $interpretation,
            // Headline output #1: deterministic, independent of any Monte Carlo run.
            'shock' => app(LumpSumTaxShock::class)->assess($this->scenario),
            // Compare-assumptions overlay: also deterministic, so it shows immediately.
            'sensitivity' => app(AssumptionComparison::class)->compare($this->scenario),
            // Deterministic year-by-year cashflow ladder (income by source -> tax -> spend
            // -> wealth). Shows immediately, before any Monte Carlo run.
            'ladder' => ResultPresenter::ladder(app(ScenarioForecaster::class)->deterministic($this->scenario)),
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

    /** @return Collection<string, Result> */
    private function resultsByVariant(SimulationRun $run): Collection
    {
        return $run->results->keyBy(fn (Result $r): string => $r->variant->value);
    }
}
