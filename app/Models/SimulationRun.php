<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Finance\Mapping\AssumptionSetMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;

/**
 * One execution of a scenario's forecast. The assumption set used is snapshotted
 * (frozen, encrypted) so a stored result stays reproducible even if the live set is
 * later edited; the seed is always recorded for the same reason.
 *
 * @property SimulationMode $mode
 * @property int $n_paths
 * @property int $seed
 * @property SimulationStatus $status
 * @property int $progress_pct
 * @property string $engine_version
 * @property string $taxyear_config_version
 * @property array $assumption_snapshot
 * @property string|null $inputs_hash
 * @property string|null $integrity_hash
 * @property string|null $error
 * @property int $scenario_id
 * @property int|null $user_id
 */
class SimulationRun extends Model
{
    protected $fillable = [
        'scenario_id', 'user_id', 'mode', 'n_paths', 'seed', 'status', 'progress_pct',
        'engine_version', 'taxyear_config_version', 'assumption_snapshot', 'inputs_hash',
        'integrity_hash', 'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'mode' => SimulationMode::class,
            'status' => SimulationStatus::class,
            'assumption_snapshot' => 'encrypted:array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** Seconds a run may sit queued at 0% before we surface the "is a worker running?" hint. */
    private const WORKER_WAIT_HINT_SECONDS = 15;

    /**
     * True when this run has sat queued at 0% long enough that, run locally, the likely
     * cause is that no queue worker is running. The full run is dispatched to the database
     * queue and needs `php artisan queue:work`; a worker would have moved it to `running`
     * by now. Drives a neutral on-screen hint so the run never sits silently at "Queued —
     * 0%" with no reason (no silent failure).
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

    public function results(): HasMany
    {
        return $this->hasMany(Result::class);
    }

    /** The frozen assumption set this run was computed against. */
    public function assumptionSnapshot(): AssumptionSet
    {
        return AssumptionSetMapper::fromArray($this->assumption_snapshot);
    }

    public function setAssumptionSnapshot(AssumptionSet $set): static
    {
        $this->assumption_snapshot = AssumptionSetMapper::toArray($set);

        return $this;
    }

    /**
     * The tamper-evident stamp over this run: everything that says what was computed
     * (scenario, mode, paths, seed, engine + tax-year stamps, inputs hash, the frozen
     * assumptions) and everything that came out of it (each variant's decrypted result
     * payload). Edit any stored figure or any provenance column and this no longer matches
     * what was recorded, so an altered result is evident rather than silently believed.
     *
     * Keyed with the app key, so re-forging the stamp needs more than database access. The
     * mutable lifecycle columns (status, progress, timestamps, error) are deliberately OUT:
     * cancelling or re-reading a run changes those legitimately, and a stamp that moved
     * every time would report tampering it had not found.
     */
    public function integrityHash(): string
    {
        $results = $this->results()
            ->orderBy('variant')
            ->get()
            ->mapWithKeys(fn (Result $result): array => [$result->variant->value => $result->payload])
            ->all();

        return hash_hmac('sha256', json_encode([
            'scenario_id' => $this->scenario_id,
            'mode' => $this->mode->value,
            'n_paths' => $this->n_paths,
            'seed' => $this->seed,
            'engine_version' => $this->engine_version,
            'taxyear_config_version' => $this->taxyear_config_version,
            'inputs_hash' => $this->inputs_hash,
            'assumptions' => $this->assumption_snapshot,
            'results' => $results,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** Stamp this run with its integrity hash. Called once the results are persisted. */
    public function recordIntegrityHash(): static
    {
        $this->integrity_hash = $this->integrityHash();

        return $this;
    }

    /**
     * True when this run still hashes to the stamp recorded when it finished. False means
     * either it was never stamped (a run predating the column, which cannot be vouched for)
     * or a stored figure has moved since — both of which the audit reports rather than hides.
     */
    public function isIntact(): bool
    {
        return $this->integrity_hash !== null
            && hash_equals($this->integrity_hash, $this->integrityHash());
    }
}
