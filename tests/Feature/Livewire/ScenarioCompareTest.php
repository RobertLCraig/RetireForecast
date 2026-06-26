<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Livewire\ScenarioCompare;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Phase C2 — the Compare page lays a base plan beside its delta-child what-ifs using
 * each one's deterministic central projection (so it shows immediately). It is
 * owner-scoped, base-centric, and never ranks the options.
 */
class ScenarioCompareTest extends TestCase
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

    public function test_it_shows_the_base_and_its_children_with_figures(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $this->childOf($base, $user, ['expense.essential' => '60000'], 'Spend more');

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertSee('Buy-vs-rent')   // the base name
            ->assertSee('Spend more')    // the child name
            ->assertSee('Base')          // the base is labelled
            ->assertViewHas('plans', fn ($plans) => $plans->count() === 2);
    }

    public function test_an_overridden_figure_changes_that_plans_column(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $child = $this->childOf($base, $user, ['expense.essential' => '90000'], 'Much higher spend');

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertViewHas('plans', function ($plans) {
                $childRow = $plans->firstWhere('name', 'Much higher spend');
                $baseRow = $plans->firstWhere('isBase', true);

                // The child spends far more, so its usable wealth left differs from the base's.
                return $childRow !== null
                    && $baseRow !== null
                    && $childRow['usableWealth'] !== $baseRow['usableWealth'];
            });
    }

    public function test_opening_compare_on_a_child_uses_its_base(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $child = $this->childOf($base, $user, ['expense.essential' => '60000'], 'A what-if');

        Livewire::test(ScenarioCompare::class, ['scenario' => $child])
            ->assertSet('base.id', $base->id);
    }

    public function test_compare_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $base = ScenarioFixture::rich($owner);

        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.compare', $base))->assertForbidden();
    }
}
