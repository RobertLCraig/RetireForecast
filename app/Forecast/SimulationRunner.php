<?php

declare(strict_types=1);

namespace App\Forecast;

use App\DecisionSupport\ThresholdRunner;
use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Finance\Mapping\AssumptionSetMapper;
use App\Jobs\RunScenarioSimulation;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use Illuminate\Support\Facades\DB;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use Throwable;

/**
 * Orchestrates a scenario's forecast run end to end: create the run record, execute
 * the buy-vs-rent comparison, report live progress, persist a Result per variant,
 * and land in a terminal status. Nothing runs silently — the run carries its status
 * and progress throughout, and a cancel between progress ticks stops it cleanly.
 *
 * A preview runs synchronously (responsive); the full run is queued.
 *
 * A forecast is not recomputed when nothing about it has changed. Each run records an
 * {@see inputsHash} of everything that moves the answer, and {@see preview} / {@see dispatch}
 * hand back the matching run instead of running the Monte Carlo again, the same cache
 * {@see ThresholdRunner} uses for a sweep. Saving an edited scenario deletes its runs (that is
 * the primary invalidation, in the builder); the hash is the belt-and-braces, so an engine bump
 * or an edited assumption set can never be served a result computed under the old one. A caller
 * of {@see dispatch} that needs to tell a freshly queued run from a cache hit reads the returned
 * model's `wasRecentlyCreated`.
 */
final class SimulationRunner
{
    public function __construct(private readonly ScenarioForecaster $forecaster) {}

    public function createRun(Scenario $scenario, SimulationMode $mode, ?int $seed = null, ?int $paths = null): SimulationRun
    {
        $paths ??= $mode->defaultPaths();

        $run = new SimulationRun([
            'scenario_id' => $scenario->id,
            'user_id' => $scenario->user_id,
            'mode' => $mode,
            'n_paths' => $paths,
            'seed' => $seed ?? random_int(0, 2_147_483_647),
            'status' => SimulationStatus::Queued,
            'progress_pct' => 0,
            'engine_version' => ScenarioForecaster::ENGINE_VERSION,
            'taxyear_config_version' => $this->forecaster->config($scenario)->verifiedOn,
            'inputs_hash' => $this->inputsHash($scenario, $mode, $seed, $paths),
        ]);
        $run->setAssumptionSnapshot($this->forecaster->assumptions($scenario));
        $run->save();

        return $run;
    }

