<?php

declare(strict_types=1);

namespace Tests\Feature\Scenario;

use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Phase C2 storage: a delta-child stores only its overrides and resolves its effective
 * inputs from its base, so the base is the single source of truth. Proves the model
 * derives the merged household, projects the child's clear columns from the effective
 * state, surfaces orphaned overrides, and cannot outlive its base.
 */
class ScenarioDeltaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_child_resolves_its_effective_state_from_base_plus_overrides(): void
    {
        $user = User::factory()->create();
        $base = ScenarioFixture::rich($user);

        $child = new Scenario;
        $child->user_id = $user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = ['expenseLines.ess1.amount' => '31000', 'name' => 'Higher essentials'];
        $child->builder_state = [];
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        $effective = $child->fresh()->effectiveBuilderState();
        // The override re-points the essential expense line (by id); everything else tracks the base.
        $this->assertSame('31000', collect($effective['expenseLines'])->firstWhere('id', 'ess1')['amount']);
        $this->assertSame('12500', collect($effective['expenseLines'])->firstWhere('id', 'disc1')['amount']);
        // The clear column is projected from the effective state (the child's own name).
        $this->assertSame('Higher essentials', $child->fresh()->name);
    }

    public function test_a_child_derives_the_merged_household_dto(): void
    {
        $user = User::factory()->create();
        $base = ScenarioFixture::rich($user);

        $child = new Scenario;
        $child->user_id = $user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = ['expenseLines.ess1.amount' => '31000'];
        $child->builder_state = [];
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        $this->assertSame(3_100_000, $child->fresh()->toHousehold()->expenseProfile->essentialAnnualSpend->pence);
        // The base is untouched by the child's override.
        $this->assertSame(2_800_000, $base->fresh()->toHousehold()->expenseProfile->essentialAnnualSpend->pence);
    }

    public function test_deleting_a_base_cascades_to_its_children(): void
    {
        $user = User::factory()->create();
        $base = ScenarioFixture::rich($user);

        $child = new Scenario;
        $child->user_id = $user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = ['variant' => 'buy_outright'];
        $child->builder_state = [];
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        $base->delete();

        $this->assertDatabaseMissing('scenarios', ['id' => $child->id]);
    }

    public function test_an_override_orphaned_by_a_base_edit_is_surfaced(): void
    {
        $user = User::factory()->create();
        $base = ScenarioFixture::rich($user);

        $child = new Scenario;
        $child->user_id = $user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = ['pensions.dc1.currentValue' => '999999'];
        $child->builder_state = [];
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        // The base drops the dc pension the override targeted.
        $state = $base->builder_state;
        $state['pensions'] = array_values(array_filter($state['pensions'], fn ($p) => $p['id'] !== 'dc1'));
        $base->fillFromBuilderState($state)->save();

        $this->assertSame(['pensions.dc1.currentValue'], $child->fresh()->orphanedOverrides());
    }
}
