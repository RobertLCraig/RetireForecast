<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\Enums\ScenarioStatus;
use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Livewire\ScenarioCompare;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Decision-support Phase 3 — the ordering gate on the combination comparison. Best-first
 * ordering is implicit advice (the phrasing lint is blind to sort order), so it is reachable
 * only behind the `interpret` ability: ranked + a "which to lean towards" narrative in advice
 * mode, plan-order + no narrative when the regulatory flag is flipped for the guidance posture.
 */
final class CombinationComparisonGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user = User::factory()->create());
    }

    public function test_advice_mode_reorders_best_first_and_shows_the_ranking(): void
    {
        // The suite runs with compliance.personal_use = true (advice mode) → interpret allowed.
        $base = ScenarioFixture::rich($this->user);
        $stronger = $this->childOf($base, ['expenseLines.ess1.amount' => '12000'], 'Spend less');
        $this->completedRun($base, $this->mc(0.55));      // weaker
        $this->completedRun($stronger, $this->mc(0.92));  // stronger

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertViewHas('combinationRanked', true)
            ->assertViewHas('combinationRanking', fn (array $lines): bool => $lines !== [])
            ->assertViewHas('comparison', fn (array $c): bool => $c['rows'][0]['name'] === 'Spend less') // best leads
            ->assertSee('Chance the money lasts, across your futures') // the section renders
            ->assertSee('Very likely to last')                        // the stronger plan's word-band chip (0.92)
            ->assertSee('lean towards')
            ->assertDontSee('@endif');                                // no leaked Blade directive
    }

    public function test_guidance_mode_keeps_plan_order_and_hides_the_ranking(): void
    {
        // Flip the regulatory line: interpret now falls back to the per-user grant (off) → denied.
        config(['compliance.personal_use' => false]);

        $base = ScenarioFixture::rich($this->user);
        $stronger = $this->childOf($base, ['expenseLines.ess1.amount' => '12000'], 'Spend less');
        $this->completedRun($base, $this->mc(0.55));
        $this->completedRun($stronger, $this->mc(0.92));

        Livewire::test(ScenarioCompare::class, ['scenario' => $base])
            ->assertViewHas('combinationRanked', false)
            ->assertViewHas('combinationRanking', [])
            // Plan order preserved: the base leads even though the child is the stronger plan.
            ->assertViewHas('comparison', fn (array $c): bool => $c['rows'][0]['name'] === $base->name && $c['rows'][0]['isBase'] === true)
            ->assertSee('not ranked')
            ->assertDontSee('lean towards');
    }

    private function childOf(Scenario $base, array $overrides, string $name): Scenario
    {
        $child = new Scenario;
        $child->user_id = $this->user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = ['name' => $name] + $overrides;
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        return $child->fresh();
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

    private function completedRun(Scenario $plan, SimulationResult $mc): void
    {
        $run = SimulationRun::create([
            'scenario_id' => $plan->id,
            'user_id' => $this->user->id,
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
}
