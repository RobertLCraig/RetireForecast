<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\Enums\ScenarioStatus;
use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Decision-support Phase 3 — the combination-comparison CSV. The analyst's exact per-plan Monte
 * Carlo figures, carrying the guidance-only disclaimer so a downloaded figure never travels
 * without its framing. Owner-scoped and base-centric (a child exports its whole family), and an
 * unsimulated plan is honestly marked rather than dropped.
 */
final class CombinationCsvExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_csv_carries_the_disclaimer_and_per_plan_figures(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $this->completedRun($base, $user, $this->mc(0.88));

        $csv = $this->download($base);

        $this->assertStringContainsString('guidance only, not financial advice', strtolower($csv));
        $this->assertStringContainsString('Chance essentials last', $csv); // the header
        $this->assertStringContainsString('88%', $csv);                     // the figure
        $this->assertStringContainsString('Simulated paths', $csv);
    }

    public function test_a_plan_without_a_run_is_marked_not_simulated_not_dropped(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user); // no run

        $csv = $this->download($base);

        $this->assertStringContainsString($base->name, $csv);
        $this->assertStringContainsString('Not simulated yet', $csv);
    }

    public function test_the_csv_is_base_centric_from_a_child(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);
        $child = $this->childOf($base, $user, ['expenseLines.ess1.amount' => '60000'], 'Spend more');

        // Downloading via the CHILD still exports the whole family.
        $csv = $this->download($child);

        $this->assertStringContainsString($base->name, $csv);
        $this->assertStringContainsString('Spend more', $csv);
    }

    public function test_the_csv_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $base = ScenarioFixture::rich($owner);

        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.compare.csv', $base))->assertForbidden();
    }

    private function download(Scenario $scenario): string
    {
        $response = $this->get(route('scenarios.compare.csv', $scenario));
        $response->assertOk();

        return $response->streamedContent();
    }

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

        return $child->fresh();
    }

    private function mc(float $ess): SimulationResult
    {
        $p = fn (int $v): array => ['p10' => Money::fromPence($v), 'p25' => Money::fromPence($v), 'p50' => Money::fromPence($v), 'p75' => Money::fromPence($v), 'p90' => Money::fromPence($v)];

        return new SimulationResult(
            nPaths: 2000,
            seed: 42,
            successProbabilityEssentials: $ess,
            successProbabilityFullSpend: $ess * 0.7,
            depletionRate: 1.0 - $ess,
            medianDepletionYear: 2050,
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
}
