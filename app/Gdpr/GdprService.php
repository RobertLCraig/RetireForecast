<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Models\User;

/**
 * GDPR data-subject operations for a user. Export returns everything the app holds
 * about them, decrypted, in a portable structure; erase is a hard delete of the
 * account, which cascades (foreign keys) to their scenarios and run history. There
 * is no soft-delete: erased means gone.
 */
final class GdprService
{
    /** @return array<string, mixed> a portable, JSON-serialisable copy of all the user's data */
    public function export(User $user): array
    {
        $user->loadMissing(['scenarios', 'simulationRuns.results']);

        return [
            'exported_at' => now()->toIso8601String(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'scenarios' => $user->scenarios->map(fn ($scenario): array => [
                'id' => $scenario->id,
                'name' => $scenario->name,
                'household_name' => $scenario->householdName(),
                'variant' => $scenario->variant->value,
                'base_tax_year' => $scenario->base_tax_year,
                'iht_modelled' => $scenario->iht_modelled,
                'status' => $scenario->status->value,
                'assumption_set_id' => $scenario->assumption_set_id,
                'created_at' => $scenario->created_at?->toIso8601String(),
                // The full builder form-state — the editable record — decrypted by the model cast.
                'builder_state' => $scenario->builder_state,
            ])->all(),
            // The user's forecast history: each run plus its per-variant results, with the
            // sensitive detail decrypted by the model casts (data portability).
            'simulation_runs' => $user->simulationRuns->map(fn ($run): array => [
                'id' => $run->id,
                'scenario_id' => $run->scenario_id,
                'mode' => $run->mode->value,
                'n_paths' => $run->n_paths,
                'seed' => $run->seed,
                'status' => $run->status->value,
                'engine_version' => $run->engine_version,
                'taxyear_config_version' => $run->taxyear_config_version,
                'created_at' => $run->created_at?->toIso8601String(),
                'assumption_snapshot' => $run->assumption_snapshot,
                'results' => $run->results->map(fn ($result): array => [
                    'id' => $result->id,
                    'variant' => $result->variant->value,
                    'detail' => $result->payload,
                ])->all(),
            ])->all(),
        ];
    }

    /** Hard-delete the account and everything that cascades from it. */
    public function erase(User $user): void
    {
        $user->delete();
    }
}
