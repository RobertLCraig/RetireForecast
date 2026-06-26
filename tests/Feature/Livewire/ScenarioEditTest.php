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
 * Phase B: a saved forecast can be edited in place. The builder is the same component,
 * pre-filled from the scenario's stored form-state (the single source of truth), and a
 * save updates that scenario rather than creating a new one. Because the inputs changed,
 * any earlier run is invalidated so the user is never shown a result from stale inputs.
 */
class ScenarioEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_prefills_the_builder_from_the_saved_form_state(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $scenario = ScenarioFixture::rich($user);

        Livewire::test(ScenarioBuilder::class, ['scenario' => $scenario])
            ->assertSet('editing', true)
            ->assertSet('scenarioId', $scenario->id)
            ->assertSet('name', 'Buy-vs-rent')
            ->assertSet('householdName', 'The Worked-Example Couple')
            ->assertSet('variant', 'rent');
    }

    public function test_editing_updates_the_forecast_in_place(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $scenario = ScenarioFixture::rich($user);

        Livewire::test(ScenarioBuilder::class, ['scenario' => $scenario])
            ->set('name', 'Edited name')
            ->call('save')
            ->assertRedirect(route('scenarios.results', $scenario));

        $this->assertSame(1, Scenario::count());
        $this->assertSame('Edited name', $scenario->fresh()->name);
        $this->assertSame('Edited name', $scenario->fresh()->builder_state['name']);
        $this->assertSame(ScenarioStatus::Ready, $scenario->fresh()->status);
    }

    public function test_editing_and_saving_invalidates_a_stale_run(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $scenario = ScenarioFixture::rich($user);

        $run = (new SimulationRunner(new ScenarioForecaster))->preview($scenario, seed: 1, paths: 20);
        $this->assertSame(1, $scenario->simulationRuns()->count());

        Livewire::test(ScenarioBuilder::class, ['scenario' => $scenario])
            ->set('expenseLines.0.amount', '31000') // the essential line
            ->call('save');

        // The earlier run (and its results) are gone — the inputs it ran on no longer hold.
        $this->assertSame(0, $scenario->simulationRuns()->count());
        $this->assertDatabaseMissing('simulation_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('results', ['simulation_run_id' => $run->id]);
    }

    public function test_a_user_cannot_edit_another_users_forecast(): void
    {
        $owner = User::factory()->create();
        $scenario = ScenarioFixture::rich($owner);

        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.edit', $scenario))->assertForbidden();
    }

    public function test_opening_a_drafts_results_page_redirects_to_the_builder(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $scenario = ScenarioFixture::rich($user);
        $scenario->update(['status' => ScenarioStatus::Draft]);

        $this->get(route('scenarios.results', $scenario))
            ->assertRedirect(route('scenarios.edit', $scenario));
    }
}
