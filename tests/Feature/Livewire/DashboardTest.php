<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Livewire\Dashboard;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The dashboard lists a user's base forecasts with their what-if children nested under
 * them. A what-if is tagged with what it changed from its base, so the difference is
 * visible at a glance from the list, not only after opening it.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function childOf(Scenario $base, User $user, array $overrides, string $name): Scenario
    {
        $child = new Scenario;
        $child->user_id = $user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = ['name' => $name] + $overrides;
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        return $child;
    }

    public function test_a_what_if_is_tagged_with_what_it_changed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $this->childOf($base, $user, ['housing.annualRent' => '20000'], 'Higher rent');

        Livewire::test(Dashboard::class)
            ->assertSee('Higher rent')                 // the child name
            ->assertSee('Rent if you sell & rent')     // the change label, as a tag
            ->assertSee('£20,000')                     // the new value, in the tag
            ->assertViewHas('whatIfChanges');
    }

    public function test_a_base_with_no_what_ifs_shows_no_tags(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        ScenarioFixture::rich($user);

        Livewire::test(Dashboard::class)
            ->assertViewHas('whatIfChanges', fn (array $changes): bool => $changes === []);
    }
}