    /**
     * The cache key for a run: everything that changes the answer. The effective form-state is
     * the single source of truth for every forecast input, so hashing it with the frozen
     * assumptions (which an admin can edit under a scenario's feet), the engine and tax-year
     * stamps and the compute parameters means any change misses the cache and an unchanged
     * scenario hits it.
     *
     * $seed is hashed as given, NOT as resolved: an unseeded request is the app's normal one
     * (the seed is random and only recorded for reproducibility), so it must match an earlier
     * unseeded run rather than the one random draw it happened to make. An explicitly seeded
     * request is asking for that seed, so it gets a cache entry of its own.
     */
    public function inputsHash(Scenario $scenario, SimulationMode $mode, ?int $seed, int $paths): string
    {
        return hash('sha256', json_encode([
            'inputs' => $scenario->effectiveBuilderState(),
            'assumptions' => AssumptionSetMapper::toArray($this->forecaster->assumptions($scenario)),
            'engine' => ScenarioForecaster::ENGINE_VERSION,
            'tax_year' => $this->forecaster->config($scenario)->verifiedOn,
            'mode' => $mode->value,
            'paths' => $paths,
            'seed' => $seed,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A quick synchronous preview: the finished run for these inputs, computed now if there
     * isn't one. Only a completed run is re-used, because a preview must return something to read.
     */
    public function preview(Scenario $scenario, ?int $seed = null, ?int $paths = null): SimulationRun
    {
        $cached = $this->cachedRun($scenario, SimulationMode::Preview, $seed, $paths, [SimulationStatus::Done]);
        if ($cached !== null) {
            return $cached;
        }

        $run = $this->createRun($scenario, SimulationMode::Preview, $seed, $paths);
        $this->execute($run);

        return $run->fresh();
    }

    /**
     * The full run for these inputs: the stored one if it is done, the in-flight one if a
     * worker already has it (so a second click never queues a duplicate), else a fresh run
     * queued on the worker and returned to poll.
     */
    public function dispatch(Scenario $scenario, ?int $seed = null): SimulationRun
    {
        $cached = $this->cachedRun(
            $scenario, SimulationMode::Full, $seed, null,
            [SimulationStatus::Done, SimulationStatus::Queued, SimulationStatus::Running],
        );
        if ($cached !== null) {
            return $cached;
        }

        $run = $this->createRun($scenario, SimulationMode::Full, $seed);
        RunScenarioSimulation::dispatch($run->id);

        return $run;
    }

    /**
     * The newest run for these exact inputs in one of $statuses, or null. A run predating the
     * inputs-hash column has a null hash and so is never served as a hit.
     *
     * @param  list<SimulationStatus>  $statuses
     */
    private function cachedRun(
        Scenario $scenario,
        SimulationMode $mode,
        ?int $seed,
        ?int $paths,
        array $statuses,
    ): ?SimulationRun {
        return SimulationRun::query()
            ->where('scenario_id', $scenario->id)
            ->where('inputs_hash', $this->inputsHash($scenario, $mode, $seed, $paths ?? $mode->defaultPaths()))
            ->whereIn('status', $statuses)
            ->latest()
            ->first();
    }

    public function execute(SimulationRun $run): void
    {
        if ($run->status->isTerminal()) {
            return; // cancelled before it started, or already finished
        }

        $run->update(['status' => SimulationStatus::Running, 'started_at' => now(), 'progress_pct' => 0]);

        try {
            $comparison = $this->forecaster->compareHousing(
                $run->scenario,
                $run->n_paths,
                $run->seed,
                onProgress: function (float $fraction) use ($run): void {
                    $pct = min(99, (int) floor($fraction * 100));
                    if ($pct > $run->progress_pct) {
                        $run->update(['progress_pct' => $pct]);
                        $this->assertNotCancelled($run);
                    }
                },
            );
        } catch (RunCancelled) {
            $run->update(['status' => SimulationStatus::Cancelled, 'finished_at' => now()]);

            return;
        } catch (Throwable $e) {
            $run->update(['status' => SimulationStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()]);

            return;
        }

        DB::transaction(function () use ($run, $comparison): void {
            foreach ($comparison as $variant => $result) {
                (new Result(['simulation_run_id' => $run->id, 'variant' => $variant]))
                    ->setSimulationResult($result)
                    ->save();
            }

            // Stamp the finished run: its provenance plus the figures just written. Any later
            // edit to either (in the database, by hand, by anything) stops matching the stamp,
            // so a doctored result is evident instead of being read as the engine's own.
            $run->recordIntegrityHash();
            $run->update(['status' => SimulationStatus::Done, 'progress_pct' => 100, 'finished_at' => now()]);

            // Snapshot this run's headline figures for the chosen strategy, so the next run can
            // be diffed against it. The snapshot lives on the scenario, which survives the run
            // deletion an input edit triggers — so the diff works across an edit, not just two
            // runs on identical inputs ({@see ResultPresenter::runDiff}).
            $scenario = $run->scenario;
            $primarySim = $comparison[$scenario->variant->value] ?? reset($comparison);
            if ($primarySim instanceof SimulationResult) {
                $scenario->recordResultSnapshot($primarySim);
            }
        });
    }

    /** Request cancellation; the running job stops at its next progress tick. */
    public function cancel(SimulationRun $run): void
    {
        if (! $run->status->isTerminal()) {
            $run->update(['status' => SimulationStatus::Cancelled, 'finished_at' => now()]);
        }
    }

    private function assertNotCancelled(SimulationRun $run): void
    {
        if ($run->fresh()?->status === SimulationStatus::Cancelled) {
            throw new RunCancelled;
        }
    }
}
