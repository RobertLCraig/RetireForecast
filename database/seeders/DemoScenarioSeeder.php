<?php

namespace Database\Seeders;

use App\Demo\DemoScenario;
use App\Enums\ScenarioStatus;
use App\Forecast\BuilderStateDelta;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeds the obviously-fictional demo plan — a base plan plus one delta-child what-if
 * (retire two years earlier) — so a fresh install can show a complete, runnable
 * forecast and the Compare feature immediately, without anyone entering real data
 * (DECISIONS 2026-06-25: no client data in the repo, any sample must be obviously
 * fictional). The demo's figures live in {@see DemoScenario} (one home); this seeder
 * only persists them in the canonical storage shape (builder_state as the source, the
 * child as a sparse override delta).
 *
 * Opt-in: not part of {@see DatabaseSeeder}, so it never fires in the normal test or
 * dev seed. Run it deliberately:
 *   php artisan db:seed --class=Database\\Seeders\\DemoScenarioSeeder
 *
 * Idempotent: re-running updates the same two scenarios in place (matched by owner +
 * name) and drops their stale runs, rather than duplicating.
 *
 * No-silent-failure: outside production it provisions an obviously-fictional demo
 * account (demo@example.com / password); in production it refuses unless
 * DEMO_USER_EMAIL names an existing user, so a release never ships default
 * credentials by accident.
 */
class DemoScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $user = $this->resolveUser();

        $base = $this->upsertBase($user);
        $this->upsertChild($user, $base);

        $this->command?->info("Seeded the demo plan for {$user->email}: a base plan and one what-if child.");
    }

    /** Create or refresh the demo base scenario from its builder form-state (the single source). */
    private function upsertBase(User $user): Scenario
    {
        $scenario = Scenario::firstOrNew([
            'user_id' => $user->id,
            'parent_scenario_id' => null,
            'name' => DemoScenario::BASE_NAME,
        ]);

        $scenario->user_id = $user->id;
        $scenario->fillFromBuilderState(DemoScenario::baseState());
        $scenario->status = ScenarioStatus::Ready;
        $scenario->save();

        // Re-seeding changes the inputs, so any earlier run is stale (gotcha B).
        $scenario->simulationRuns()->delete();

        return $scenario->fresh();
    }

    /**
     * Create or refresh the delta-child what-if: store only the overrides (the leaves
     * that differ from the base), derived via the same merge function the builder uses,
     * never a full copy — so the base stays the single source of truth.
     */
    private function upsertChild(User $user, Scenario $base): void
    {
        $effectiveBase = DemoScenario::baseState();
        $edited = DemoScenario::retireEarlyState();
        $overrides = BuilderStateDelta::diff($effectiveBase, $edited);

        $child = Scenario::firstOrNew([
            'user_id' => $user->id,
            'parent_scenario_id' => $base->id,
            'name' => DemoScenario::CHILD_NAME,
        ]);

        $child->user_id = $user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = $overrides;
        $child->builder_state = [];
        $child->projectFrom($edited);
        $child->status = ScenarioStatus::Ready;
        $child->save();

        $child->simulationRuns()->delete();
    }

    /**
     * The account the demo plan is attached to. Outside production a fictional demo
     * account is provisioned; in production an explicit existing user is required, so
     * no default-credential account is ever created on a release.
     */
    private function resolveUser(): User
    {
        $email = env('DEMO_USER_EMAIL');
        if (is_string($email) && $email !== '') {
            $user = User::where('email', $email)->first();
            if ($user === null) {
                throw new RuntimeException(
                    "DEMO_USER_EMAIL is set to {$email} but no such user exists. Register that account first, ".
                    'or unset DEMO_USER_EMAIL to provision a demo account (non-production only).'
                );
            }

            return $user;
        }

        if (app()->environment('production')) {
            throw new RuntimeException(
                'Refusing to provision a demo account in production. Set DEMO_USER_EMAIL to an existing '.
                'user to attach the demo plan to that account.'
            );
        }

        return User::firstOrCreate(
            ['email' => 'demo@example.com'],
            [
                'name' => 'Demo user (fictional)',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'disclaimer_acknowledged_at' => now(),
                'can_interpret' => false,
                'is_admin' => false,
            ],
        );
    }
}
