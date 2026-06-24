<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScenarioVariant;
use App\Finance\Mapping\SimulationResultMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult as SimulationResultDto;

/**
 * The aggregated Monte Carlo outcome for one housing variant of a run. Maps to and
 * from the engine's {@see SimulationResultDto}; the figures are sensitive and held
 * in the encrypted payload, with the variant kept clear for lookup.
 *
 * @property ScenarioVariant $variant
 * @property array $payload
 * @property int $simulation_run_id
 */
class Result extends Model
{
    protected $fillable = ['simulation_run_id', 'variant', 'payload'];

    protected function casts(): array
    {
        return [
            'variant' => ScenarioVariant::class,
            'payload' => 'encrypted:array',
        ];
    }

    public function simulationRun(): BelongsTo
    {
        return $this->belongsTo(SimulationRun::class);
    }

    public function simulationResult(): SimulationResultDto
    {
        return SimulationResultMapper::fromArray($this->payload);
    }

    public function setSimulationResult(SimulationResultDto $result): static
    {
        $this->payload = SimulationResultMapper::toArray($result);

        return $this;
    }
}
