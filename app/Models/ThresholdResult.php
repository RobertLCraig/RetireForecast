<?php

declare(strict_types=1);

namespace App\Models;

use App\DecisionSupport\LeverKey;
use App\DecisionSupport\ThresholdOutcome;
use App\Enums\SimulationStatus;
use App\Finance\Mapping\AssumptionSetMapper;
use App\Finance\Mapping\ThresholdOutcomeMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;

/**
 * A computed (or in-flight) decision-support lever threshold for a scenario. It is both the
 * queued run (status + live progress + cancel, mirroring {@see SimulationRun}) AND the stored
 * result: the swept curve + crossing land in the encrypted `payload` once done.
 *
 * Reproducibility + staleness: the seed is fixed and recorded, the lever grid + path count +
 * engine/tax-year versions + a frozen assumption snapshot are stored, and `inputs_hash` keys
 * the row to the exact inputs it answers — so an identical re-request is a cache hit and a
 * result is only ever surfaced while its hash matches the scenario's current inputs.
 *
 * @property string $lever_key
 * @property string|null $lever_param
 * @property string $metric
 * @property float $target_probability
 * @property int $n_paths
 * @property int $seed
 * @property array $grid
 * @property string $engine_version
 * @property string $taxyear_config_version
 * @property array $assumption_snapshot
 * @property string $inputs_hash
 * @property SimulationStatus $status
 * @property int $progress_pct
 * @property array|null $payload
 * @property string|null $error
 * @property int $scenario_id
 * @property int|null $user_id
 */
class ThresholdResult extends Model
{
    protected $fillable = [
        'scenario_id', 'user_id', 'lever_key', 'lever_param', 'metric', 'target_probability', 'n_paths', 'seed',
        'grid', 'engine_version', 'taxyear_config_version', 'assumption_snapshot', 'inputs_hash',
        'status', 'progress_pct', 'payload', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SimulationStatus::class,
            'target_probability' => 'float',
            'grid' => 'encrypted:array',
            'assumption_snapshot' => 'encrypted:array',
            'payload' => 'encrypted:array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** Seconds a threshold may sit queued at 0% before we surface the "is a worker running?" hint. */
    private const WORKER_WAIT_HINT_SECONDS = 15;

    /**
     * True when this threshold has sat queued at 0% long enough that, run locally, the likely
     * cause is that no queue worker is running (the sweep is dispatched to the database queue
     * and needs `php artisan queue:work`). Drives a neutral on-screen hint so a threshold never
     * sits silently at "Queued — 0%" with no reason — the same treatment a simulation run gets.
     */
    public function isAwaitingWorker(): bool
    {
        return $this->status === SimulationStatus::Queued
            && (int) $this->progress_pct === 0
            && $this->created_at !== null
            && $this->created_at->lte(now()->subSeconds(self::WORKER_WAIT_HINT_SECONDS));
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function leverKey(): LeverKey
    {
        return LeverKey::from($this->lever_key);
    }

    public function metricEnum(): SweepMetric
    {
        return SweepMetric::from($this->metric);
    }

    /** The computed outcome (curve + crossing), or null while it is still queued/running. */
    public function thresholdOutcome(): ?ThresholdOutcome
    {
        return $this->payload === null ? null : ThresholdOutcomeMapper::fromArray($this->payload);
    }

    public function setThresholdOutcome(ThresholdOutcome $outcome): static
    {
        $this->payload = ThresholdOutcomeMapper::toArray($outcome);

        return $this;
    }

    /** The frozen assumption set this threshold was computed against. */
    public function assumptionSnapshot(): AssumptionSet
    {
        return AssumptionSetMapper::fromArray($this->assumption_snapshot);
    }

    public function setAssumptionSnapshot(AssumptionSet $set): static
    {
        $this->assumption_snapshot = AssumptionSetMapper::toArray($set);

        return $this;
    }
}
