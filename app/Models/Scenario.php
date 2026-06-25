<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Forecast\HouseholdAssembler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;

/**
 * A saved forecast: a household and a housing decision the user built. The raw builder
 * form-state is the SINGLE SOURCE OF TRUTH, held in the encrypted `builder_state`
 * payload; the engine's {@see Household} and {@see HousingAction} DTOs are *derived*
 * from it on demand by the {@see HouseholdAssembler} (no reverse-mapper — the inputs
 * have one home, which storage and the UI both read).
 *
 * The clear structural columns (name, variant, tax year, IHT toggle, status, the
 * assumption-set reference) are a projection of that form-state, refreshed on every
 * save by {@see fillFromBuilderState()}, never an independent source that could drift.
 *
 * @property string $name
 * @property ScenarioVariant $variant
 * @property string $base_tax_year
 * @property bool $iht_modelled
 * @property ScenarioStatus $status
 * @property array $builder_state
 * @property int|null $user_id
 * @property int|null $assumption_set_id
 */
class Scenario extends Model
{
    protected $fillable = [
        'user_id', 'assumption_set_id',
        'name', 'variant', 'base_tax_year', 'iht_modelled', 'status', 'builder_state',
    ];

    protected function casts(): array
    {
        return [
            'variant' => ScenarioVariant::class,
            'status' => ScenarioStatus::class,
            'iht_modelled' => 'boolean',
            'builder_state' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assumptionSet(): BelongsTo
    {
        return $this->belongsTo(AssumptionSet::class);
    }

    public function simulationRuns(): HasMany
    {
        return $this->hasMany(SimulationRun::class);
    }

    /**
     * Store the builder form-state (the single source of truth) and project the clear
     * structural columns from it. The columns are a derived projection, never an
     * independent source — so they cannot drift from what the user actually entered.
     *
     * @param  array<string, mixed>  $state  the builder's form-state (the draft shape)
     */
    public function fillFromBuilderState(array $state): static
    {
        $this->builder_state = $state;
        $this->name = (string) ($state['name'] ?? '');
        $this->variant = ScenarioVariant::tryFrom((string) ($state['variant'] ?? '')) ?? ScenarioVariant::Rent;
        $this->base_tax_year = (string) ($state['baseTaxYear'] ?? '2026-27');
        $this->iht_modelled = (bool) ($state['ihtModelled'] ?? false);
        $this->assumption_set_id = $state['assumptionSetId'] ?? null;

        return $this;
    }

    /** The household's display name, read from the form-state (no separate column to drift). */
    public function householdName(): string
    {
        return (string) ($this->builder_state['householdName'] ?? '');
    }

    /** The engine Household DTO, derived from the stored builder form-state. */
    public function toHousehold(): Household
    {
        return (new HouseholdAssembler)->household($this->builder_state ?? []);
    }

    /** The housing decision DTO, derived from the stored builder form-state. */
    public function toHousingAction(): HousingAction
    {
        return (new HouseholdAssembler)->housingAction($this->builder_state['housing'] ?? []);
    }
}
