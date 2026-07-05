<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\LeverKey;
use App\DecisionSupport\ThresholdRunner;
use App\Enums\SimulationStatus;
use App\Jobs\RunLeverThreshold;
use App\Livewire\ThresholdExplorer;
use App\Models\ThresholdResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The "How far can we go?" panel (Phase 2). Proves the panel renders with the levers the
 * scenario can move, the slider drives the instant deterministic line, asking for the limit
 * queues the Monte Carlo threshold, and a completed threshold paints the meter + full sweep —
 * and that a tampered threshold id can't load another user's figures.
 */
final class ThresholdExplorerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user = User::factory()->create());
    }

    private User $user;

    public function test_it_renders_the_panel_and_offers_levers(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee('How far can we go?')
            ->assertSee('Your essential spending')     // a lever that always applies
            ->assertSee('Find the limit');
    }

    public function test_switching_lever_resets_the_value_and_clears_the_threshold(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->set('thresholdId', 999)
            ->call('setLever', LeverKey::EssentialSpend->value)
            ->assertSet('lever', LeverKey::EssentialSpend->value)
            ->assertSet('thresholdId', null); // a threshold is lever-specific
    }

    public function test_moving_the_slider_redraws_the_deterministic_line(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        // A different lever value produces a different net-position table (the line redrew).
        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->call('setLever', LeverKey::EssentialSpend->value)
            ->set('leverValue', 20_000)
            ->assertOk()
            ->set('leverValue', 40_000)
            ->assertOk();
    }

    public function test_find_limit_queues_the_threshold(): void
    {
        Queue::fake();
        $scenario = ScenarioFixture::rich($this->user);

        $component = Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->call('setLever', LeverKey::EssentialSpend->value)
            ->call('findLimit');

        $this->assertNotNull($component->get('thresholdId'));
        Queue::assertPushed(RunLeverThreshold::class);
        $this->assertSame(1, ThresholdResult::where('scenario_id', $scenario->id)->count());
    }

    public function test_a_completed_threshold_paints_the_meter_and_full_sweep(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        // Pre-compute a small, fast threshold to Done (sync queue runs it inline).
        $threshold = app(ThresholdRunner::class)->request(
            $scenario, LeverKey::EssentialSpend, SweepMetric::Essentials, 0.90, [18_000.0, 30_000.0, 42_000.0], 40,
        )->fresh();
        $this->assertSame(SimulationStatus::Done, $threshold->status);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->set('lever', LeverKey::EssentialSpend->value)
            ->set('leverValue', 30_000)
            ->set('thresholdId', $threshold->id)
            ->assertSee('Show the full sweep')
            ->assertSee('Download CSV')
            ->assertDontSee('@endif'); // no leaked Blade directive
    }

    public function test_a_threshold_id_from_another_user_does_not_load(): void
    {
        $mine = ScenarioFixture::rich($this->user);

        // Another user's completed threshold.
        $stranger = User::factory()->create();
        $theirScenario = ScenarioFixture::rich($stranger);
        $theirThreshold = app(ThresholdRunner::class)->request(
            $theirScenario, LeverKey::EssentialSpend, SweepMetric::Essentials, 0.90, [18_000.0, 30_000.0], 40,
        )->fresh();

        // Point my panel at their threshold id: it must not surface (no meter, still offers "Find the limit").
        Livewire::test(ThresholdExplorer::class, ['scenario' => $mine])
            ->set('lever', LeverKey::EssentialSpend->value)
            ->set('thresholdId', $theirThreshold->id)
            ->assertSee('Find the limit')
            ->assertDontSee('Show the full sweep');
    }
}
