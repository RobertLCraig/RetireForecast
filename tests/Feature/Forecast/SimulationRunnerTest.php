<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Jobs\RunScenarioSimulation;
use App\Models\AssumptionSet;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use Tests\Support\ScenarioFixture;
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
        return ScenarioFixture::rich(User::factory()->create());
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

        // Built through createRun + execute, not preview(), so the inputs-hash cache cannot
        // answer the second request with the first run: this must compare two real computations.
        $a = $runner->createRun($scenario, SimulationMode::Preview, seed: 9, paths: 30);
        $runner->execute($a);
        $b = $runner->createRun($scenario, SimulationMode::Preview, seed: 9, paths: 30);
        $runner->execute($b);

        $this->assertNotSame($a->id, $b->id);

        $rentA = Result::where('simulation_run_id', $a->id)->where('variant', 'rent')->firstOrFail();
        $rentB = Result::where('simulation_run_id', $b->id)->where('variant', 'rent')->firstOrFail();

        $this->assertSame(
            $rentA->simulationResult()->terminalWealthPercentiles['p50']->pence,
            $rentB->simulationResult()->terminalWealthPercentiles['p50']->pence,
        );
    }

    public function test_an_unchanged_scenario_is_not_recomputed(): void
    {
        $scenario = $this->scenario();
        $runner = $this->runner();

        $first = $runner->preview($scenario, paths: 20);
        $second = $runner->preview($scenario, paths: 20);

        // The same run handed back, with no second set of results computed behind it.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SimulationRun::count());
        $this->assertSame(3, Result::count());
    }

    public function test_editing_the_inputs_misses_the_cache_and_recomputes(): void
    {
        $scenario = $this->scenario();
        $runner = $this->runner();

        $first = $runner->preview($scenario, paths: 20);

        $state = $scenario->builder_state;
        $state['expenseLines'][0]['amount'] = '31234';
        $scenario->fillFromBuilderState($state)->save();

        $second = $runner->preview($scenario->fresh(), paths: 20);

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_second_click_does_not_queue_a_duplicate_full_run(): void
    {
        Queue::fake();
        $scenario = $this->scenario();
        $runner = $this->runner();

        $first = $runner->dispatch($scenario);
        $second = $runner->dispatch($scenario);

        // The in-flight run is handed back rather than a twin queued beside it.
        $this->assertSame($first->id, $second->id);
        Queue::assertPushed(RunScenarioSimulation::class, 1);
        $this->assertSame(1, SimulationRun::count());
    }

    public function test_an_edited_assumption_set_misses_the_cache(): void
    {
        // The form-state alone would not see this: the scenario still points at the same
        // assumption set, and only the figures inside that row moved. The hash covers the
        // assumptions the run actually froze, so an admin edit cannot be answered with a
        // result computed under the old ones.
        $set = AssumptionSet::fromDto(AssumptionSetLibrary::default());
        $set->save();
        $user = User::factory()->create();
        $scenario = ScenarioFixture::rich($user, ['assumptionSetId' => $set->id]);
        $runner = $this->runner();

        $first = $runner->preview($scenario, paths: 20);

        $payload = $set->payload;
        $payload['inflationMean'] += 100; // +1 percentage point
        $set->payload = $payload;
        $set->save();

        $second = $runner->preview($scenario->fresh(), paths: 20);

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_a_completed_run_is_stamped_and_the_stamp_catches_a_tampered_figure(): void
    {
        $run = $this->runner()->preview($this->scenario(), seed: 1, paths: 20);

        $this->assertNotNull($run->integrity_hash);
        $this->assertTrue($run->isIntact());

        // Doctor a stored figure the way a database edit would.
        $result = $run->results()->where('variant', 'rent')->firstOrFail();
        $payload = $result->payload;
        $payload['successProbabilityEssentials'] = 1.0;
        $result->payload = $payload;
        $result->save();

        $this->assertFalse($run->fresh()->isIntact());
    }

    public function test_the_stamp_also_catches_a_rewritten_provenance_column(): void
    {
        $run = $this->runner()->preview($this->scenario(), seed: 1, paths: 20);

        // Re-labelling which engine produced a result is tampering too: the figures would then
        // read as comparable with runs they are not comparable with.
        SimulationRun::withoutEvents(fn () => SimulationRun::query()
            ->whereKey($run->id)
            ->update(['engine_version' => 'finance-engine/not-the-one-that-ran']));

        $this->assertFalse($run->fresh()->isIntact());
    }

    public function test_a_lifecycle_change_does_not_read_as_tampering(): void
    {
        $run = $this->runner()->preview($this->scenario(), seed: 1, paths: 20);

        // Status, progress and timestamps are lifecycle, not evidence, so moving them must not
        // fire the tamper check, or every legitimate state change would cry wolf.
        $run->update([
            'status' => SimulationStatus::Cancelled,
            'progress_pct' => 42,
            'finished_at' => now()->addHour(),
        ]);

        $this->assertTrue($run->fresh()->isIntact());
    }
}
