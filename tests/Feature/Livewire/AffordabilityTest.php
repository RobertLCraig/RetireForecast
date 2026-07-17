<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Jobs\RunScenarioSimulation;
use App\Livewire\Affordability;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * "What you can afford" reduces a base plan and its what-if children to a plain works / doesn't
 * work verdict, driven by the deterministic central projection, and leads with the plans that
 * keep the essentials paid for life. Owner-scoped and base-centric, like Compare.
 */
class AffordabilityTest extends TestCase
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

    public function test_it_separates_plans_that_work_from_plans_that_do_not(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // A base with a modest essential spend so it comfortably lasts.
        $base = ScenarioFixture::rich($user, [
            'variant' => 'stay_put',
            'name' => 'Keep the home',
            'expenseLines.ess1.amount' => '8000',
        ]);
        // A child whose essentials are so high the money must run out.
        $broke = $this->childOf($base, $user, ['expenseLines.ess1.amount' => '250000'], 'Spend far too much');

        Livewire::test(Affordability::class, ['scenario' => $base])
            ->assertOk()
            ->assertSee('What you can afford')
            ->assertSee('The bottom line')
            ->assertViewHas('working', fn (array $w): bool => collect($w)->contains(fn ($c) => $c['title'] === 'Keep the home'))
            ->assertViewHas('failing', fn (array $f): bool => collect($f)->contains(fn ($c) => $c['title'] === 'Spend far too much' && $c['runsOutYear'] !== null))
            // The bottom line names a working plan, never a failing one.
            ->assertViewHas('bottomLine', fn (array $b): bool => $b['best'] !== null && $b['best']['works'] === true);
    }

    public function test_working_plans_are_ordered_strongest_first(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        // Base comfortably lasts with plenty to spare; a leaner child also works but leaves less.
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put', 'name' => 'Roomy plan', 'expenseLines.ess1.amount' => '6000']);
        $this->childOf($base, $user, ['expenseLines.ess1.amount' => '14000'], 'Leaner plan');

        Livewire::test(Affordability::class, ['scenario' => $base])
            ->assertViewHas('working', function (array $w): bool {
                // Descending money-left: each card leaves at least as much as the next.
                for ($i = 1; $i < count($w); $i++) {
                    if ($w[$i - 1]['moneyLeftPence'] < $w[$i]['moneyLeftPence']) {
                        return false;
                    }
                }

                return count($w) >= 2;
            });
    }

    public function test_opening_on_a_child_assesses_the_whole_family_from_the_base(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put', 'name' => 'Base plan']);
        $child = $this->childOf($base, $user, ['expenseLines.ess1.amount' => '9000'], 'A what-if');

        Livewire::test(Affordability::class, ['scenario' => $child])
            ->assertOk()
            ->assertViewHas('bottomLine', fn (array $b): bool => $b['total'] === 2);
    }

    public function test_check_how_sure_queues_full_runs_and_hands_off_to_compare(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put', 'name' => 'Base plan']);
        $this->childOf($base, $user, ['expenseLines.ess1.amount' => '9000'], 'A what-if');

        // No stored runs yet, so both plans are queued, and the page hands off to Compare's progress UI.
        Livewire::test(Affordability::class, ['scenario' => $base])
            ->call('checkHowSure')
            ->assertRedirect(route('scenarios.compare', $base));

        Queue::assertPushed(RunScenarioSimulation::class, 2);
    }

    public function test_it_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $base = ScenarioFixture::rich($owner, ['variant' => 'stay_put']);

        $this->actingAs($intruder);
        Livewire::test(Affordability::class, ['scenario' => $base])->assertForbidden();
    }
}
