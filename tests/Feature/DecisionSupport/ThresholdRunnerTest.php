<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\LeverKey;
use App\DecisionSupport\LeverThresholdService;
use App\DecisionSupport\ThresholdRunner;
use App\Enums\SimulationStatus;
use App\Forecast\ScenarioForecaster;
use App\Jobs\RunLeverThreshold;
use App\Models\Scenario;
use App\Models\ThresholdResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use RuntimeException;
use Tests\Feature\Forecast\SimulationRunnerTest;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The queued threshold runner (Phase 1): a scenario computes a lever threshold on the worker
 * with live progress, an identical re-request is a cache hit, and every record carries its
 * provenance. Mirrors {@see SimulationRunnerTest} — the sweep is a long
 * run, so nothing about it runs silently.
 *
 * The test queue connection is sync, so `request()` runs the sweep inline through the real
 * dispatch -> job -> runner path. Grids and path counts are kept tiny for speed.
 */
final class ThresholdRunnerTest extends TestCase
{
    use RefreshDatabase;

    private const GRID = [62.0, 66.0, 70.0];

    private function runner(): ThresholdRunner
    {
        return app(ThresholdRunner::class);
    }

    private function scenario(): Scenario
    {
        return ScenarioFixture::rich(User::factory()->create());
    }

    private function request(Scenario $scenario, ?array $grid = null, int $paths = 40): ThresholdResult
    {
        return $this->runner()->request(
            $scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, $grid ?? self::GRID, $paths,
        );
    }

    public function test_request_queues_the_sweep_and_stamps_provenance(): void
    {
        Queue::fake();
        $scenario = $this->scenario();

        $run = $this->request($scenario);

        Queue::assertPushed(RunLeverThreshold::class, fn (RunLeverThreshold $job): bool => $job->thresholdResultId === $run->id);

        $this->assertSame(SimulationStatus::Queued, $run->status);
        $this->assertSame(LeverKey::RetirementAge->value, $run->lever_key);
        $this->assertSame(SweepMetric::Essentials->value, $run->metric);
        $this->assertSame(0.90, $run->target_probability);
        $this->assertSame(40, $run->n_paths);
        $this->assertSame(LeverThresholdService::SEED, $run->seed);
        $this->assertEquals(self::GRID, $run->grid);
        $this->assertSame(ScenarioForecaster::ENGINE_VERSION, $run->engine_version);
        $this->assertNotEmpty($run->inputs_hash);
        $this->assertNotEmpty($run->assumption_snapshot); // frozen for reproducibility
        $this->assertNull($run->thresholdOutcome());       // nothing computed yet
    }

    public function test_the_sweep_runs_to_completion_with_progress_and_a_curve(): void
    {
        $run = $this->request($this->scenario())->fresh();

        $this->assertSame(SimulationStatus::Done, $run->status);
        $this->assertSame(100, $run->progress_pct);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        $outcome = $run->thresholdOutcome();
        $this->assertNotNull($outcome);
        $this->assertCount(3, $outcome->curve->points);           // one point per grid value
        $this->assertSame(LeverThresholdService::SEED, $outcome->curve->seed);
        $this->assertInstanceOf(CrossingVerdict::class, $outcome->crossing->verdict);
    }

    public function test_the_job_handle_runs_a_queued_sweep_to_completion(): void
    {
        $scenario = $this->scenario();
        $runner = $this->runner();
        $hash = $runner->inputsHash($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, self::GRID, 40);
        $run = $runner->createRun($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, self::GRID, 40, $hash);

        (new RunLeverThreshold($run->id))->handle($runner);

        $this->assertSame(SimulationStatus::Done, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->thresholdOutcome());
    }

    public function test_re_requesting_identical_inputs_is_a_cache_hit(): void
    {
        $scenario = $this->scenario();

        $first = $this->request($scenario)->fresh();
        $this->assertSame(SimulationStatus::Done, $first->status);

        $second = $this->request($scenario);

        $this->assertSame($first->id, $second->id);            // the same stored result
        $this->assertSame(1, ThresholdResult::count());        // no second sweep queued
    }

    public function test_a_changed_parameter_is_a_cache_miss(): void
    {
        $scenario = $this->scenario();

        $this->request($scenario, paths: 40);
        $this->request($scenario, paths: 60); // different paths -> different inputs hash

        $this->assertSame(2, ThresholdResult::count());
    }

