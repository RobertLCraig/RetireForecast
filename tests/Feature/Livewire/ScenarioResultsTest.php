<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Assistant\ScenarioContext;
use App\DecisionSupport\CapacityForLoss;
use App\Enums\ScenarioStatus;
use App\Enums\SimulationStatus;
use App\Export\ScenarioReport;
use App\Forecast\ResultPresenter;
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
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;
use Tests\Support\BuilderStateFixture;
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
        // Neutral single-strategy report. Pin the public posture (the suite default is now advice
        // mode — DECISIONS 2026-07-04), where the advice readouts reference the other strategies.
        config()->set('compliance.personal_use', false);

        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Will the money last?')
            ->assertSee('Essentials always met')
            // Single-strategy report: only the scenario's own strategy is shown (others are
            // separate what-ifs); the cross-strategy comparison lives on the Compare page now.
            ->assertSee('Sell & rent')
            ->assertDontSee('Stay put')
            ->assertSee('%');
    }

    public function test_the_contingent_income_panel_and_claim_prompt_render_on_the_results_page(): void
    {
        // Board card 0046, the WIRING half: a Blade directive can fail to compile silently, so the
        // two new panels are proved to reach a rendered page. Which side of the floor the credit
        // falls on is settled by IncomeFloorTest, not here: this fixture renders both tables, so
        // seeing the label proves nothing about where it is counted.
        $scenario = ScenarioFixture::fromState($this->user, BuilderStateFixture::minimalValid());

        Livewire::test(ScenarioResults::class, ['scenario' => $scenario])
            ->assertSee('Income the forecast counts, but nobody guarantees')
            ->assertSee('Pension Credit')
            ->assertSee('How to claim Pension Credit')
            ->assertSee('backdated up to 3 months');
    }

    public function test_every_reader_of_the_floor_nets_pension_credit_off_the_savings_draw(): void
    {
        // Board card 0046 #1, the review's finding. Pension Credit left the secure floor, but the
        // screen, the PDF and the assistant still told the household that the whole shortfall below
        // secure income came from savings, overstating the draw by the award. And the survivor's
        // credit, the larger one, was in no table and no total. A couple on small State Pensions,
        // so both the both-alive year and the survivor's year are topped up by the credit and still
        // draw on savings for the rest.
        $state = BuilderStateFixture::minimalValid();
        $state['people'][] = ['id' => 'p2', 'dob' => '1955-01-01', 'sex' => 'male', 'employmentStatus' => 'retired',
            'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => ''];
        $state['pensions'] = [
            ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '120', 'qualifyingYears' => '', 'deferralWeeks' => '0', 'fixedEscalationRate' => '', 'crystallisedValue' => ''],
            ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '120', 'qualifyingYears' => '', 'deferralWeeks' => '0', 'fixedEscalationRate' => '', 'crystallisedValue' => ''],
        ];
        $state['expenseLines'][0]['amount'] = '25000';
        $scenario = ScenarioFixture::fromState($this->user, $state);
        $floor = ResultPresenter::incomeFloor(app(ScenarioForecaster::class)->deterministic($scenario));

        $this->assertNotNull($floor);
        $this->assertNotNull($floor['gap'], 'precondition: secure income leaves essentials uncovered');
        $this->assertNotSame([], $floor['contingent'], 'precondition: the forecast awards Pension Credit');
        $this->assertNotSame($floor['gap'], $floor['fromSavings']);

        $screen = Livewire::test(ScenarioResults::class, ['scenario' => $scenario])->html();
        $pdf = view('pdf.results', ['reports' => [(new ScenarioReport)->data($scenario)]])->render();
        $assistant = ScenarioContext::for($scenario, app(ScenarioForecaster::class))->promptBlock();

        // The figure under the "Met from savings / pension" label, read off the tile itself: the
        // pre-credit gap can equal a figure printed elsewhere (here it is the essentials), so a
        // page-wide search for it proves nothing.
        $tile = static function (string $html): ?string {
            preg_match('~Met from savings / pension</p>\s*<p[^>]*>\s*([^<]+?)\s*</p>~', $html, $m);

            return $m[1] ?? null;
        };
        $this->assertSame(e($floor['fromSavings']), $tile($screen), 'screen: the savings tile nets off the credit');
        $this->assertSame(e($floor['fromSavings']), $tile($pdf), 'pdf: the savings tile nets off the credit');
        $this->assertStringContainsString("{$floor['fromSavings']} from savings and investments", $assistant);
        $this->assertStringNotContainsString("remaining {$floor['gap']} must come from savings", $assistant);

        foreach (['screen' => $screen, 'pdf' => $pdf, 'assistant' => $assistant] as $where => $text) {
            $this->assertStringContainsString(e($floor['contingentIncome']), $text, "{$where}: the credit itself");
        }

        $this->assertNotNull($floor['survivor'], 'precondition: a couple with a survivor phase');
        $this->assertNotSame([], $floor['survivor']['contingent'], 'precondition: the survivor is awarded Pension Credit');
        foreach (['screen' => $screen, 'pdf' => $pdf, 'assistant' => $assistant] as $where => $text) {
            $this->assertStringContainsString(e($floor['survivor']['contingentIncome']), $text, "{$where}: the survivor's credit");
        }
    }

    public function test_the_results_page_shows_the_withdrawal_sequencing_panel(): void
    {
        // The deterministic "how you draw your money" panel renders without a completed run:
        // the current draw order's lifetime tax vs filling the tax-free bands first.
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->assertSee('How you draw your money down')
            ->assertSee('Filling your tax-free allowances first')
            ->assertSee('tax paid across the plan');
    }

    public function test_care_not_modelled_shows_a_heads_up_that_disappears_when_care_is_on(): void
    {
        // Care off (the default): a note flags the omitted ~1-in-4 six-figure risk, so it is not
        // silently left out of "will the money last?".
        Livewire::test(ScenarioResults::class, ['scenario' => ScenarioFixture::rich($this->user)])
            ->assertSee('Later-life care')
            ->assertSee('1 in 4');

        // Care on: the heads-up is gone (the care panel takes over once a run models it).
        Livewire::test(ScenarioResults::class, ['scenario' => ScenarioFixture::rich($this->user, ['modelCareCost' => true])])
            ->assertDontSee('Later-life care');
    }

    public function test_the_iht_panel_shows_only_when_the_toggle_is_on(): void
    {
        // Deterministic, so it renders without a completed run — like the withdrawal panel.
        Livewire::test(ScenarioResults::class, ['scenario' => ScenarioFixture::rich($this->user, ['ihtModelled' => true])])
            ->assertSee('Inheritance tax on your estate')
            ->assertSee('married or in a civil partnership');

        // With the toggle off (the default), the estate section is absent — proving the toggle drives it.
        Livewire::test(ScenarioResults::class, ['scenario' => ScenarioFixture::rich($this->user, ['ihtModelled' => false])])
            ->assertDontSee('Inheritance tax on your estate');
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
            ->assertSee('No completed run yet.')
            // A base offers the one-click what-ifs.
            ->assertSee('Retire 2 years later')
            ->assertSee('Live 10 years longer');
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

    public function test_the_results_page_shows_the_historical_stress_test_before_any_run(): void
    {
        // The backtest is deterministic, so it renders immediately (no Monte Carlo needed). The
        // private build: a public one withholds it (card 0012).
        config()->set('compliance.personal_use', true);
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('Stress test: how it would have handled past crises')
            ->assertSee('Historical starts survived')
            ->assertSee('Oil crisis & UK crash (1973–74)')
            ->assertSee('Rate of Return on Everything');
    }

    /**
     * The stress test replays the Jorda-Schularick-Taylor dataset, which is CC BY-NC-SA and cannot
     * ship publicly (card 0012). A public build withholds the panel on screen and in the PDF and
     * says so in its place; the private build keeps both.
     */
    public function test_a_public_build_withholds_the_historical_stress_test_on_screen_and_in_the_pdf(): void
    {
        config()->set('compliance.personal_use', false);
        $scenario = $this->scenario();
        $withheld = 'Historical stress testing is not available in this build.';

        $this->get(route('scenarios.results', $scenario))
            ->assertOk()
            ->assertSee($withheld)
            ->assertDontSee('Historical starts survived')
            ->assertDontSee('Rate of Return on Everything');

        $pdf = view('pdf.results', ['reports' => [(new ScenarioReport)->data($scenario)]])->render();
        $this->assertStringContainsString($withheld, $pdf);
        $this->assertStringNotContainsString('Historical starts survived', $pdf);
        $this->assertStringNotContainsString('Rate of Return on Everything', $pdf);
    }

    public function test_a_private_build_shows_the_historical_stress_test_on_screen_and_in_the_pdf(): void
    {
        config()->set('compliance.personal_use', true);
        $scenario = $this->scenario();
        $withheld = 'Historical stress testing is not available in this build.';

        $this->get(route('scenarios.results', $scenario))
            ->assertOk()
            ->assertSee('Historical starts survived')
            ->assertSee('Rate of Return on Everything')
            ->assertDontSee($withheld);

        $pdf = view('pdf.results', ['reports' => [(new ScenarioReport)->data($scenario)]])->render();
        $this->assertStringContainsString('Historical starts survived', $pdf);
        $this->assertStringContainsString('Rate of Return on Everything', $pdf);
        $this->assertStringNotContainsString($withheld, $pdf);
    }

    public function test_the_care_cost_panel_shows_after_a_run_when_care_is_modelled(): void
    {
        // A scenario with the care-risk toggle on: a completed run reports the care impact,
        // which the results page surfaces as its own panel.
        $scenario = ScenarioFixture::rich($this->user, ['modelCareCost' => true]);

        Livewire::test(ScenarioResults::class, ['scenario' => $scenario])
            ->set('previewPaths', 40)
            ->call('preview')
            ->assertSee('The risk of late-life care costs')
            ->assertSee('Chance care costs you something');
    }

    /**
     * Capacity for loss states BOTH figures the card asks for (a percentage fall and the cash it
     * amounts to) beside the total wealth they are measured against, so a reader can check the
     * arithmetic rather than take the percentage on trust. Deterministic, so it needs no run.
     */
    public function test_the_results_page_states_how_far_wealth_could_fall(): void
    {
        $scenario = $this->scenario();
        $capacity = app(CapacityForLoss::class)->forScenario($scenario);
        $this->assertFalse($capacity['alreadyBreached'], 'the rich fixture should have some room to lose');
        $this->assertFalse($capacity['survivesTotalLoss']);

        $this->get(route('scenarios.results', $scenario))
            ->assertOk()
            ->assertSee('How much could you afford to lose?')
            ->assertSee('The most your wealth could fall')
            ->assertSee($capacity['percent'].'%')
            ->assertSee($capacity['cash']->format())
            ->assertSee($capacity['wealth']->format());
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
        // The neutral "mode label" is posture-dependent; pin the public guidance-only posture
        // (the suite default is now personal-use advice mode — DECISIONS 2026-07-04).
        config()->set('compliance.personal_use', false);

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

    public function test_the_results_page_has_an_on_this_page_side_nav(): void
    {
        // The page is tabbed, so this nav jumps WITHIN the tab on display (the tab bar moves
        // between tabs). It lists only sections actually present in that tab, and its links are
        // real anchors (work without JS); a bundled observer highlights on scroll.
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('On this page')
            ->assertSeeHtml('data-results-toc')
            ->assertSeeHtml('href="#sec-shock"')      // the verdict tab, which is on display
            ->assertSeeHtml('href="#sec-how-far"')
            ->assertDontSeeHtml('href="#sec-ladder"'); // another tab's section, not this nav's job
    }

    public function test_the_results_page_is_grouped_into_tabs(): void
    {
        // B2: four groups, so the first screen is the answer rather than eighteen stacked
        // sections. Each section declares the tab it belongs to, and the ones outside the
        // active tab are rendered but hidden.
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSeeHtml('data-results-tabs')
            ->assertSee('The verdict')
            ->assertSee('Money over time')
            ->assertSee('Where the money goes')
            ->assertSee('The fine print')
            ->assertSeeHtml('id="sec-shock" data-tab="verdict"')
            ->assertSeeHtml('id="sec-ladder" data-tab="money" hidden');
    }

    public function test_every_figure_stays_reachable_without_javascript(): void
    {
        // The tabs are plain links the server resolves (?tab=…), not a JavaScript widget, and a
        // section outside the active tab is rendered anyway — so its accessible table and CSV
        // twin never leave the page. Both halves of that promise are asserted here.
        $scenario = $this->scenario();

        $this->get(route('scenarios.results', $scenario))
            ->assertOk()
            ->assertSeeHtml('href="'.e(route('scenarios.results', ['scenario' => $scenario, 'tab' => 'money'])).'"')
            ->assertSee('Year-by-year cashflow')      // in the page even while another tab shows
            ->assertSee('Usable (excl. home)');

        // Following that link renders the same section visible, with no JavaScript involved.
        $this->get(route('scenarios.results', ['scenario' => $scenario, 'tab' => 'money']))
            ->assertOk()
            ->assertSeeHtml('id="sec-ladder" data-tab="money" aria-labelledby')
            ->assertSeeHtml('id="sec-shock" data-tab="verdict" hidden');
    }

    public function test_an_unknown_tab_falls_back_to_the_verdict(): void
    {
        // A bad ?tab= would otherwise hide every panel and leave a blank page.
        $this->get(route('scenarios.results', ['scenario' => $this->scenario(), 'tab' => 'nonsense']))
            ->assertOk()
            ->assertSeeHtml('id="sec-shock" data-tab="verdict" aria-labelledby');
    }

    public function test_the_advisory_banners_sit_below_the_verdict(): void
    {
        // B4: the banners used to render above the first figure. The reader came for the
        // answer, so the answer comes first — the care heads-up now follows the verdict and
        // the outlook chart instead of preceding them.
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSeeInOrder([
                'Will the money last?',
                'Projected',
                'Later-life care',
            ]);

        // The housekeeping banners are demoted further still, out of the verdict entirely.
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSeeHtml('data-tab="detail" hidden class="rounded-lg border border-blue-200');
    }

    public function test_detail_tables_are_behind_a_disclosure(): void
    {
        // B3: the long raw tables render inside <details> so the page skims. <details> is
        // native HTML, so the figures are still there with JavaScript off. The private build, which
        // keeps the stress test's crisis table (card 0012).
        config()->set('compliance.personal_use', true);
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('Show the year-by-year numbers')
            ->assertSee('Show each income source')
            ->assertSee('Show how the sale price becomes net proceeds')
            ->assertSee('Show each crisis it was started into');
    }

    public function test_the_results_page_shows_the_cashflow_ladder_before_any_run(): void
    {
        // The ladder is the deterministic central projection, so it shows immediately.
        $this->get(route('scenarios.results', $this->scenario()))
            ->assertOk()
            ->assertSee('Year-by-year cashflow')
            ->assertSee('Usable (excl. home)')
            ->assertSee('Total (incl. home equity)');
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
        // Usable wealth (excl. home) must read separately from total (incl. home equity), so
        // an asset-rich household that runs out of cash does not look like the wealthiest.
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->set('previewPaths', 30)
            ->call('preview')
            ->assertSee('Usable wealth left (excl. home)')
            ->assertSee('Total wealth left (incl. home equity)');
    }

    public function test_the_cashflow_ladder_shows_the_scenarios_own_strategy_and_home_sale(): void
    {
        // Single-strategy report: the ladder opens on the scenario's own (sell-&-rent) strategy,
        // labelled as such — a sell strategy, so the home-sale milestone shows. There is no
        // in-report strategy switcher anymore (strategies are separate what-ifs, on Compare).
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->assertSee('Year-by-year cashflow')
            ->assertSee('Sell & rent')
            ->assertSee('The home is sold')
            ->assertDontSee('The year-by-year picture, by housing strategy');
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

        // Default: the spendable (excl-home) basis leads the fan chart, and a fresh run carries
        // the usable fan so no "re-run" prompt shows. (The cross-strategy comparison chart is on
        // the Compare page now, not in this single-strategy report.)
        $component
            ->assertSee('Projected spendable money over time')
            ->assertDontSee('These results were calculated before');

        // Toggle the home back in -> the fan chart switches to the total-wealth basis.
        $component->set('includeHome', true)
            ->assertSee('Projected total wealth over time')
            ->assertDontSee('Projected spendable money over time');
    }

    public function test_the_nominal_pounds_toggle_shows_the_engines_own_pre_deflation_figures(): void
    {
        // Card 0013. The figure the box must bring onto the page is the engine's own nominal
        // twin of the last projected year, not anything the presenter computes. Nothing else on
        // the page is nominal, so the figure is absent until the box is ticked.
        $scenario = $this->scenario();
        $years = app(ScenarioForecaster::class)->deterministicVariants($scenario)[$scenario->variant->value]->years;
        $last = $years[count($years) - 1];
        $nominal = $last->nominal->totalWealth->format();
        $this->assertNotSame($last->totalWealth->format(), $nominal, 'the fixture must inflate, or real and nominal coincide');

        Livewire::test(ScenarioResults::class, ['scenario' => $scenario])
            ->assertSee('Wealth by type (real pounds)')
            ->assertDontSee($nominal)
            ->set('nominalPounds', true)
            ->assertSee('Wealth by type (cash pounds)')
            ->assertSee($nominal);
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
            ->assertSee('If you sell & buy')
            ->assertSee('Surplus invested')
            // Life-event milestones (when retire / SP / death happen) make the cashflow legible.
            ->assertSee('When the big events happen')
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

    public function test_a_what_if_highlights_what_it_changed_from_its_base(): void
    {
        $base = $this->scenario();
        $child = new Scenario;
        $child->user_id = $this->user->id;
        $child->parent_scenario_id = $base->id;
        $child->overrides = ['name' => 'Higher essentials', 'expenseLines.ess1.amount' => '31000'];
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        Livewire::test(ScenarioResults::class, ['scenario' => $child])
            ->assertSee('A what-if of')
            ->assertSee('Buy-vs-rent')              // the base name
            ->assertSee('What this what-if changes')
            ->assertSee('Essentials · amount')      // the humanised changed input
            ->assertSee('£28,000')                  // base value
            ->assertSee('£31,000')                  // new value
            // The quick-what-if launcher is a base-only affordance, not shown on a what-if.
            ->assertDontSee('Quick what-if:');
    }

    public function test_the_explore_levers_save_a_what_if_from_the_adjustments(): void
    {
        // The levers no longer run a throwaway live preview: they build a proper, saved what-if
        // (a delta-child of the base), so a lever change is always a real, comparable scenario.
        $base = $this->scenario();
        $component = Livewire::test(ScenarioResults::class, ['scenario' => $base]);

        // No adjustment: "create" saves nothing (no empty what-if).
        $component->call('makeWhatIf');
        $this->assertSame(0, $base->children()->count());

        // Set a lever, then save it → a delta-child of the base is created from the adjustment.
        $component->set('slideSpend', 30)->call('makeWhatIf');
        $child = $base->children()->latest()->first();
        $this->assertNotNull($child);
        $this->assertSame($base->id, $child->parent_scenario_id);
        $this->assertStringContainsString('Spend +30%', $child->overrides['name']);
    }

    /**
     * Board card 0033, criterion 4. The spend slider scaled a line's `amount` and left the
     * `utilities` inside it fixed, so sliding spend down made more and more of a service charge
     * read as utilities and a sell plan kept a charge it should have dropped.
     */
    public function test_the_spend_slider_scales_the_utilities_inside_a_line_with_its_amount(): void
    {
        $base = ScenarioFixture::rich($this->user, [
            'expenseLines' => [
                ['id' => 'ess1', 'label' => 'Essentials', 'amount' => '28000', 'category' => 'essential', 'savedAsAsset' => false],
                ['id' => 'sc1', 'label' => 'Service charge', 'amount' => '4000', 'utilities' => '1500', 'category' => 'essential',
                    'condition' => 'while_owning_home', 'savedAsAsset' => false],
            ],
        ]);

        Livewire::test(ScenarioResults::class, ['scenario' => $base])
            ->set('slideSpend', -50)->call('makeWhatIf');

        $line = collect($base->children()->latest()->first()->effectiveBuilderState()['expenseLines'])->firstWhere('id', 'sc1');
        $this->assertEquals(2000, $line['amount']);
        $this->assertEquals(750, $line['utilities']);
    }

    public function test_the_income_floor_shows_the_survivor_cliff_for_a_couple(): void
    {
        // The rich fixture is a couple, so the income-floor section carries the survivor-year twin
        // (Phase 4): the before/after cliff renders on the deterministic section, pre-run.
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->assertSee('Essential spending vs secure income')
            ->assertSee('What happens to this floor at the first death')
            ->assertSee('After the first death')
            ->assertDontSee('@endif'); // no leaked Blade directive
    }

    /**
     * Board card 0061, criterion 3. The results page took its central figures from a coin-flip
     * lifespan and said "one life" beside them. Whatever horizon is in force, the odds it leaves
     * have to reach the screen.
     */
    public function test_the_results_page_states_the_odds_behind_its_single_path(): void
    {
        Livewire::test(ScenarioResults::class, ['scenario' => $this->scenario()])
            ->assertSee(PlanningHorizon::DEFAULT->oddsPhrase());
    }

    /**
     * Board card 0061, criterion 3. The advice-cost block said "the money would still last for
     * life" about the same single path, run to a median lifespan when the lever is at the 50th.
     */
    public function test_the_advice_cost_block_never_says_a_median_path_lasts_for_life(): void
    {
        $scenario = ScenarioFixture::rich($this->user, [
            'assumptionOverrides' => ['planningHorizon' => 'p50'],
        ]);

        Livewire::test(ScenarioResults::class, ['scenario' => $scenario])
            ->assertViewHas('adviceCost', fn (?array $a): bool => $a !== null
                && $a['diy']['depletionYear'] === null && $a['advised']['depletionYear'] === null)
            ->assertDontSee('last for life')
            ->assertSee(PlanningHorizon::P50->oddsPhrase());
    }

    /**
     * Board card 0089. A buy plan whose price dwarfs the sale proceeds and the savings, with no
     * buy mortgage, so the engine charges an unfunded purchase gap on the buy path and nowhere
     * else.
     */
    private function unfundedBuyScenario(): Scenario
    {
        return ScenarioFixture::rich($this->user, [
            'variant' => 'buy_outright',
            'housing' => array_replace(BuilderStateFixture::full()['housing'], ['buyPrice' => '5000000']),
        ]);
    }

    /**
     * @param  list<array{kind: string, text: string}>  $notes
     * @return list<array{kind: string, text: string}>
     */
    private static function ofKinds(array $notes, array $kinds): array
    {
        return array_values(array_filter($notes, static fn (array $n): bool => in_array($n['kind'], $kinds, true)));
    }

    public function test_an_unfunded_purchase_note_reaches_the_results_page(): void
    {
        // Card 0025's headline: the note naming the cost of an unfunded purchase. The notes were
        // read off the stay-put forecast, which charges no purchase, so it could never appear.
        Livewire::test(ScenarioResults::class, ['scenario' => $this->unfundedBuyScenario()])
            ->assertViewHas('inputNotes', fn (array $notes): bool => self::ofKinds($notes, ['unfunded_one_off']) !== []);
    }

    public function test_forecast_derived_disclosures_read_the_selected_variant(): void
    {
        $scenario = $this->unfundedBuyScenario();
        $forecaster = app(ScenarioForecaster::class);
        $variants = $forecaster->deterministicVariants($scenario);
        $household = $scenario->toHousehold();
        $action = ResultPresenter::housingActionFor($scenario->toHousingAction(), 'buy_outright');
        $set = $forecaster->assumptions($scenario);
        $settings = $forecaster->settings($scenario);
        $derived = ['assumed_figure', 'unfunded_one_off'];

        // What the buy plan's OWN forecast discloses, and what the stay-put one would. The two
        // must differ, or this test could pass whichever forecast the page read.
        $own = self::ofKinds(ResultPresenter::inputNotes($household, $variants['buy_outright'], $action, 'buy_outright', $set, $settings), $derived);
        $stayPut = self::ofKinds(ResultPresenter::inputNotes($household, $variants['stay_put'], $action, 'buy_outright', $set, $settings), $derived);
        $this->assertNotSame($stayPut, $own, 'the fixture must make the two forecasts disclose differently');

        // Screen and print both show the plan on display.
        Livewire::test(ScenarioResults::class, ['scenario' => $scenario])
            ->assertViewHas('inputNotes', fn (array $notes): bool => self::ofKinds($notes, $derived) === $own);
        $this->assertSame($own, self::ofKinds((new ScenarioReport)->data($scenario)['inputNotes'], $derived));
    }

    public function test_input_sanity_notes_do_not_change_with_the_selected_strategy(): void
    {
        // An earner with no retirement age (a sanity note on the household as entered). The same
        // household shown on stay put and on a sell plan must carry the same sanity notes.
        $scenario = $this->unfundedBuyScenario();
        $forecaster = app(ScenarioForecaster::class);
        $variants = $forecaster->deterministicVariants($scenario);
        $household = $scenario->toHousehold();
        $sanity = ['no_salary', 'no_retirement_age', 'early_death'];

        $asEntered = self::ofKinds(ResultPresenter::inputNotes($household, $variants['stay_put']), $sanity);

        // A shown plan whose every member dies in the base year. It is a statement about the
        // plan, not about the household as entered, so no early-death note may follow from it.
        $stay = $variants['stay_put'];
        $buy = $variants['buy_outright'];
        $shown = new ForecastResult(
            years: $buy->years,
            essentialsAlwaysMet: $buy->essentialsAlwaysMet,
            fullSpendAlwaysMet: $buy->fullSpendAlwaysMet,
            depletionCalendarYear: $buy->depletionCalendarYear,
            terminalTotalWealth: $buy->terminalTotalWealth,
            terminalUsableWealth: $buy->terminalUsableWealth,
            finalCalendarYear: $buy->finalCalendarYear,
            deathCalendarYears: array_map(static fn (): int => $stay->years[0]->calendarYear, $stay->deathCalendarYears),
        );

        $this->assertSame($asEntered, self::ofKinds(
            ResultPresenter::inputNotes($household, $stay, null, 'buy_outright', null, null, $shown),
            $sanity,
        ));
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
