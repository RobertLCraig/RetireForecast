<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Livewire\ScenarioBuilder;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Phase C2 — "Create what-if" delta children. The child editor is the full builder
 * pre-filled from the base, but a save stores ONLY the leaves the user changed (the
 * delta), with the base as the single source of truth. Structural add/remove is
 * refused (a delta cannot fork the base), and editing the base flows through to its
 * children. Also covers the stable list-item ids the override targeting relies on.
 */
class ScenarioChildTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_child_prefills_from_the_base(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);

        Livewire::test(ScenarioBuilder::class, ['scenario' => $base, 'asChild' => true])
            ->assertSet('childMode', true)
            ->assertSet('parentScenarioId', $base->id)
            ->assertSet('scenarioId', null)
            ->assertSet('editing', false)
            ->assertSet('householdName', 'The Worked-Example Couple')
            ->assertSet('name', 'Buy-vs-rent — what-if');
    }

    public function test_saving_a_child_stores_only_the_delta(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);

        Livewire::test(ScenarioBuilder::class, ['scenario' => $base, 'asChild' => true])
            ->set('name', 'Higher essentials')
            ->set('expenseLines.0.amount', '31000') // the essential line (ess1)
            ->call('save');

        $child = Scenario::where('parent_scenario_id', $base->id)->firstOrFail();

        // Only the two changed leaves are stored — not a copy of the base.
        $this->assertEqualsCanonicalizing(['name', 'expenseLines.ess1.amount'], array_keys($child->overrides));
        $this->assertSame('31000', $child->overrides['expenseLines.ess1.amount']);
        $this->assertSame([], $child->builder_state);
        $this->assertSame(ScenarioStatus::Ready, $child->status);
        // The child's effective inputs reflect the override; the base is untouched.
        $this->assertSame(3_100_000, $child->toHousehold()->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(2_800_000, $base->fresh()->toHousehold()->expenseProfile->essentialAnnualSpend->pence);
    }

    public function test_editing_the_base_refreshes_children_and_invalidates_their_runs(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);

        $child = new Scenario;
        $child->user_id = $user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = ['name' => 'Child', 'variant' => 'buy_outright'];
        $child->builder_state = [];
        $child->projectFrom($child->effectiveBuilderState());
        $child->status = ScenarioStatus::Ready;
        $child->save();

        $run = (new SimulationRunner(new ScenarioForecaster))->preview($child, seed: 1, paths: 20);
        $this->assertSame(1, $child->simulationRuns()->count());

        // Edit the base's essential spend; the child inherits it (it did not override it).
        Livewire::test(ScenarioBuilder::class, ['scenario' => $base])
            ->set('expenseLines.0.amount', '33000') // the essential line (ess1)
            ->call('save');

        $this->assertSame(0, $child->simulationRuns()->count());
        $this->assertDatabaseMissing('simulation_runs', ['id' => $run->id]);
        $this->assertSame(3_300_000, $child->fresh()->toHousehold()->expenseProfile->essentialAnnualSpend->pence);
    }

    public function test_a_structural_change_in_a_child_is_refused(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);

        Livewire::test(ScenarioBuilder::class, ['scenario' => $base, 'asChild' => true])
            ->call('addAccount')
            ->set('accounts.3.balance', '5000')
            ->call('save')
            ->assertHasErrors('childStructure')
            ->assertNoRedirect();

        $this->assertSame(0, Scenario::where('parent_scenario_id', $base->id)->count());
    }

    public function test_a_user_cannot_create_a_child_of_another_users_base(): void
    {
        $owner = User::factory()->create();
        $base = ScenarioFixture::rich($owner);

        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.child', $base))->assertForbidden();
    }

    public function test_added_list_rows_get_stable_ids_that_survive_a_save(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);

        $component = Livewire::test(ScenarioBuilder::class, ['scenario' => $base])
            ->call('addAccount');

        $accounts = $component->get('accounts');
        $newId = $accounts[array_key_last($accounts)]['id'];
        $this->assertNotEmpty($newId);

        $component->set('accounts.3.balance', '5000')->call('save');

        $saved = $base->fresh()->builder_state['accounts'];
        $this->assertSame($newId, $saved[array_key_last($saved)]['id']);
    }
}
