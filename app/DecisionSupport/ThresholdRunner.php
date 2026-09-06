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

    /**
     * Paths per CELL for a queued 2-D frontier. A frontier multiplies the 1-D cost by its held
     * values (~5 columns × ~9-11 cells each), so it runs at half the 1-D density: at 1,000 paths a
     * cell's 95% Wilson interval is still ≈±2 points near a 90% success rate — tight enough to band
     * each column's crossing — while the whole map stays a few minutes on a queued worker instead
     * of tens. Recorded as provenance and overridable like the 1-D count.
     */
    public const FRONTIER_DEFAULT_PATHS = 1_000;

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
     * $leverParam is the per-person target for a parameterised lever (the person id the per-person
     * longevity lever moves) — it joins the inputs hash, so the same lever on a different person is
     * a distinct threshold, and re-requesting the same person is a cache hit.
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
        ?string $leverParam = null,
    ): ThresholdResult {
        $grid ??= $this->service->defaultGrid($lever, $scenario->toHousehold(), $scenario->toHousingAction());
        $paths ??= self::DEFAULT_PATHS;
        $hash = $this->inputsHash($scenario, $lever, $metric, $targetProbability, $grid, $paths, $leverParam);

        $existing = ThresholdResult::query()
            ->where('scenario_id', $scenario->id)
            ->where('inputs_hash', $hash)
            ->whereIn('status', [SimulationStatus::Done, SimulationStatus::Queued, SimulationStatus::Running])
            ->latest()
            ->first();

        if ($existing !== null) {
            return $existing; // cache hit, or a sweep for these inputs is already running
        }

        $run = $this->createRun($scenario, $lever, $metric, $targetProbability, $grid, $paths, $hash, $leverParam);
        RunLeverThreshold::dispatch($run->id);

        return $run;
    }

    /**
     * The 2-D twin of {@see request}: return an existing frontier for these exact inputs (done, or
     * already in flight) or queue a fresh one. The condition lever + its held grid join the inputs
     * hash, so a frontier is cached and invalidated exactly as a 1-D threshold is — and the two can
     * never answer for each other (a 1-D hash has null condition fields).
     *
     * @param  list<float>|null  $thresholdGrid
     * @param  list<float>|null  $conditionGrid
     */
    public function requestFrontier(
        Scenario $scenario,
        LeverKey $thresholdLever,
        LeverKey $conditionLever,
        SweepMetric $metric,
        float $targetProbability,
        ?array $thresholdGrid = null,
        ?array $conditionGrid = null,
        ?int $paths = null,
    ): ThresholdResult {
        $household = $scenario->toHousehold();
        $action = $scenario->toHousingAction();
        $thresholdGrid ??= $this->service->defaultGrid($thresholdLever, $household, $action);
        $conditionGrid ??= $this->service->defaultConditionGrid($conditionLever, $household, $action);
        $paths ??= self::FRONTIER_DEFAULT_PATHS;
        $hash = $this->inputsHash(
            $scenario, $thresholdLever, $metric, $targetProbability, $thresholdGrid, $paths,
            conditionLever: $conditionLever, conditionGrid: $conditionGrid,
        );

        $existing = ThresholdResult::query()
            ->where('scenario_id', $scenario->id)
            ->where('inputs_hash', $hash)
            ->whereIn('status', [SimulationStatus::Done, SimulationStatus::Queued, SimulationStatus::Running])
            ->latest()
            ->first();

        if ($existing !== null) {
            return $existing; // cache hit, or a frontier for these inputs is already running
        }

        $run = $this->createRun(
            $scenario, $thresholdLever, $metric, $targetProbability, $thresholdGrid, $paths, $hash,
            conditionLever: $conditionLever, conditionGrid: $conditionGrid,
        );
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
     * @param  list<float>|null  $conditionGrid
     */
    public function inputsHash(
        Scenario $scenario,
        LeverKey $lever,
        SweepMetric $metric,
        float $targetProbability,
        array $grid,
        int $paths,
        ?string $leverParam = null,
        ?LeverKey $conditionLever = null,
        ?array $conditionGrid = null,
    ): string {
        return hash('sha256', json_encode([
            'inputs' => $scenario->effectiveBuilderState(),
            'engine' => ScenarioForecaster::ENGINE_VERSION,
            'lever' => $lever->value,
            'lever_param' => $leverParam,
            // The frontier's second axis; both null for a 1-D threshold, so the two kinds of run
            // can never hash to each other.
            'condition_lever' => $conditionLever?->value,
            'condition_grid' => $conditionGrid,
            'metric' => $metric->value,
            'target' => $targetProbability,
            'grid' => $grid,
            'paths' => $paths,
            'seed' => LeverThresholdService::SEED,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<float>  $grid
     * @param  list<float>|null  $conditionGrid
     */
    public function createRun(
        Scenario $scenario,
        LeverKey $lever,
        SweepMetric $metric,
        float $targetProbability,
        array $grid,
        int $paths,
        string $inputsHash,
        ?string $leverParam = null,
        ?LeverKey $conditionLever = null,
        ?array $conditionGrid = null,
    ): ThresholdResult {
        $run = new ThresholdResult([
            'scenario_id' => $scenario->id,
            'user_id' => $scenario->user_id,
            'lever_key' => $lever->value,
            'lever_param' => $leverParam,
            'condition_lever_key' => $conditionLever?->value,
            'condition_grid' => $conditionGrid,
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
     * Run the sweep — 1-D threshold or 2-D frontier, decided by the record's condition columns —
     * reporting progress and honouring a cancel. Mirrors {@see SimulationRunner::execute}: a
     * cancel between grid points (or frontier cells) stops it cleanly, any other failure lands in
     * Failed with its reason (no silent failure).
     */
    public function execute(ThresholdResult $run): void
    {
        if ($run->status->isTerminal()) {
            return; // cancelled before it started, or already finished
        }

        $run->update(['status' => SimulationStatus::Running, 'started_at' => now(), 'progress_pct' => 0]);

        $onProgress = function (int $done, int $total) use ($run): void {
            $pct = min(99, (int) floor($done / max(1, $total) * 100));
            if ($pct > $run->progress_pct) {
                $run->update(['progress_pct' => $pct]);
                $this->assertNotCancelled($run);
            }
        };

        try {
            if ($run->isFrontier()) {
                $outcome = $this->service->computeFrontier(
                    $run->scenario,
                    $run->leverKey(),
                    $run->conditionLeverKey(),
                    $run->metricEnum(),
                    $run->target_probability,
                    thresholdGrid: $run->grid,
                    conditionGrid: $run->condition_grid,
                    nPaths: $run->n_paths,
                    onProgress: $onProgress,
                );
            } else {
                $outcome = $this->service->compute(
                    $run->scenario,
                    $run->leverKey(),
                    $run->metricEnum(),
                    $run->target_probability,
                    grid: $run->grid,
                    nPaths: $run->n_paths,
                    leverParam: $run->lever_param,
                    onProgress: $onProgress,
                );
            }
        } catch (RunCancelled) {
            $run->update(['status' => SimulationStatus::Cancelled, 'finished_at' => now()]);

            return;
        } catch (Throwable $e) {
            // The message on the row is a status line for the page, not a diagnosis; the handler
            // gets the throwable itself. {@see SimulationRunner::execute} for the reasoning.
            report($e);
            $run->update(['status' => SimulationStatus::Failed, 'error' => $e->getMessage(), 'finished_at' => now()]);

            return;
        }

        ($outcome instanceof FrontierOutcome ? $run->setFrontierOutcome($outcome) : $run->setThresholdOutcome($outcome))
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
