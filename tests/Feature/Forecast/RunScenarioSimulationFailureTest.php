<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Jobs\RunScenarioSimulation;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * No silent failure: a dead worker (timeout, OOM, killed) must not leave a run stuck in
 * Running while the page polls forever. The job's failed() handler lands it in a terminal
 * Failed status with a reason.
 */
class RunScenarioSimulationFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_job_marks_a_live_run_failed_with_a_reason(): void
    {
        $run = $this->queuedRun();

        (new RunScenarioSimulation($run->id))->failed(new RuntimeException('worker died'));

        $run->refresh();
        $this->assertSame(SimulationStatus::Failed, $run->status);
        $this->assertSame('worker died', $run->error);
        $this->assertNotNull($run->finished_at);
    }

    public function test_failed_uses_a_fallback_reason_when_none_is_given(): void
    {
        $run = $this->queuedRun();

        (new RunScenarioSimulation($run->id))->failed(null);

        $this->assertSame(SimulationStatus::Failed, $run->fresh()->status);
        $this->assertNotEmpty($run->fresh()->error);
    }

    public function test_failed_does_not_overwrite_an_already_terminal_run(): void
    {
        $run = $this->queuedRun();
        $run->update(['status' => SimulationStatus::Done]);

        (new RunScenarioSimulation($run->id))->failed(new RuntimeException('late failure'));

        $this->assertSame(SimulationStatus::Done, $run->fresh()->status);
        $this->assertNull($run->fresh()->error);
    }

    private function queuedRun(): SimulationRun
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        return (new SimulationRunner(new ScenarioForecaster))
            ->createRun($scenario, SimulationMode::Full, seed: 1);
    }
}
