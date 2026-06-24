<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Forecast\SimulationRunner;
use App\Models\SimulationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
}
