<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Enums\SimulationStatus;
use App\Forecast\RunCancelled;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Jobs\RunLeverThreshold;
use App\Models\Scenario;
use App\Models\ThresholdResult;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use Throwable;

/**
 * Orchestrates a lever-threshold sweep end to end, mirroring {@see SimulationRunner}:
 * create the {@see ThresholdResult} record, run the {@see LeverThresholdService} on the worker,
 * report live progress, land in a terminal status, and honour a cancel between progress ticks.
 * Nothing runs silently — the record carries its status and progress throughout.
 *
 * The sweep is a set of Monte Carlo runs (a long run), so it is queued, never run on the web
 * request. Re-requesting an identical sweep is a cache hit: the record is keyed by an inputs
 * hash (the effective form-state plus the engine version and every compute parameter), and an
 * input edit deletes the records exactly as it deletes runs, so a stale threshold is never
 * surfaced.
 */
final class ThresholdRunner
{
    /**
     * Paths per grid point for a queued ("confirmed") threshold. Higher than the compute core's
     * 500-path default so a persisted crossing is tight; the count is recorded on the record as
     * provenance and is overridable. A cheaper preview path-count ladder is a Phase-2 concern.
     */
    public const DEFAULT_PATHS = 2_000;

    public function __construct(
        private readonly LeverThresholdService $service,
        private readonly ScenarioForecaster $forecaster,
    ) {}

    /**
     * Return an existing threshold for these exact inputs (a done cache hit, or one already in
     * flight) or queue a fresh sweep. $grid/$paths default to the service's per-lever grid and
     * {@see DEFAULT_PATHS}; the same defaults feed the inputs hash, so a defaulted re-request
     * still hits the cache.
     *
     * @param  list<float>|null  $grid
     */
    public function request(
        Scenario $scenario,
        LeverKey $lever,
        SweepMetric $metric,
        float $targetProbability,
        ?array $grid = null,
        ?int $paths = null,
    ): ThresholdResult {
        $grid ??= $this->service->defaultGrid($lever, $scenario->toHousehold(), $scenario->toHousingAction());
        $paths ??= self::DEFAULT_PATHS;
        $hash = $this->inputsHash($scenario, $lever, $metric, $targetProbability, $grid, $paths);

        $existing = ThresholdResult::query()
            ->where('scenario_id', $scenario->id)
            ->where('inputs_hash', $hash)
            ->whereIn('status', [SimulationStatus::Done, SimulationStatus::Queued, SimulationStatus::Running])
            ->latest()
            ->first();

        if ($existing !== null) {
            return $existing; // cache hit, or a sweep for these inputs is already running
        }

        $run = $this->createRun($scenario, $lever, $metric, $targetProbability, $grid, $paths, $hash);
        RunLeverThreshold::dispatch($run->id);

        return $run;
    }

    /**
     * The cache key for a sweep: everything that changes the answer. The effective form-state is
     * the single source of truth for every forecast input (household, settings, assumptions), so
     * hashing it plus the engine version and the compute parameters means any input edit, engine
     * bump or parameter change misses the cache and any unchanged re-request hits it.
     *
     * @param  list<float>  $grid
     */
    public function inputsHash(
        Scenario $scenario,
        LeverKey $lever,
        SweepMetric $metric,
        float $targetProbability,
        array $grid,
        int $paths,
    ): string {
        return hash('sha256', json_encode([
            'inputs' => $scenario->effectiveBuilderState(),
            'engine' => ScenarioForecaster::ENGINE_VERSION,
            'lever' => $lever->value,
            'metric' => $metric->value,
            'target' => $targetProbability,
            'grid' => $grid,
            'paths' => $paths,
            'seed' => LeverThresholdService::SEED,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<float>  $grid
     */
    public function createRun(
        Scenario $scenario,
        LeverKey $lever,
        SweepMetric $metric,
        float $targetProbability,
        array $grid,
        int $paths,
        string $inputsHash,
    ): ThresholdResult {
        $run = new ThresholdResult([
            'scenario_id' => $scenario->id,
            'user_id' => $scenario->user_id,
            'lever_key' => $lever->value,
            'metric' => $metric->value,
            'target_probability' => $targetProbability,
            'n_paths' => $paths,
            'seed' => LeverThresholdService::SEED,
            'grid' => $grid,
            'engine_version' => ScenarioForecaster::ENGINE_VERSION,
            'taxyear_config_version' => $this->forecaster->config($scenario)->verifiedOn,
            'inputs_hash' => $inputsHash,
            'status' => SimulationStatus::Queued,
            'progress_pct' => 0,
        ]);
        $run->setAssumptionSnapshot($this->forecaster->assumptions($scenario));
        $run->save();

        return $run;
    }

    /**
     * Run the sweep, reporting progress and honouring a cancel. Mirrors
     * {@see SimulationRunner::execute}: a cancel between grid points stops it
     * cleanly, any other failure lands in Failed with its reason (no silent failure).
     */
    public function execute(ThresholdResult $run): void
    {
        if ($run->status->isTerminal()) {
            return; // cancelled before it started, or already finished
        }

        $run->update(['status' => SimulationStatus::Running, 'started_at' => now(), 'progress_pct' => 0]);

        try {
            $outcome = $this->service->compute(
                $run->scenario,
                $run->leverKey(),
                $run->metricEnum(),
                $run->target_probability,
                grid: $run->grid,
                nPaths: $run->n_paths,
                onProgress: function (int $done, int $total) use ($run): void {
                    $pct = min(99, (int) floor($done / max(1, $total) * 100));
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

        $run->setThresholdOutcome($outcome)
            ->fill(['status' => SimulationStatus::Done, 'progress_pct' => 100, 'finished_at' => now()])
            ->save();
    }

    /** Request cancellation; the running job stops at its next progress tick. */
    public function cancel(ThresholdResult $run): void
    {
        if (! $run->status->isTerminal()) {
            $run->update(['status' => SimulationStatus::Cancelled, 'finished_at' => now()]);
        }
    }

    private function assertNotCancelled(ThresholdResult $run): void
    {
        if ($run->fresh()?->status === SimulationStatus::Cancelled) {
            throw new RunCancelled;
        }
    }
}
