<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\SimulationStatus;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Jobs\RunScenarioSimulation;
use App\Livewire\ScenarioResults;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

class ScenarioResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_a_preview_runs_and_renders_headline_numbers_as_text(): void
    {
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Will the money last?')
            ->assertSee('Essentials always met')
            ->assertSee('Sell & rent')
            ->assertSee('Stay put')
            ->assertSee('%');
    }

    public function test_the_fan_chart_ships_with_an_accessible_data_table(): void
    {
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Projected spendable money over time')
            ->assertSeeHtml('<table')
            ->assertSeeHtml('<caption')
            ->assertSee('Median')
            ->assertSee('Show the numbers behind this chart')
            // The end-of-life rise is explained (thin-sample tail + old-age pot compounding),
            // not left to look like a glitch.
            ->assertSee('Why the line can climb sharply at the far right')
            // Person ages label the chart tables (and the axis, client-side).
            ->assertSee('Age(s)');
    }

    public function test_a_completed_preview_persists_three_variant_results(): void
    {
        $component = Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview');

        $run = SimulationRun::findOrFail($component->get('runId'));
        $this->assertSame(SimulationStatus::Done, $run->status);
        $this->assertSame(3, $run->results()->count());
    }

    public function test_the_full_run_is_queued_then_can_be_cancelled(): void
    {
        Queue::fake();
        $component = Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()]);

        $component->call('runFull');
        Queue::assertPushed(RunScenarioSimulation::class);
        $run = SimulationRun::findOrFail($component->get('runId'));
        $this->assertSame(SimulationStatus::Queued, $run->status);

        $component->call('cancel');
        $this->assertSame(SimulationStatus::Cancelled, $run->fresh()->status);
    }

    public function test_the_results_page_shows_run_controls_before_any_run(): void
    {
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('Run a quick preview')
            ->assertSee('No completed run yet.');
    }

    public function test_the_results_page_shows_the_lump_sum_tax_shock_before_any_run(): void
    {
        // The shock is deterministic, so it renders immediately (no Monte Carlo needed).
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('The pension lump-sum tax shock')
            ->assertSee('Tax-free (25%)')
            ->assertSee('emergency (Month-1) basis');
    }

    public function test_the_results_page_shows_the_assumption_sensitivity_overlay(): void
    {
        // Also deterministic: the compare-assumptions table shows before any run.
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('How sensitive is this to the assumptions?')
            ->assertSee('DMS historical');
    }

    public function test_a_user_cannot_view_another_users_results(): void
    {
        $scenario = $this->scenario();
        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.results', $scenario))->assertForbidden();
    }

    public function test_a_completed_run_carries_the_guidance_only_disclaimer_and_mode_label(): void
    {
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Guidance only, not financial advice.')
            ->assertSee('Output mode:')
            ->assertSee('Neutral guidance');
    }

    public function test_the_csv_export_is_prefixed_with_a_disclaimer(): void
    {
        $component = Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview');

        /** @var ScenarioResults $instance */
        $instance = $component->instance();
        $response = $instance->downloadFanCsv();
        $this->assertNotNull($response);

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('guidance only, not financial advice', strtolower($csv));
        $this->assertStringContainsString('Year,P10', $csv); // the data header still follows
    }

    public function test_a_forged_run_id_cannot_load_another_users_run(): void
    {
        // A completed run that belongs to someone else.
        $other = User::factory()->create();
        $otherRun = (new SimulationRunner(new ScenarioForecaster))->preview($this->scenarioFor($other), seed: 1, paths: 20);
        $this->assertSame(SimulationStatus::Done, $otherRun->status);

        // Back as me, on my own scenario, tampering the public runId to the other's run.
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('runId', $otherRun->id)
            ->assertSee('No completed run yet.')
            ->assertDontSee('Will the money last?');
    }

    public function test_the_results_page_shows_the_cashflow_ladder_before_any_run(): void
    {
        // The ladder is the deterministic central projection, so it shows immediately.
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('Year-by-year cashflow')
            ->assertSee('Usable (excl. home)')
            ->assertSee('Total (incl. home)');
    }

    public function test_the_results_page_shows_the_spending_plan_and_income_floor_before_any_run(): void
    {
        // Both are deterministic (the budget echoes the inputs; the floor reads the central
        // projection), so they render immediately, before any Monte Carlo run.
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('Your spending plan')
            ->assertSee('£28,000.00')   // the essential line item
            ->assertSee('£12,500.00')   // the discretionary line item
            ->assertSee('Total spending')
            ->assertSee('Essential spending vs secure income')
            ->assertSee('secure income');
    }

    public function test_a_preview_shows_usable_wealth_alongside_total(): void
    {
        // Usable wealth (excl. home) must read separately from total (incl. home), so an
        // asset-rich household that runs out of cash does not look like the wealthiest.
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Usable wealth left (excl. home)')
            ->assertSee('Total wealth left (incl. home)');
    }

    public function test_the_cashflow_ladder_csv_export_is_prefixed_with_a_disclaimer(): void
    {
        /** @var ScenarioResults $instance */
        $instance = Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])->instance();
        $response = $instance->downloadLadderCsv();

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('guidance only, not financial advice', strtolower($csv));
        $this->assertStringContainsString('Usable wealth (excl. home)', $csv);
    }

    public function test_a_completed_run_shows_a_plain_english_run_out_verdict(): void
    {
        // The blunt, plain-English verdict (factual, anchored to the simulated futures) renders
        // alongside the metrics; the banned-phrasing partition test guards it stays guidance-side.
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('On these figures');
    }

    public function test_a_completed_run_still_shows_when_a_newer_run_is_cancelled(): void
    {
        // The latest run being cancelled/failed must not hide the last good result — the
        // page presents the latest *completed* run, not merely the latest run.
        Queue::fake();
        $scenario = $this->scenario();
        $runner = new SimulationRunner(new ScenarioForecaster);

        $done = $runner->preview($scenario, paths: 20);
        $this->assertSame(SimulationStatus::Done, $done->status);

        $newer = $runner->dispatch($scenario);
        $runner->cancel($newer);
        $this->assertSame(SimulationStatus::Cancelled, $newer->fresh()->status);

        Livewire::test(ScenarioResults::class, ['scenario' => $scenario->fresh()])
            ->assertSee('Will the money last?')
            ->assertDontSee('No completed run yet.');
    }

    public function test_the_include_home_toggle_flips_both_charts_between_spendable_and_total(): void
    {
        $component = Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview');

        // Default: the spendable (excl-home) basis leads on both the fan and the comparison,
        // and a fresh run carries the usable fan so no "re-run" prompt shows.
        $component
            ->assertSee('Projected spendable money over time')
            ->assertSee('Spendable money over time, by housing strategy')
            ->assertDontSee('These results were calculated before');

        // Toggle the home back in -> both charts switch to the total-wealth basis.
        $component->set('includeHome', true)
            ->assertSee('Projected total wealth over time')
            ->assertSee('Total wealth over time, by housing strategy')
            ->assertDontSee('Projected spendable money over time');
    }

    public function test_a_stale_queued_run_with_no_worker_surfaces_a_start_a_worker_hint(): void
    {
        // No worker: the job is captured but never executed, so the run stays queued at 0%.
        Queue::fake();
        $component = Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->call('runFull');

        // Freshly queued, it must NOT flash the hint immediately (a worker may be about to pick it up).
        $component->call('refreshRun')
            ->assertDontSee('Still waiting for a background worker');

        // After the grace window with no worker, the page explains why it is stuck rather than
        // sitting silently at "Queued — 0%".
        $this->travel(20)->seconds();
        $component->call('refreshRun')
            ->assertSee('Still waiting for a background worker')
            ->assertSee('php artisan queue:work');
    }

    public function test_the_results_page_shows_the_assumptions_panel_and_sale_explainer_before_any_run(): void
    {
        // The show-your-working layer is deterministic, so it renders immediately. The rich
        // fixture configures a sale (£525k) and a cheaper home (£320k), so both the proceeds
        // waterfall and the buy destination appear.
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('The assumptions behind these figures')
            ->assertSee('Investment growth (blended, real)')
            ->assertSee('Investment income yield (nominal)')
            ->assertSee('If you sell: where the money comes from and goes')
            ->assertSee('Net proceeds')
            ->assertSee('If you sell & buy cheaper')
            ->assertSee('Surplus invested')
            // The ladder spend is itemised into its essential floor and discretionary remainder.
            ->assertSee('split into its essential floor and discretionary remainder');
    }

    public function test_the_cashflow_ladder_csv_carries_the_essential_and_discretionary_split(): void
    {
        /** @var ScenarioResults $instance */
        $instance = Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])->instance();
        $response = $instance->downloadLadderCsv();

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('Essential spend', $csv);
        $this->assertStringContainsString('Discretionary spend', $csv);
    }

    private function scenario(): Scenario
    {
        return $this->scenarioFor($this->user);
    }

    private function scenarioFor(User $user): Scenario
    {
        return ScenarioFixture::rich($user);
    }
}
