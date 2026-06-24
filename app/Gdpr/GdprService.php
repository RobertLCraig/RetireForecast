<?php

declare(strict_types=1);

namespace App\Gdpr;

use App\Models\User;

/**
 * GDPR data-subject operations for a user. Export returns everything the app holds
 * about them, decrypted, in a portable structure; erase is a hard delete of the
 * account, which cascades (foreign keys) to their households and scenarios. There
 * is no soft-delete: erased means gone.
 */
final class GdprService
{
    /** @return array<string, mixed> a portable, JSON-serialisable copy of all the user's data */
    public function export(User $user): array
    {
        $user->loadMissing(['households', 'scenarios']);

        return [
            'exported_at' => now()->toIso8601String(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'households' => $user->households->map(fn ($household): array => [
                'id' => $household->id,
                'name' => $household->name,
                'region' => $household->region->value,
                'created_at' => $household->created_at?->toIso8601String(),
                // payload is already decrypted by the model cast.
                'detail' => $household->payload,
            ])->all(),
            'scenarios' => $user->scenarios->map(fn ($scenario): array => [
                'id' => $scenario->id,
                'household_id' => $scenario->household_id,
                'name' => $scenario->name,
                'variant' => $scenario->variant->value,
                'base_tax_year' => $scenario->base_tax_year,
                'iht_modelled' => $scenario->iht_modelled,
                'status' => $scenario->status->value,
                'assumption_set_id' => $scenario->assumption_set_id,
                'created_at' => $scenario->created_at?->toIso8601String(),
                'housing_action' => $scenario->payload,
            ])->all(),
        ];
    }

    /** Hard-delete the account and everything that cascades from it. */
    public function erase(User $user): void
    {
        $user->delete();
    }
}
