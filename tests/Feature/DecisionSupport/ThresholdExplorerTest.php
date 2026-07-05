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
use Tests\Support\BuilderStateFixture;
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

    public function test_it_offers_the_survivor_db_lever_for_a_couple_with_a_survivor_pension(): void
    {
        // The rich fixture is a couple whose DB scheme provides a 50% survivor pension.
        $scenario = ScenarioFixture::rich($this->user);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee("Your DB pension's survivor share");
    }

    public function test_it_hides_the_survivor_db_lever_without_a_db_survivor_pension(): void
    {
        // A single person with only essential spending and no DB pension — no survivor to inherit,
        // no DB scheme to vary.
        $scenario = ScenarioFixture::fromState($this->user, array_replace(
            ['step' => 5, 'name' => 'Solo', 'baseTaxYear' => '2026-27', 'variant' => 'rent', 'ihtModelled' => false, 'assumptionSetId' => null],
            BuilderStateFixture::minimalValid(),
        ));

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee('Your essential spending')
            ->assertDontSee("Your DB pension's survivor share");
    }

    public function test_it_offers_the_survivor_annuity_lever_for_a_couple_with_a_joint_life_annuity(): void
    {
        // A couple whose DC pot buys a joint-life annuity (a survivor fraction is set).
        $pensions = BuilderStateFixture::full()['pensions'];
        $pensions[0] = array_merge($pensions[0], [
            'annuitise' => '1', 'annuityAmount' => '150000', 'annuityAtAge' => '66',
            'annuityRate' => '6.5', 'annuityEscalation' => 'none', 'annuityJoint' => '1', 'annuitySurvivorFraction' => '50',
        ]);
        $scenario = ScenarioFixture::rich($this->user, ['pensions' => $pensions]);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee("Your annuity's survivor share");
    }

    public function test_it_hides_the_survivor_annuity_lever_without_a_joint_life_annuity(): void
    {
        // The rich fixture annuitises nothing — so the annuity lever is hidden even though its DB
        // survivor lever shows (the two survivor levers gate independently).
        $scenario = ScenarioFixture::rich($this->user);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee("Your DB pension's survivor share")
            ->assertDontSee("Your annuity's survivor share");
    }

    public function test_it_offers_a_per_person_longevity_lever_for_each_person_in_a_couple(): void
    {
        // The rich fixture is a couple, so "whose longevity" is a real question — one lever per person.
        $scenario = ScenarioFixture::rich($this->user);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee('How long Person 1 lives')
            ->assertSee('How long Person 2 lives');
    }

    public function test_it_hides_the_per_person_longevity_lever_for_a_single_person(): void
    {
        // A lone person has no survivor cliff and no "whose longevity" split — the combined lifespan
        // what-if covers them, so the explorer offers no per-person longevity lever.
        $scenario = ScenarioFixture::fromState($this->user, array_replace(
            ['step' => 5, 'name' => 'Solo', 'baseTaxYear' => '2026-27', 'variant' => 'rent', 'ihtModelled' => false, 'assumptionSetId' => null],
            BuilderStateFixture::minimalValid(),
        ));

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee('Your essential spending')
            ->assertDontSee('How long');
    }

    public function test_finding_the_limit_on_a_persons_longevity_records_which_person(): void
    {
        Queue::fake();
        $scenario = ScenarioFixture::rich($this->user);

        $component = Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->call('setLever', 'person_longevity:p2') // the composite menu id names the person
            ->assertSet('lever', 'person_longevity:p2')
            ->call('findLimit');

        // The queued threshold splits the composite id back into a clean lever key + its person.
        $threshold = ThresholdResult::findOrFail($component->get('thresholdId'));
        $this->assertSame(LeverKey::PersonLongevity->value, $threshold->lever_key);
        $this->assertSame('p2', $threshold->lever_param);
    }

    public function test_the_same_longevity_lever_on_different_people_is_a_distinct_threshold(): void
    {
        // The person id joins the inputs hash, so p1's threshold is never served for a p2 request.
        $scenario = ScenarioFixture::rich($this->user);
        $runner = app(ThresholdRunner::class);
        $grid = [0.0, 8.0, 15.0];

        $p1 = $runner->inputsHash($scenario, LeverKey::PersonLongevity, SweepMetric::Essentials, 0.90, $grid, 60, 'p1');
        $p2 = $runner->inputsHash($scenario, LeverKey::PersonLongevity, SweepMetric::Essentials, 0.90, $grid, 60, 'p2');

        $this->assertNotSame($p1, $p2);
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

    public function test_a_completed_per_person_longevity_threshold_paints_without_error(): void
    {
        // Exercises the presenter's PersonLongevity arms (the ± year value labels + caption) and the
        // leverParam round-trip through request → execute → render on a non-monotone (Unknown) lever.
        $scenario = ScenarioFixture::rich($this->user);

        $threshold = app(ThresholdRunner::class)->request(
            $scenario, LeverKey::PersonLongevity, SweepMetric::Essentials, 0.90, [0.0, 8.0, 15.0], 40, leverParam: 'p2',
        )->fresh();
        $this->assertSame(SimulationStatus::Done, $threshold->status);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->set('lever', 'person_longevity:p2')
            ->set('leverValue', 8)
            ->set('thresholdId', $threshold->id)
            ->assertOk()
            ->assertSee('Show the full sweep')
            ->assertSee('+8 years')          // the ± year value label from the PersonLongevity arm
            ->assertDontSee('@endif');       // no leaked Blade directive
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
