<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\LeverKey;
use App\DecisionSupport\ThresholdRunner;
use App\Enums\ScenarioStatus;
use App\Livewire\ScenarioBuilder;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use Tests\Feature\Livewire\ScenarioEditTest;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * A computed threshold rests on the scenario's inputs, so editing the scenario must invalidate
 * it exactly as it invalidates a stale simulation run — the user is never shown a threshold from
 * inputs that no longer hold. Mirrors {@see ScenarioEditTest}.
 */
final class ThresholdInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private function threshold(Scenario $scenario): void
    {
        $runner = app(ThresholdRunner::class);
        $hash = $runner->inputsHash($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, [62.0], 40);
        $runner->createRun($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, [62.0], 40, $hash);
    }

    public function test_editing_and_saving_invalidates_a_stale_threshold(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $scenario = ScenarioFixture::rich($user);

        $this->threshold($scenario);
        $this->assertSame(1, $scenario->thresholdResults()->count());

        Livewire::test(ScenarioBuilder::class, ['scenario' => $scenario])
            ->set('expenseLines.0.amount', '31000') // the essential line — a real input change
            ->call('save');

        $this->assertSame(0, $scenario->thresholdResults()->count());
        $this->assertDatabaseCount('threshold_results', 0);
    }

    public function test_editing_a_base_invalidates_its_children_thresholds(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user);

        // A delta-child what-if of the base, with its own computed threshold.
        $child = new Scenario;
        $child->user_id = $user->id;
        $child->parent_scenario_id = $base->id;
        $child->setRelation('parent', $base);
        $child->overrides = ['name' => 'Later retirement'];
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        $this->threshold($child->fresh());
        $this->assertSame(1, $child->thresholdResults()->count());

        // Editing the base changes the child's effective inputs, so the child's threshold is stale.
        Livewire::test(ScenarioBuilder::class, ['scenario' => $base])
            ->set('expenseLines.0.amount', '31000')
            ->call('save');

        $this->assertSame(0, $child->thresholdResults()->count());
    }
}
