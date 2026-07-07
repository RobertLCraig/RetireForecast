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

    public function test_it_offers_a_per_person_sp_deferral_lever_for_each_person_in_a_couple(): void
    {
        // The rich fixture is a couple, both holding a State Pension — so "whose State Pension to
        // defer" is a real survivor question, one lever per person.
        $scenario = ScenarioFixture::rich($this->user);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee('How long Person 1 defers their State Pension')
            ->assertSee('How long Person 2 defers their State Pension');
    }

    public function test_it_hides_the_sp_deferral_lever_for_a_single_person(): void
    {
        // A lone person's deferral is a plain income-timing choice, not a survivor question, so the
        // explorer offers no per-person State Pension deferral lever.
        $scenario = ScenarioFixture::fromState($this->user, array_replace(
            ['step' => 5, 'name' => 'Solo', 'baseTaxYear' => '2026-27', 'variant' => 'rent', 'ihtModelled' => false, 'assumptionSetId' => null],
            BuilderStateFixture::minimalValid(),
        ));

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee('Your essential spending')
            ->assertDontSee('defers their State Pension');
    }

    public function test_finding_the_limit_on_sp_deferral_records_which_person(): void
    {
        Queue::fake();
        $scenario = ScenarioFixture::rich($this->user);

        $component = Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->call('setLever', 'sp_deferral:p2') // the composite menu id names the person
            ->assertSet('lever', 'sp_deferral:p2')
            ->call('findLimit');

        $threshold = ThresholdResult::findOrFail($component->get('thresholdId'));
        $this->assertSame(LeverKey::StatePensionDeferral->value, $threshold->lever_key);
        $this->assertSame('p2', $threshold->lever_param);
    }

    public function test_a_completed_sp_deferral_threshold_paints_without_error(): void
    {
        // Exercises the presenter's StatePensionDeferral arms (the "N years later" value labels + the
        // trade-off caption) and the leverParam round-trip on a non-monotone (Unknown) lever.
        $scenario = ScenarioFixture::rich($this->user);

        $threshold = app(ThresholdRunner::class)->request(
            $scenario, LeverKey::StatePensionDeferral, SweepMetric::Essentials, 0.90, [0.0, 2.0, 4.0], 40, leverParam: 'p2',
        )->fresh();
        $this->assertSame(SimulationStatus::Done, $threshold->status);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->set('lever', 'sp_deferral:p2')
            ->set('leverValue', 2)
            ->set('thresholdId', $threshold->id)
            ->assertOk()
            ->assertSee('Show the full sweep')
            ->assertSee('2 years later')      // the value label from the StatePensionDeferral arm
            ->assertDontSee('@endif');        // no leaked Blade directive
    }

    public function test_it_offers_the_care_lever_ungated_for_a_couple_and_a_single_person(): void
    {
        // Care risk is off by default and applies to anyone — so unlike the survivor levers it is
        // offered whether the household is a couple or a lone person.
        $couple = ScenarioFixture::rich($this->user);
        Livewire::test(ThresholdExplorer::class, ['scenario' => $couple])
            ->assertSee('Whether care fees are modelled');

        $solo = ScenarioFixture::fromState($this->user, array_replace(
            ['step' => 5, 'name' => 'Solo', 'baseTaxYear' => '2026-27', 'variant' => 'rent', 'ihtModelled' => false, 'assumptionSetId' => null],
            BuilderStateFixture::minimalValid(),
        ));
        Livewire::test(ThresholdExplorer::class, ['scenario' => $solo])
            ->assertSee('Whether care fees are modelled');
    }

    public function test_finding_the_care_comparison_stores_the_binary_grid(): void
    {
        Queue::fake();
        $scenario = ScenarioFixture::rich($this->user);

        $component = Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->call('setLever', 'care')
            ->assertSet('lever', 'care')
            ->call('findLimit');

        // The care sweep is a two-point pinned before/after: a categorical [off, on] grid, household-wide.
        $threshold = ThresholdResult::findOrFail($component->get('thresholdId'));
        $this->assertSame(LeverKey::Care->value, $threshold->lever_key);
        $this->assertNull($threshold->lever_param);
        // Stored grid round-trips through JSON, so the two whole-number points come back as int 0/1
        // (functionally identical — the lever reads value >= 0.5).
        $this->assertEquals([0.0, 1.0], $threshold->grid);
    }

    public function test_a_completed_care_comparison_paints_two_states_and_no_meter(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        // A small, fast care sweep to Done (two 40-path runs, off and on).
        $threshold = app(ThresholdRunner::class)->request(
            $scenario, LeverKey::Care, SweepMetric::Essentials, 0.90, [0.0, 1.0], 40,
        )->fresh();
        $this->assertSame(SimulationStatus::Done, $threshold->status);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->set('lever', 'care')
            ->set('thresholdId', $threshold->id)
            ->assertOk()
            // The two-state before/after — never a slider, meter or interpolated "limit".
            ->assertSee('Care fees not modelled')
            ->assertSee('Care fees modelled')
            ->assertSee('two separate simulated futures')     // the pin-and-compare caption
            ->assertSee('Download CSV')
            ->assertDontSee('On track at this setting')       // no meter (that is a continuous-lever readout)
            ->assertDontSee('id="lever-slider"', escape: false) // no slider
            ->assertDontSee('the money stays on track up to')  // no meterCaption
            ->assertDontSee('safe')                            // the neutral-copy guardrail
            ->assertDontSee('@endif');                         // no leaked Blade directive
    }

    public function test_it_offers_the_trade_off_map_when_both_axes_apply(): void
    {
        // The rich fixture buys a cheaper home AND has a working partner — both axes are live.
        $scenario = ScenarioFixture::rich($this->user);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee('The trade-off map')
            ->assertSee('Map the trade-off');
    }

    public function test_it_hides_the_trade_off_map_when_an_axis_is_missing(): void
    {
        // A lone retiree who buys nothing: no buy price to sweep, no retirement age to hold.
        $scenario = ScenarioFixture::fromState($this->user, array_replace(
            ['step' => 5, 'name' => 'Solo', 'baseTaxYear' => '2026-27', 'variant' => 'rent', 'ihtModelled' => false, 'assumptionSetId' => null],
            BuilderStateFixture::minimalValid(),
        ));

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->assertSee('How far can we go?')
            ->assertDontSee('The trade-off map');
    }

    public function test_map_frontier_queues_the_frontier_run(): void
    {
        Queue::fake();
        $scenario = ScenarioFixture::rich($this->user);

        $component = Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->call('mapFrontier');

        $this->assertNotNull($component->get('frontierId'));
        Queue::assertPushed(RunLeverThreshold::class);

        $run = ThresholdResult::where('scenario_id', $scenario->id)->sole();
        $this->assertTrue($run->isFrontier());
        $this->assertSame(LeverKey::BuyPrice->value, $run->lever_key);
        $this->assertSame(LeverKey::RetirementAge->value, $run->condition_lever_key);
        $this->assertNotEmpty($run->condition_grid);
    }

    public function test_a_completed_frontier_paints_the_map(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        // A small, fast frontier to Done (2 columns × 2 cells at 30 paths; sync queue runs it inline).
        $frontier = app(ThresholdRunner::class)->requestFrontier(
            $scenario, LeverKey::BuyPrice, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90,
            thresholdGrid: [150_000.0, 250_000.0], conditionGrid: [62.0, 70.0], paths: 30,
        )->fresh();
        $this->assertSame(SimulationStatus::Done, $frontier->status);

        Livewire::test(ThresholdExplorer::class, ['scenario' => $scenario])
            ->set('frontierId', $frontier->id)
            ->assertOk()
            ->assertSee('Show the full map')
            ->assertSee('Age 62')                 // a held-value column header
            ->assertSee('£250,000')               // a swept price row label
            ->assertSee('Download CSV')
            ->assertDontSee('safe')               // the neutral-copy guardrail
            ->assertDontSee('@endif');            // no leaked Blade directive
    }

    public function test_a_frontier_id_from_another_user_does_not_load(): void
    {
        $mine = ScenarioFixture::rich($this->user);

        $stranger = User::factory()->create();
        $theirs = app(ThresholdRunner::class)->requestFrontier(
            ScenarioFixture::rich($stranger), LeverKey::BuyPrice, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90,
            thresholdGrid: [150_000.0, 250_000.0], conditionGrid: [62.0, 70.0], paths: 30,
        )->fresh();

        Livewire::test(ThresholdExplorer::class, ['scenario' => $mine])
            ->set('frontierId', $theirs->id)
            ->assertSee('Map the trade-off')
            ->assertDontSee('Show the full map');
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
