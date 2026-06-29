<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\SimulationStatus;
use App\Models\SimulationRun;
use Tests\TestCase;

/**
 * The "is a worker running?" hint logic: a run that sits queued at 0% past the grace
 * window is the one case where we surface the start-a-worker note. No DB needed — this
 * pins the model predicate directly so the rule holds independently of the Blade wiring.
 */
class SimulationRunWorkerHintTest extends TestCase
{
    public function test_only_a_stale_queued_run_at_zero_progress_awaits_a_worker(): void
    {
        // Queued at 0% beyond the grace window -> probably no worker running.
        $this->assertTrue($this->makeRun(SimulationStatus::Queued, progress: 0, ageSeconds: 30)->isAwaitingWorker());

        // Freshly queued: still within the grace window, so stay quiet (a worker may be about to pick it up).
        $this->assertFalse($this->makeRun(SimulationStatus::Queued, progress: 0, ageSeconds: 2)->isAwaitingWorker());

        // A worker has clearly engaged or finished -> never the hint.
        $this->assertFalse($this->makeRun(SimulationStatus::Queued, progress: 5, ageSeconds: 30)->isAwaitingWorker());
        $this->assertFalse($this->makeRun(SimulationStatus::Running, progress: 0, ageSeconds: 30)->isAwaitingWorker());
        $this->assertFalse($this->makeRun(SimulationStatus::Done, progress: 100, ageSeconds: 30)->isAwaitingWorker());
        $this->assertFalse($this->makeRun(SimulationStatus::Failed, progress: 0, ageSeconds: 30)->isAwaitingWorker());
        $this->assertFalse($this->makeRun(SimulationStatus::Cancelled, progress: 0, ageSeconds: 30)->isAwaitingWorker());
    }

    private function makeRun(SimulationStatus $status, int $progress, int $ageSeconds): SimulationRun
    {
        $run = new SimulationRun(['status' => $status, 'progress_pct' => $progress]);
        $run->created_at = now()->subSeconds($ageSeconds);

        return $run;
    }
}
