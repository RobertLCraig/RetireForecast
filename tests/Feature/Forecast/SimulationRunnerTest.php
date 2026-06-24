<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Jobs\RunScenarioSimulation;
use App\Models\Household;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HouseholdFixture;
use Tests\TestCase;

class SimulationRunnerTest extends TestCase
{
    use RefreshDatabase;

    private function runner(): SimulationRunner
    {
        return new SimulationRunner(new ScenarioForecaster);
    }

    private function scenario(): Scenario
    {
        $user = User::factory()->create();
        $household = Household::fromDto(HouseholdFixture::household(), $user->id);
        $household->save();

        $scenario = new Scenario([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'name' => 'Buy-vs-rent',
            'variant' => ScenarioVariant::Rent,
            'base_tax_year' => '2026-27',
            'iht_modelled' => false,
            'status' => ScenarioStatus::Ready,
        ]);
        $scenario->setHousingAction(HouseholdFixture::housingAction());
        $scenario->save();

        return $scenario->fresh();
    }

    public function test_a_preview_runs_synchronously_persists_three_results_and_completes(): void
    {
        $run = $this->runner()->preview($this->scenario(), seed: 1, paths: 30);

        $this->assertSame(SimulationStatus::Done, $run->status);
        $this->assertSame(100, $run->progress_pct);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(3, Result::where('simulation_run_id', $run->id)->count());
        $this->assertSame(1, $run->seed);
    }

    public function test_the_full_run_is_queued_not_run_inline(): void
    {
        Queue::fake();

        $run = $this->runner()->dispatch($this->scenario(), seed: 5);

        Queue::assertPushed(RunScenarioSimulation::class);
        $this->assertSame(SimulationStatus::Queued, $run->status);
        $this->assertSame(SimulationMode::Full, $run->mode);
        $this->assertSame(10_000, $run->n_paths);
        $this->assertSame(0, Result::count());
    }

    public function test_the_job_executes_a_run_to_completion(): void
    {
        $scenario = $this->scenario();
        $run = $this->runner()->createRun($scenario, SimulationMode::Full, seed: 5, paths: 20);

        (new RunScenarioSimulation($run->id))->handle($this->runner());

        $run->refresh();
        $this->assertSame(SimulationStatus::Done, $run->status);
        $this->assertSame(3, $run->results()->count());
    }

    public function test_cancelling_before_a_run_starts_stops_it_and_writes_no_results(): void
    {
        $runner = $this->runner();
        $run = $runner->createRun($this->scenario(), SimulationMode::Preview, seed: 1, paths: 20);

        $runner->cancel($run);
        $this->assertSame(SimulationStatus::Cancelled, $run->fresh()->status);

        $runner->execute($run->fresh());

        $this->assertSame(SimulationStatus::Cancelled, $run->fresh()->status);
        $this->assertSame(0, Result::where('simulation_run_id', $run->id)->count());
    }

    public function test_two_runs_with_the_same_seed_are_reproducible(): void
    {
        $scenario = $this->scenario();
        $runner = $this->runner();

        $a = $runner->preview($scenario, seed: 9, paths: 30);
        $b = $runner->preview($scenario, seed: 9, paths: 30);

        $rentA = Result::where('simulation_run_id', $a->id)->where('variant', 'rent')->firstOrFail();
        $rentB = Result::where('simulation_run_id', $b->id)->where('variant', 'rent')->firstOrFail();

        $this->assertSame(
            $rentA->simulationResult()->terminalWealthPercentiles['p50']->pence,
            $rentB->simulationResult()->terminalWealthPercentiles['p50']->pence,
        );
    }
}
