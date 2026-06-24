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
 * @property string|null $error
 * @property int $scenario_id
 * @property int|null $user_id
 */
class SimulationRun extends Model
{
    protected $fillable = [
        'scenario_id', 'user_id', 'mode', 'n_paths', 'seed', 'status', 'progress_pct',
        'engine_version', 'taxyear_config_version', 'assumption_snapshot', 'error',
        'started_at', 'finished_at',
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
}
