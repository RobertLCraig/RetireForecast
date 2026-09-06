<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SimulationStatus;
use App\Forecast\SimulationRunner;
use App\Models\SimulationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Runs a queued full simulation on the worker. Holds only the run id (so the job
 * payload stays small and always reads current state); delegates the work to
 * {@see SimulationRunner}, which reports progress and honours cancellation.
 */
class RunScenarioSimulation implements ShouldQueue
{
    use Queueable;

    /**
     * How long a full 10,000-path run is allowed to take before the worker kills it.
     *
     * A worker allows 60 seconds to a job that names nothing, which a full run passes long
     * before it finishes. That has never bitten on this machine because **Windows has no
     * `pcntl`**, so the worker here cannot enforce a timeout at all — it bites the moment the
     * same code runs anywhere that has one (CI, Docker, WSL, any Linux host), where every
     * full run is killed part-way and marked failed. An hour is a deliberate ceiling rather
     * than a measurement: it is far above any run this tool produces, and it still ends a run
     * that has hung instead of holding a worker for ever.
     *
     * `config('queue.connections.database.retry_after')` MUST stay above this, or the queue
     * offers a still-running job to a second worker. `QueuedRunSafetyTest` holds that.
     */
    public int $timeout = 3600;

    /**
     * One attempt. A killed or crashed run is not quietly started again from the top: it goes
     * to {@see failed()}, which lands it in a terminal Failed status with the reason on it, so
     * the reader is told rather than left watching a progress bar restart itself.
     */
    public int $tries = 1;

    /** A run that hits the timeout has failed; do not release it back for another go. */
    public bool $failOnTimeout = true;

    public function __construct(public readonly int $simulationRunId) {}

    /**
     * One worker per run record. A worker restarted mid-run (board card 0009 is literally that)
     * used to pick the same still-reserved job up and run it BESIDE the first one: two runs
     * writing results for one record and fighting over its progress counter. The lock is on the
     * run id, so a different run is never held up, and it outlives {@see $timeout} so it cannot
     * expire under a run that is still going.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->simulationRunId))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

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
