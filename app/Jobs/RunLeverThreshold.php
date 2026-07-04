<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DecisionSupport\ThresholdRunner;
use App\Enums\SimulationStatus;
use App\Models\ThresholdResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a queued lever-threshold sweep on the worker. Holds only the record id (so the job
 * payload stays small and always reads current state); delegates the work to
 * {@see ThresholdRunner}, which reports progress and honours cancellation. Mirrors
 * {@see RunScenarioSimulation}.
 */
class RunLeverThreshold implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $thresholdResultId) {}

    public function handle(ThresholdRunner $runner): void
    {
        $run = ThresholdResult::find($this->thresholdResultId);

        if ($run !== null) {
            $runner->execute($run);
        }
    }

    /**
     * A dead worker (timeout, OOM, killed) must not strand a threshold in Running while the
     * page polls forever. Mark it Failed with the reason so the status is terminal and the UI
     * stops waiting — no silent failure.
     */
    public function failed(?Throwable $e): void
    {
        $run = ThresholdResult::find($this->thresholdResultId);

        if ($run !== null && ! $run->status->isTerminal()) {
            $run->update([
                'status' => SimulationStatus::Failed,
                'error' => $e?->getMessage() ?? 'The threshold worker stopped unexpectedly.',
                'finished_at' => now(),
            ]);
        }
    }
}
