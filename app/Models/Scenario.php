<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Forecast\BuilderStateDelta;
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
 * save by {@see projectFrom()}, never an independent source that could drift.
 *
 * A delta-child what-if (Phase C2) is the exception that proves the single-source
 * rule: it references its base via {@see parent()} and stores ONLY its `overrides`
 * (a sparse delta), leaving `builder_state` empty. Its effective inputs are the base's
 * form-state overlaid with those overrides ({@see effectiveBuilderState()}), so the
 * base stays the one source and a base fix flows through instead of leaving a fork.
 *
 * @property string $name
 * @property ScenarioVariant $variant
 * @property string $base_tax_year
 * @property bool $iht_modelled
 * @property ScenarioStatus $status
 * @property array $builder_state
 * @property array|null $overrides
 * @property int|null $user_id
 * @property int|null $parent_scenario_id
 * @property int|null $assumption_set_id
 */
class Scenario extends Model
{
    protected $fillable = [
        'user_id', 'parent_scenario_id', 'assumption_set_id',
        'name', 'variant', 'base_tax_year', 'iht_modelled', 'status', 'builder_state', 'overrides',
    ];

    protected function casts(): array
    {
        return [
            'variant' => ScenarioVariant::class,
            'status' => ScenarioStatus::class,
            'iht_modelled' => 'boolean',
            'builder_state' => 'encrypted:array',
            'overrides' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The base plan this scenario is a what-if of, or null when it is itself a base. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_scenario_id');
    }

    /** The delta-child what-ifs spun off this base. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_scenario_id');
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
     * Store a base scenario's builder form-state (the single source of truth) and
     * project the clear structural columns from it. The columns are a derived
     * projection, never an independent source — so they cannot drift from what the
     * user actually entered.
     *
     * @param  array<string, mixed>  $state  the builder's form-state (the draft shape)
     */
    public function fillFromBuilderState(array $state): static
    {
        $this->builder_state = $state;

        return $this->projectFrom($state);
    }

    /**
     * Refresh the clear structural columns from an effective form-state. Used both by a
     * base ({@see fillFromBuilderState()}) and by a child (whose source is `overrides`,
     * not `builder_state`), so listing/filtering never has to decrypt or re-merge — and
     * never holds a value the form-state cannot reproduce.
     *
     * @param  array<string, mixed>  $state
     */
    public function projectFrom(array $state): static
    {
        $this->name = (string) ($state['name'] ?? '');
        $this->variant = ScenarioVariant::tryFrom((string) ($state['variant'] ?? '')) ?? ScenarioVariant::Rent;
        $this->base_tax_year = (string) ($state['baseTaxYear'] ?? '2026-27');
        $this->iht_modelled = (bool) ($state['ihtModelled'] ?? false);
        $this->assumption_set_id = $state['assumptionSetId'] ?? null;

        return $this;
    }

    public function isChild(): bool
    {
        return $this->parent_scenario_id !== null;
    }

    /** The base of this what-if family: this scenario if it is a base, else its parent. Keeps what-ifs two-level. */
    public function baseScenario(): self
    {
        return $this->isChild() ? $this->parent : $this;
    }

    /**
     * The resolved form-state every consumer reads: a base returns its own
     * `builder_state`; a child returns its base's effective state overlaid with its
     * `overrides` (one merge function, base as the single source — gotcha N).
     *
     * @return array<string, mixed>
     */
    public function effectiveBuilderState(): array
    {
        if (! $this->isChild()) {
            return $this->builder_state ?? [];
        }

        return BuilderStateDelta::merge(
            $this->parent->effectiveBuilderState(),
            $this->overrides ?? [],
        );
    }

    /**
     * Override paths that no longer resolve against the current base (e.g. a row the
     * base later deleted) — surfaced to the user rather than silently dropped.
     *
     * @return list<string>
     */
    public function orphanedOverrides(): array
    {
        if (! $this->isChild()) {
            return [];
        }

        return BuilderStateDelta::orphans($this->parent->effectiveBuilderState(), $this->overrides ?? []);
    }

    /** The household's display name, read from the effective form-state (no column to drift). */
    public function householdName(): string
    {
        return (string) ($this->effectiveBuilderState()['householdName'] ?? '');
    }

    /** The engine Household DTO, derived from the effective (base ⊕ overrides) form-state. */
    public function toHousehold(): Household
    {
        return (new HouseholdAssembler)->household($this->effectiveBuilderState());
    }

    /** The housing decision DTO, derived from the effective form-state. */
    public function toHousingAction(): HousingAction
    {
        return (new HouseholdAssembler)->housingAction($this->effectiveBuilderState()['housing'] ?? []);
    }
}
