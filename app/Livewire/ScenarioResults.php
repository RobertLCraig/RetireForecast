<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\SimulationStatus;
use App\Forecast\ResultPresenter;
use App\Forecast\SimulationRunner;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

    public function mount(Scenario $scenario): void
    {
        abort_unless($scenario->user_id === auth()->id(), 403);

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
            fputcsv($out, ['Year', 'P10', 'P25', 'P50', 'P75', 'P90']);
            foreach ($fan['rows'] as $row) {
                fputcsv($out, [$row['year'], $row['p10'], $row['p25'], $row['p50'], $row['p75'], $row['p90']]);
            }
            fclose($out);
        }, "fan-chart-{$fan['variant']}.csv", ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        $run = $this->currentRun();
        $presented = null;

        if ($run && $run->status === SimulationStatus::Done) {
            $presented = ResultPresenter::build($this->resultsByVariant($run), $this->scenario->variant->value);
        }

        return view('livewire.scenario-results', [
            'run' => $run,
            'presented' => $presented,
        ])->title('Forecast results');
    }

    private function runner(): SimulationRunner
    {
        return app(SimulationRunner::class);
    }

    private function currentRun(): ?SimulationRun
    {
        return $this->runId ? SimulationRun::with('results')->find($this->runId) : null;
    }

    /** @return Collection<string, Result> */
    private function resultsByVariant(SimulationRun $run): Collection
    {
        return $run->results->keyBy(fn (Result $r): string => $r->variant->value);
    }
}