    public function test_cancelling_before_it_starts_stops_it_and_stores_no_curve(): void
    {
        $scenario = $this->scenario();
        $runner = $this->runner();
        $hash = $runner->inputsHash($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, self::GRID, 40);
        $run = $runner->createRun($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, self::GRID, 40, $hash);

        $runner->cancel($run);
        $this->assertSame(SimulationStatus::Cancelled, $run->fresh()->status);

        $runner->execute($run->fresh());

        $this->assertSame(SimulationStatus::Cancelled, $run->fresh()->status);
        $this->assertNull($run->fresh()->thresholdOutcome());
    }

    public function test_a_frontier_request_runs_to_completion_and_round_trips_its_map(): void
    {
        $scenario = $this->scenario();

        // Sync queue: the request runs the whole frontier inline through dispatch -> job -> runner.
        $run = $this->runner()->requestFrontier(
            $scenario, LeverKey::BuyPrice, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90,
            thresholdGrid: [150_000.0, 250_000.0], conditionGrid: [62.0, 70.0], paths: 30,
        )->fresh();

        // The record is a frontier (the condition columns are the discriminator) and is Done.
        $this->assertTrue($run->isFrontier());
        $this->assertSame(LeverKey::BuyPrice->value, $run->lever_key);
        $this->assertSame(LeverKey::RetirementAge, $run->conditionLeverKey());
        $this->assertEquals([62.0, 70.0], $run->condition_grid);
        $this->assertSame(SimulationStatus::Done, $run->status);
        $this->assertSame(100, $run->progress_pct);

        // The payload rehydrates as a frontier — with every column's full curve — and never as a
        // 1-D threshold (the two readers can't answer for each other).
        $outcome = $run->frontierOutcome();
        $this->assertNotNull($outcome);
        $this->assertSame(LeverKey::BuyPrice, $outcome->thresholdLever);
        $this->assertSame(LeverKey::RetirementAge, $outcome->conditionLever);
        $this->assertCount(2, $outcome->frontier->points);
        $this->assertCount(2, $outcome->frontier->points[0]->curve->points);
        $this->assertNull($run->thresholdOutcome());
    }

    public function test_re_requesting_an_identical_frontier_is_a_cache_hit_but_a_1d_threshold_is_not(): void
    {
        Queue::fake();
        $scenario = $this->scenario();
        $args = [
            $scenario, LeverKey::RetirementAge, LeverKey::EssentialSpend, SweepMetric::Essentials, 0.90,
            self::GRID, [20_000.0, 40_000.0], 40,
        ];

        $first = $this->runner()->requestFrontier(...$args);
        $second = $this->runner()->requestFrontier(...$args);
        $this->assertSame($first->id, $second->id); // identical frontier inputs -> the same record

        // A 1-D request sweeping the SAME lever over the SAME grid at the SAME paths differs only
        // by the (null) condition fields — and still never collides with the frontier's hash.
        $oneD = $this->request($scenario);
        $this->assertNotSame($first->id, $oneD->id);
        $this->assertSame(2, ThresholdResult::count());
    }

    public function test_a_sweep_that_throws_is_reported_not_only_summarised_on_the_row(): void
    {
        // Same rule as {@see SimulationRunnerTest}: the row's error column is a status line, not a
        // diagnosis. A sweep that dies on the worker must reach the exception handler too.
        Exceptions::fake();

        $scenario = $this->scenario();
        $runner = $this->runner();
        $hash = $runner->inputsHash($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, self::GRID, 40);
        $run = $runner->createRun($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, self::GRID, 40, $hash);

        // Break the scenario AFTER the run exists, so the throw lands inside execute().
        $scenario->update(['base_tax_year' => '1899-00']);

        $runner->execute($run->fresh());

        $this->assertSame(SimulationStatus::Failed, $run->fresh()->status);
        Exceptions::assertReported(InvalidArgumentException::class);
    }

    public function test_a_dead_worker_marks_the_threshold_failed(): void
    {
        $scenario = $this->scenario();
        $runner = $this->runner();
        $hash = $runner->inputsHash($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, self::GRID, 40);
        $run = $runner->createRun($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, self::GRID, 40, $hash);

        (new RunLeverThreshold($run->id))->failed(new RuntimeException('worker died'));

        $run->refresh();
        $this->assertSame(SimulationStatus::Failed, $run->status);
        $this->assertStringContainsString('worker died', (string) $run->error);
    }
}
