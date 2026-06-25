<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SimulationStatus;
use App\Forecast\SimulationRunner;
use App\Models\SimulationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a queued full simulation on the worker. Holds only the run id (so the job
 * payload stays small and always reads current state); delegates the work to
 * {@see SimulationRunner}, which reports progress and honours cancellation.
 */
class RunScenarioSimulation implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $simulationRunId) {}

    public function handle(SimulationRunner $runner): void
    {
        $run = SimulationRun::find($this->simulationRunId);

        if ($run !== null) {
            $runner->execute($run);
        }
    }

    /**
     * A dead worker (timeout, OOM, killed) must not strand a run in Running while the
     * page polls forever. Mark it Failed with the reason so the status is terminal and
     * the UI stops waiting — no silent failure.
     */
    public function failed(?Throwable $e): void
    {
        $run = SimulationRun::find($this->simulationRunId);

        if ($run !== null && ! $run->status->isTerminal()) {
            $run->update([
                'status' => SimulationStatus::Failed,
                'error' => $e?->getMessage() ?? 'The forecast worker stopped unexpectedly.',
                'finished_at' => now(),
            ]);
        }
    }
}
