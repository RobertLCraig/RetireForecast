<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Forecast\ResultPresenter;
use App\Jobs\RunScenarioSimulation;
use App\Livewire\Affordability;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
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
            // The deterministic verdict is still here, but demoted below the probability (B1).
            ->assertSee('On the expected path')
            ->assertViewHas('working', fn (array $w): bool => collect($w)->contains(fn ($c) => $c['title'] === 'Keep the home'))
            ->assertViewHas('failing', fn (array $f): bool => collect($f)->contains(fn ($c) => $c['title'] === 'Spend far too much' && $c['runsOutYear'] !== null))
            // The bottom line names a working plan, never a failing one.
            ->assertViewHas('bottomLine', fn (array $b): bool => $b['best'] !== null && $b['best']['works'] === true);
    }

    public function test_it_surfaces_a_care_stress_verdict_beside_every_plan(): void
    {
        // A2: the verdict is the expected, care-free path, so every plan must carry the "if significant
        // care is needed" companion — the base "lasts for life" is never shown against a silently
        // care-free projection. Assert the structure reaches the view (completeness) and the copy shows.
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put', 'name' => 'Keep the home', 'expenseLines.ess1.amount' => '8000']);

        Livewire::test(Affordability::class, ['scenario' => $base])
            ->assertOk()
            // Every working plan card carries a non-empty care-stress verdict...
            ->assertViewHas('working', fn (array $w): bool => $w !== [] && collect($w)->every(
                fn ($c) => isset($c['careStress']['verdict']) && $c['careStress']['verdict'] !== '' && array_key_exists('holds', $c['careStress']),
            ))
            // ...the bottom line qualifies "for life" with the care caveat...
            ->assertViewHas('bottomLine', fn (array $b): bool => ! empty($b['careCaveat']) && str_contains($b['careCaveat'], 'long-term care'))
            // ...and the care line is visible to the reader.
            ->assertSee('nursing care');
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

    /**
     * B1 (card 0010 #1): the landing opens on the Monte Carlo chance and its plain word band, not
     * on the deterministic yes/no. The band comes from the one banding home, so this page and the
     * comparison chip can never put the same probability in two different words.
     */
    public function test_it_leads_with_the_monte_carlo_probability_and_its_word_band(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put', 'name' => 'Keep the home', 'expenseLines.ess1.amount' => '8000']);
        $this->completedRun($base, $user, $this->mc(0.62));

        Livewire::test(Affordability::class, ['scenario' => $base])
            ->assertOk()
            ->assertViewHas('bottomLine', fn (array $b): bool => $b['lead']['checked'] === true
                && $b['lead']['percent'] === '62%'
                && $b['lead']['band'] === ResultPresenter::lastsBand(0.62)
                && $b['lead']['plan'] === 'Keep the home')
            // The figure and its word are on the page, above the expected-path verdict.
            ->assertSeeInOrder(['How sure is your strongest plan?', '62%', 'Borderline', 'On the expected path'])
            ->assertDontSee('@endif'); // no leaked Blade directive
    }

    /**
     * B1 (card 0010 #2): with no completed run there is no probability, so the page says so. The
     * deterministic verdict is one average future and must never stand in the probability's place.
     */
    public function test_it_says_the_probability_is_unchecked_rather_than_showing_a_deterministic_figure(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put', 'name' => 'Keep the home', 'expenseLines.ess1.amount' => '8000']);

        Livewire::test(Affordability::class, ['scenario' => $base])
            ->assertOk()
            ->assertViewHas('bottomLine', fn (array $b): bool => $b['lead']['checked'] === false
                && $b['lead']['percent'] === null
                && $b['lead']['band'] === null)
            ->assertSeeInOrder(['How sure is your strongest plan?', 'Not checked yet', 'not a probability'])
            // The offer to run the real check, rather than a figure standing in for one.
            ->assertSee('Check how sure');
    }

    /** A minimal but valid Monte Carlo result, pinned to a chosen "chance essentials last". */
    private function mc(float $ess): SimulationResult
    {
        $p = fn (int $v): array => ['p10' => Money::fromPence($v), 'p25' => Money::fromPence($v), 'p50' => Money::fromPence($v), 'p75' => Money::fromPence($v), 'p90' => Money::fromPence($v)];

        return new SimulationResult(
            nPaths: 2000,
            seed: 42,
            successProbabilityEssentials: $ess,
            successProbabilityFullSpend: $ess * 0.7,
            depletionRate: 1.0 - $ess,
            medianDepletionYear: $ess >= 0.9 ? null : 2050,
            terminalWealthPercentiles: $p((int) round($ess * 100_000_00)),
            fanChart: [],
            usableWealthPercentiles: $p((int) round($ess * 100_000_00)),
            usableFanChart: [],
            netPositionFanChart: [['calendarYear' => 2030, 'paths' => 2000] + $p((int) round($ess * 100_000_00))],
        );
    }

    private function completedRun(Scenario $plan, User $user, SimulationResult $mc): void
    {
        $run = SimulationRun::create([
            'scenario_id' => $plan->id,
            'user_id' => $user->id,
            'mode' => SimulationMode::Full,
            'n_paths' => $mc->nPaths,
            'seed' => $mc->seed,
            'status' => SimulationStatus::Done,
            'progress_pct' => 100,
            'engine_version' => 'test',
            'taxyear_config_version' => 'test',
            'assumption_snapshot' => [],
        ]);

        $result = new Result(['simulation_run_id' => $run->id, 'variant' => $plan->variant]);
        $result->setSimulationResult($mc);
        $result->save();
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
