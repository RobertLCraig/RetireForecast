<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\ScenarioStatus;
use App\Models\Scenario;
use App\Models\User;

/**
 * Builds a persisted {@see Scenario} the way the inverted storage layer does: from the
 * builder's form-state ({@see BuilderStateFixture}), with the engine DTOs derived from
 * it. A scenario built with {@see rich()} derives a household equal to
 * {@see HouseholdFixture::household()} and a housing action equal to
 * {@see HouseholdFixture::housingAction()} — the single shape, exercised end to end.
 */
final class ScenarioFixture
{
    /**
     * A ready scenario for $user reproducing the rich household + housing fixture.
     *
     * @param  array<string, mixed>  $stateOverrides  merged onto the builder form-state
     */
    public static function rich(User $user, array $stateOverrides = []): Scenario
    {
        return self::fromState($user, self::richState($stateOverrides));
    }

    /**
     * Persist a ready scenario for $user from an explicit builder form-state.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromState(User $user, array $state): Scenario
    {
        $scenario = new Scenario;
        $scenario->user_id = $user->id;
        $scenario->fillFromBuilderState($state);
        $scenario->status = ScenarioStatus::Ready;
        $scenario->save();

        return $scenario->fresh();
    }

    /**
     * The full builder form-state reproducing {@see HouseholdFixture} plus the
     * scenario-level fields (name, variant, tax year, IHT, assumption set).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function richState(array $overrides = []): array
    {
        return array_replace([
            'step' => 5,
            'name' => 'Buy-vs-rent',
            'baseTaxYear' => '2026-27',
            'variant' => 'rent',
            'ihtModelled' => false,
            'assumptionSetId' => null,
        ], BuilderStateFixture::full(), $overrides);
    }
}
