<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Finance\Mapping\HousingActionMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RetireForecast\FinanceEngine\Dto\HousingAction;

/**
 * One housing decision (variant) over a household, run under a chosen assumption
 * set and tax year. The housing action's figures are sensitive and held in the
 * encrypted payload; variant, tax year, IHT toggle, status and the assumption-set
 * reference are clear structural columns.
 *
 * @property string $name
 * @property ScenarioVariant $variant
 * @property string $base_tax_year
 * @property bool $iht_modelled
 * @property ScenarioStatus $status
 * @property array $payload
 * @property int $household_id
 * @property int|null $user_id
 * @property int|null $assumption_set_id
 */
class Scenario extends Model
{
    protected $fillable = [
        'household_id', 'user_id', 'assumption_set_id',
        'name', 'variant', 'base_tax_year', 'iht_modelled', 'status', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'variant' => ScenarioVariant::class,
            'status' => ScenarioStatus::class,
            'iht_modelled' => 'boolean',
            'payload' => 'encrypted:array',
        ];
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assumptionSet(): BelongsTo
    {
        return $this->belongsTo(AssumptionSet::class);
    }

    public function housingAction(): HousingAction
    {
        return HousingActionMapper::fromArray($this->payload);
    }

    public function setHousingAction(HousingAction $action): static
    {
        $this->payload = HousingActionMapper::toArray($action);

        return $this;
    }
}
