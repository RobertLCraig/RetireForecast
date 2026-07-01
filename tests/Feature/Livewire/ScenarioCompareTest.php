<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Enums\SimulationMode;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Jobs\RunScenarioSimulation;
use App\Livewire\ScenarioCompare;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
        $this->childOf($base, $user, ['expenseLines.ess1.amount' => '60000'], 'Spend more');

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertSee('Buy-vs-rent')          // the base name
            ->assertSee('Spend more')           // the child name
            ->assertSee('Base')                 // the base is labelled
            ->assertSee('Essentials · amount')  // the what-if's change, tagged
            ->assertSee('£60,000')              // the new value, in the tag
            ->assertViewHas('plans', fn ($plans) => $plans->count() === 2);
    }

    public function test_an_overridden_figure_changes_that_plans_column(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $child = $this->childOf($base, $user, ['expenseLines.ess1.amount' => '90000'], 'Much higher spend');

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
        $child = $this->childOf($base, $user, ['expenseLines.ess1.amount' => '60000'], 'A what-if');

        Livewire::test(ScenarioCompare::class, ['scenario' => $child])
            ->assertSet('base.id', $base->id);
    }

    public function test_it_shows_a_wealth_over_time_burndown_overlay(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $this->childOf($base, $user, ['expenseLines.ess1.amount' => '90000'], 'Spend more');

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertSee('Usable wealth over time')
            ->assertViewHas('burndown', function (array $burndown): bool {
                // One overlaid line per plan (base + the what-if); each plan has a cell for
                // every year in the union (a figure or null for years it does not reach).
                return count($burndown['rows']) === 2
                    && $burndown['years'] !== []
                    && count($burndown['rows'][0]['cells']) === count($burndown['years'])
                    && $burndown['rows'][1]['name'] === 'Spend more';
            });
    }

    public function test_the_burndown_reconciles_to_the_cashflow_ladder_usable_wealth(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);

        $forecast = app(ScenarioForecaster::class)->deterministic($base);
        $burndown = ResultPresenter::burndown([['name' => $base->name, 'forecast' => $forecast]]);
        $ladder = ResultPresenter::ladder($forecast);

        // The burndown's usable-wealth line is the SAME figure the cashflow ladder shows for
        // each year — one definition, so the chart can't drift from the table.
        foreach ($ladder['rows'] as $row) {
            $this->assertSame($row['usableWealth'], $burndown['rows'][0]['cells'][$row['year']], "usable wealth in {$row['year']}");
        }
    }

    public function test_re_run_all_queues_a_full_run_for_every_plan_compared(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $this->childOf($base, $user, ['expenseLines.ess1.amount' => '31000'], 'What-if A');
        $this->childOf($base, $user, ['expenseLines.ess1.amount' => '32000'], 'What-if B');

        $component = Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->call('runFullFamily');

        // Base + 2 ready what-ifs = 3 full runs queued, one per compared plan.
        Queue::assertPushed(RunScenarioSimulation::class, 3);
        $this->assertSame(3, SimulationRun::where('mode', SimulationMode::Full)->count());
        $component->assertSet('familyQueued', 3);
    }

    public function test_re_run_all_from_a_child_still_covers_the_whole_family(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $child = $this->childOf($base, $user, ['expenseLines.ess1.amount' => '31000'], 'What-if A');
        $this->childOf($base, $user, ['expenseLines.ess1.amount' => '32000'], 'What-if B');

        // Compare is base-centric, so launched from a child it still runs the base + all children.
        Livewire::test(ScenarioCompare::class, ['scenario' => $child])
            ->call('runFullFamily')
            ->assertSet('familyQueued', 3);
        Queue::assertPushed(RunScenarioSimulation::class, 3);
    }

    public function test_the_re_run_all_button_reflects_the_plan_count(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $this->childOf($base, $user, ['expenseLines.ess1.amount' => '31000'], 'What-if A');

        // Base + 1 what-if = 2 plans on the page.
        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertSee('Re-run all 2 (full 10k)');
    }

    public function test_an_unaffordable_buy_cheaper_plan_is_flagged_on_compare(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        // A "sell & buy cheaper" what-if whose buy price dwarfs the sale proceeds — the engine
        // would floor the surplus at £0 and "buy" anyway, so Compare must flag the shortfall
        // rather than let an unaffordable plan sit in the comparison unmarked.
        $this->childOf($base, $user, ['variant' => 'buy_outright', 'housing.buyPrice' => '5000000'], 'Buy a mansion');

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertViewHas('plans', function ($plans): bool {
                $row = $plans->firstWhere('name', 'Buy a mansion');

                return $row !== null && $row['buyShortfall'] !== null;
            })
            ->assertSee('more than the sale frees');
    }

    public function test_a_plan_within_its_means_carries_no_buy_shortfall_flag(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user); // no unaffordable buy plan → nothing to flag

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertViewHas('plans', fn ($plans): bool => $plans->every(fn (array $p): bool => $p['buyShortfall'] === null))
            ->assertDontSee('more than the sale frees');
    }

    public function test_compare_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $base = ScenarioFixture::rich($owner);

        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.compare', $base))->assertForbidden();
    }
}
