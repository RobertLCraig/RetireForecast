<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Enums\ScenarioStatus;
use App\Export\ChartSvg;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Http\Controllers\ScenarioPdfController;
use App\Livewire\ScenarioResults;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The downloadable PDF results summary. The route streams a real PDF; the view-render
 * tests assert the figures + the guidance-only disclaimer are present, built from the
 * same ResultPresenter the on-screen page uses (so the print cannot drift).
 *
 * The load-bearing guard is {@see test_the_pdf_carries_every_section_the_results_page_shows}:
 * it derives the screen's section list from the Livewire component itself rather than
 * restating it, so a NEW results-page section that nobody adds to the export fails here
 * instead of silently going missing from the report a user shares.
 */
class ScenarioPdfTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Data the results page needs only to drive its interactive controls — there are no
     * figures behind them to print, so the PDF legitimately omits them:
     *   run / resultsRun   the live run status, progress bar and cancel button
     *   canMakeWhatIf      whether to offer the lever sliders
     *   sliderSummary      the unsaved lever positions ("retire +2 yr")
     *   ladderStrategies   the ladder's strategy picker (the PDF prints the selected one)
     *   ladderSelected     the picker's current key (printed as ladderSelectedLabel)
     *   whatsNew           transient "new in this build" review markers
     *   tabs               the on-screen tab bar; paper has no tabs, the PDF prints one flow
     *   layoutConfig       Livewire's own #[Layout] plumbing, not a section at all
     * Anything else the page renders must reach the export.
     */
    private const INTERACTIVE_ONLY = [
        'run', 'resultsRun', 'canMakeWhatIf', 'sliderSummary',
        'ladderStrategies', 'ladderSelected', 'whatsNew', 'tabs', 'layoutConfig',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_the_owner_can_download_a_pdf_summary(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        $response = $this->get(route('scenarios.results.pdf', $scenario));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_pdf_is_owner_scoped(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.results.pdf', $scenario))->assertForbidden();
    }

    public function test_a_draft_scenario_has_no_pdf(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        $scenario->update(['status' => ScenarioStatus::Draft]);

        $this->get(route('scenarios.results.pdf', $scenario))->assertNotFound();
    }

    /**
     * Completeness: every variable the results page renders reaches the export, bar the
     * interactive-only ones listed above. Derived from the component, so adding a section
     * to the screen and forgetting the report is a failing test, not a silent omission.
     */
    public function test_the_pdf_carries_every_section_the_results_page_shows(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        (new SimulationRunner(new ScenarioForecaster))->preview($scenario, paths: 20);

        $screenKeys = array_keys($this->screenData($scenario));
        $exported = array_keys(app(ScenarioPdfController::class)->data($scenario));

        $missing = array_diff($screenKeys, $exported, self::INTERACTIVE_ONLY);

        $this->assertSame([], array_values($missing), 'The results page renders data the PDF export drops: '
            .implode(', ', $missing).'. Add it to ScenarioPdfController::data() and print it in pdf/partials/report.blade.php.');
    }

    /** Each of those sections has to actually appear in the rendered report, not merely be passed to it. */
    public function test_the_report_renders_every_section_heading(): void
    {
        $scenario = ScenarioFixture::rich($this->user, ['ihtModelled' => true]);
        (new SimulationRunner(new ScenarioForecaster))->preview($scenario, paths: 20);

        $html = $this->renderReport($scenario);

        foreach ([
            'Guidance only, not financial advice',
            'Will the money last?',
            'How long the money may need to last',
            'Projected',                                        // the fan chart section
            'The pension lump-sum tax shock',
            'How sensitive is this to the assumptions?',
            'Your spending plan',
            'PLSA Retirement Living Standards',
            'Essential spending vs secure income',
            'How much could you afford to lose?',
            'Inheritance tax on your estate',
            'How you draw your money down',
            'Stress test: how it would have handled past crises',
            'The assumptions behind these figures',
            'If you sell: where the money comes from and goes',
            'When the big events happen',
            'Money over time',
            'Where your income comes from',
            'Where your wealth is',
            'What you spend, and how it changes',
            'Year-by-year cashflow',
            'Check these figures',
        ] as $heading) {
            $this->assertStringContainsString($heading, $html, "The PDF report is missing the '{$heading}' section.");
        }
    }

    /**
     * The charts print. dompdf executes no JavaScript, so they are re-drawn server-side as SVG
     * — but from the SAME option blob the screen chart is initialised with, which is what the
     * geometry assertion below pins down.
     */
    public function test_the_report_draws_every_chart_as_embedded_svg(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        (new SimulationRunner(new ScenarioForecaster))->preview($scenario, paths: 20);

        $html = $this->renderReport($scenario);

        $this->assertSame(5, substr_count($html, 'src="data:image/svg+xml;base64,'),
            'The report should embed five charts: BOTH Monte Carlo fan bases plus the income, wealth and cost time series.');
    }

    /**
     * The fan's wealth basis is a live checkbox on screen ("Include home value") and paper
     * cannot be toggled, so the report prints both bases — otherwise one of the two views the
     * reader can see on screen is silently missing from what they share.
     */
    public function test_the_report_prints_both_fan_bases_not_just_the_default(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        (new SimulationRunner(new ScenarioForecaster))->preview($scenario, paths: 20);

        $report = app(ScenarioPdfController::class)->data($scenario);

        $this->assertTrue($report['presented']['fan']['usableBasis'], 'The first fan should be the spendable (excl-home) basis.');
        $this->assertFalse($report['presentedTotal']['fan']['usableBasis'], 'The second fan should be the total-wealth (incl-home) basis.');
        $this->assertNotSame($report['fanChart'], $report['fanChartTotal'], 'The two fans must be different charts, not the same one twice.');

        $html = $this->renderReport($scenario);
        $this->assertStringContainsString('Projected spendable money over time', $html);
        $this->assertStringContainsString('Projected total wealth over time', $html);
    }

    /**
     * A printed chart plots the presenter's own numbers. The wealth chart's three legs sum to
     * net worth, so the top of its stack must be the largest net-worth figure in the table twin
     * — assert the drawn area actually reaches the axis maximum that figure implies, rather than
     * only that an SVG was produced.
     */
    public function test_a_printed_chart_plots_the_same_series_the_screen_chart_does(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        $report = app(ScenarioPdfController::class)->data($scenario);

        $svg = ChartSvg::render($report['timeSeries']['wealth']['options']);

        // One filled band per wealth leg (pensions / savings / home equity), in the presenter's
        // own colours — a dropped leg would silently understate net worth.
        foreach ($report['timeSeries']['wealth']['options']['colors'] as $colour) {
            $this->assertStringContainsString('fill="'.$colour.'"', $svg,
                "The wealth chart is missing the {$colour} band the screen chart draws.");
        }

        // The y axis has to reach the tallest stack: the largest net worth in the table twin.
        $peak = 0;
        foreach ($report['timeSeries']['wealth']['rows'] as $row) {
            $peak = max($peak, (int) preg_replace('/[^0-9]/', '', explode('.', $row['total'])[0]));
        }
        $this->assertGreaterThan(0, $peak);
        preg_match_all('/>£([\d.]+)([km]?)</u', $svg, $labels, PREG_SET_ORDER);
        $axisTop = 0.0;
        foreach ($labels as [, $value, $unit]) {
            $axisTop = max($axisTop, (float) $value * ['' => 1, 'k' => 1_000, 'm' => 1_000_000][$unit]);
        }
        $this->assertGreaterThanOrEqual($peak, $axisTop,
            'The chart axis stops below the largest figure in its own table, so the top of the stack is cut off.');
    }

    /**
     * The ladder is printed on the SAME housing strategy the screen selects. Both resolve it
     * through App\Forecast\LadderContext, so a scenario whose stored variant the inputs do not
     * configure (here: sell & rent with no sale price) is clamped to stay put on both.
     */
    public function test_the_printed_ladder_uses_the_same_strategy_the_screen_selects(): void
    {
        // The fixture's variant is "rent"; strip the sale so renting is not an offered strategy.
        $scenario = ScenarioFixture::rich($this->user, ['housing' => ['salePrice' => '', 'buyPrice' => '']]);

        $report = app(ScenarioPdfController::class)->data($scenario);
        $screen = $this->screenData($scenario);

        $this->assertSame($screen['ladderSelectedLabel'], $report['ladderSelectedLabel']);
        $this->assertSame($screen['ladder']['rows'], $report['ladder']['rows']);
    }

    /**
     * The widest table is not clipped off the page edge. dompdf does not wrap or shrink a table
     * that is too wide — it runs off the paper and the overflow is silently cut, which is how the
     * ladder's final "Total (incl. home equity)" column first shipped truncated to "£225,5". That
     * is a figure the reader cannot see, so it is guarded end to end against the real rendered
     * PDF rather than against the HTML, which looks fine either way.
     */
    public function test_nothing_is_clipped_off_the_page_edge(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        // The A4 landscape media box. dompdf line-breaks running prose to fit its container, so
        // only an UNBREAKABLE token — a "£1,182,147.40" in a table cell — can actually hang over
        // the edge; that is what the width test is applied to. A run that merely *starts* past
        // the page is off the paper whatever it contains.
        $pageWidth = 841.89;

        $overflowing = array_values(array_filter(
            $this->paintedTextRuns($scenario),
            fn (array $run): bool => $run['x'] > $pageWidth
                || (! str_contains(trim($run['text']), ' ') && $run['right'] > $pageWidth),
        ));

        $this->assertSame([], $overflowing, 'Content runs off the right-hand edge of the page, where it is '
            .'cut off and unreadable: '.implode(', ', array_map(
                fn (array $r): string => '"'.trim($r['text']).'" at '.round($r['x']).'pt',
                array_slice($overflowing, 0, 6),
            )));
    }

    /**
     * A plan that does not sell is given no sale mechanics. A BASE scenario carries a sale
     * price and buy price so the Compare page can run all three variants, so "is a sale
     * configured?" is the wrong question — the report must ask whether the strategy it is
     * printing actually sells. A stay-put plan was getting a page of "if you sell" waterfall,
     * the sale-cost assumptions and CGT signposting for a disposal it never makes.
     */
    public function test_a_stay_put_plan_is_given_no_sale_information(): void
    {
        // Sale and buy prices ARE configured (as on a real base scenario) — only the chosen
        // strategy differs.
        $scenario = ScenarioFixture::rich($this->user, ['variant' => 'stay_put']);

        $report = app(ScenarioPdfController::class)->data($scenario);
        $html = $this->renderReport($scenario);

        $this->assertFalse($report['salePlanned']);
        $this->assertNull($report['saleExplainer'], 'A stay-put plan should not be handed a sale waterfall.');
        $this->assertFalse($report['sourcesShowCgt'], 'There is no disposal, so there is no capital gains tax to signpost.');
        $this->assertStringNotContainsString('If you sell', $html);
        $this->assertStringNotContainsString('Housing-decision inputs', $html);
    }

    /**
     * A plan that does not BUY is not disclosed a bought home's assumed costs. The assumed-figure
     * notes exist to surface defaults the engine supplies for itself — but a base scenario carries
     * a buy price so Compare can run every variant, so keying the disclosure off "was a buy price
     * entered?" put a bought home's assumed 1%-of-value upkeep on a stay-put plan that never buys
     * it. Disclosing a figure the projection does not charge is the inverse of the rule: it makes
     * the reader plan around a cost that is not there.
     */
    public function test_a_plan_that_does_not_buy_is_not_disclosed_a_bought_homes_assumed_costs(): void
    {
        // Clear the CURRENT home's running costs as well as the bought home's: with a figure on the
        // current home the engine carries it across and assumes nothing, so there would be no
        // disclosure either way and the test would prove nothing. (richState merges shallowly, so
        // the property block is edited in place rather than passed as an override.)
        $state = ScenarioFixture::richState();
        $state['property']['runningCosts'] = '';

        $staysPut = ScenarioFixture::fromState($this->user, ['variant' => 'stay_put'] + $state);
        $buys = ScenarioFixture::fromState($this->user, ['variant' => 'buy_outright'] + $state);

        $assumedOf = fn ($scenario): array => array_values(array_filter(
            app(ScenarioPdfController::class)->data($scenario)['inputNotes'],
            fn (array $note): bool => $note['kind'] === 'assumed_figure',
        ));

        // The fixture leaves the bought home's running costs blank, so the buy plan HAS an assumed
        // figure to disclose — without this the stay-put assertion below would pass vacuously.
        $this->assertNotEmpty($assumedOf($buys), 'The buying plan should disclose its assumed running costs.');
        $this->assertStringContainsString('running costs for the home', $this->renderReport($buys));

        // A stay-put plan may still carry assumed figures of its own (using the ISA allowance is
        // one, and applies to any plan), so the claim is narrower than "none at all": none of them
        // may be about the home it never buys.
        foreach ($assumedOf($staysPut) as $note) {
            $this->assertStringNotContainsString('the home you\'d buy', $note['text'],
                'A stay-put plan is being disclosed assumed figures for a home it never buys.');
            $this->assertStringNotContainsString('moving costs', $note['text'],
                'A stay-put plan is being disclosed the cost of a move it never makes.');
        }
        $this->assertStringNotContainsString('running costs for the home', $this->renderReport($staysPut));
    }

    /** …and a plan that does sell still gets all of it. */
    public function test_a_selling_plan_still_gets_the_sale_information(): void
    {
        $scenario = ScenarioFixture::rich($this->user, ['variant' => 'rent']);

        $report = app(ScenarioPdfController::class)->data($scenario);
        $html = $this->renderReport($scenario);

        $this->assertTrue($report['salePlanned']);
        $this->assertNotNull($report['saleExplainer']);
        $this->assertStringContainsString('If you sell: where the money comes from and goes', $html);
        $this->assertStringContainsString('Housing-decision inputs', $html);
    }

    /**
     * The income side of the plan is echoed back like the spending side: what comes in, where
     * the capital sits, and when each source starts and stops. Until this shipped a reader
     * could see what a plan spends but not what funds it.
     */
    public function test_the_report_shows_where_the_money_comes_from(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        $report = app(ScenarioPdfController::class)->data($scenario);
        $html = $this->renderReport($scenario);

        $this->assertNotEmpty($report['incomePlan']['income'], 'The plan has income sources; none were exported.');
        $this->assertNotEmpty($report['incomePlan']['capital'], 'The plan has capital; none was exported.');
        $this->assertNotEmpty($report['incomePlan']['timeline'], 'No income source was traced over time.');

        $this->assertStringContainsString('Where your money comes from', $html);
        $this->assertStringContainsString('Capital you can draw on', $html);
        $this->assertStringContainsString('How each source changes over time', $html);

        // Every source that ever pays in the projection is accounted for in the timeline —
        // the completeness rule: an input that reaches the result must reach the reader.
        $paying = [];
        foreach ($report['ladder']['sources'] as $source) {
            $paying[] = $report['ladder']['sourceLabels'][$source];
        }
        $traced = array_column($report['incomePlan']['timeline'], 'label');
        $this->assertSame([], array_values(array_diff($paying, $traced)),
            'A source the cashflow ladder pays out is missing from the income timeline.');
    }

    /** The spending plan carries monthly beside annual — a household budgets by the month. */
    public function test_the_spending_plan_shows_monthly_and_annual(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        $report = app(ScenarioPdfController::class)->data($scenario);
        $html = $this->renderReport($scenario);

        $this->assertArrayHasKey('spendingTotalMonthly', $report['budget']);
        foreach ($report['budget']['tiers'] as $tier) {
            $this->assertArrayHasKey('subtotalMonthly', $tier);
            foreach ($tier['lines'] as $line) {
                $this->assertArrayHasKey('amountMonthly', $line);
                $this->assertStringContainsString($line['amountMonthly'], $html);
            }
        }
    }

    /** The full cashflow ladder prints every column the CSV exports — no quietly dropped figure. */
    public function test_the_printed_ladder_carries_every_column(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        $html = $this->renderReport($scenario);

        foreach (['Tax', 'Spend', 'of which essential', 'of which discretionary', 'Unmet spend',
            'To spend / month', 'of which free', 'Available capital', 'Pension capital (taxable)',
            'Usable (excl. home)', 'Total (incl. home equity)'] as $column) {
            $this->assertStringContainsString($column, $html, "The printed ladder is missing the '{$column}' column.");
        }

        // Every income source the ladder itemises has a column of its own.
        $ladder = app(ScenarioPdfController::class)->data($scenario)['ladder'];
        $this->assertNotEmpty($ladder['sources']);
        foreach ($ladder['sources'] as $source) {
            $this->assertStringContainsString($ladder['sourceLabels'][$source], $html);
        }
    }

    public function test_the_report_renders_the_key_figures_and_the_disclaimer(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        $html = $this->renderReport($scenario);

        $this->assertStringContainsString($scenario->name, $html);
        $this->assertStringContainsString('Guidance only, not financial advice', $html);
        $this->assertStringContainsString('Your spending plan', $html);
        $this->assertStringContainsString('Year-by-year cashflow', $html);
        // The house-sale funding waterfall, single-sourced from the same presenter the screen
        // uses: the rich fixture sells & buys, so the "If you sell" block and its net-proceeds
        // line must print (the PDF must not silently drop the sale explainer).
        $this->assertStringContainsString('If you sell: where the money comes from and goes', $html);
        $this->assertStringContainsString('Net proceeds', $html);
        $this->assertStringContainsString('If you sell &amp; buy', $html);
        // Deterministic-only report says so when no Monte Carlo run exists yet.
        $this->assertStringContainsString('No completed Monte Carlo run yet', $html);
    }

    public function test_the_report_adds_the_monte_carlo_summary_once_a_run_exists(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        (new SimulationRunner(new ScenarioForecaster))->preview($scenario, paths: 20);

        $html = $this->renderReport($scenario);

        $this->assertStringContainsString('Will the money last?', $html);
        $this->assertStringContainsString('Essentials always met', $html);
        // The verdict sentence the screen leads with, not just the percentages behind it.
        $this->assertStringContainsString('On these figures', $html);
    }

    public function test_export_all_streams_a_single_pdf(): void
    {
        ScenarioFixture::rich($this->user);
        ScenarioFixture::rich($this->user);

        $response = $this->get(route('scenarios.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_export_all_includes_every_ready_scenario_and_only_the_owners(): void
    {
        $first = ScenarioFixture::rich($this->user);
        $first->update(['name' => 'First plan']);
        $second = ScenarioFixture::rich($this->user);
        $second->update(['name' => 'Second plan']);
        $draft = ScenarioFixture::rich($this->user);
        $draft->update(['name' => 'Unfinished draft', 'status' => ScenarioStatus::Draft]);
        $other = ScenarioFixture::rich(User::factory()->create());
        $other->update(['name' => 'Someone elses plan']);

        $reports = app(ScenarioPdfController::class)->reports($this->user);
        $html = view('pdf.results', ['reports' => $reports])->render();

        $this->assertStringContainsString('First plan', $html);
        $this->assertStringContainsString('Second plan', $html);
        $this->assertStringNotContainsString('Unfinished draft', $html);
        $this->assertStringNotContainsString('Someone elses plan', $html);
    }

    public function test_export_all_with_nothing_ready_is_not_found(): void
    {
        $this->get(route('scenarios.pdf'))->assertNotFound();
    }

    /** The exact data the export produces, rendered through the exact template it uses. */
    private function renderReport(Scenario $scenario): string
    {
        return view('pdf.results', ['reports' => [app(ScenarioPdfController::class)->data($scenario)]])->render();
    }

    /**
     * Every text run painted into the rendered PDF, with its position in PAGE points.
     *
     * Position, not presence, is what matters: dompdf still writes a cell that overflows the
     * paper, so merely finding the text proves nothing — a guard built on `assertStringContains`
     * passes happily while the figure sits off the page and no reader ever sees it. The
     * graphics-state stack is tracked so the coordinates of text drawn inside a scaled block
     * (the chart images carry a 0.75 `cm` transform) are mapped back to page space.
     *
     * @return list<array{x: float, right: float, text: string}>
     */
    private function paintedTextRuns(Scenario $scenario): array
    {
        $pdf = $this->get(route('scenarios.results.pdf', $scenario))->getContent();

        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams);

        $runs = [];
        foreach ($streams[1] as $chunk) {
            $ops = @gzuncompress($chunk);
            if ($ops === false) {
                continue;
            }

            // [scaleX, translateX] — enough for the axis-aligned transforms dompdf emits.
            $ctm = [1.0, 0.0];
            $stack = [];

            preg_match_all(
                '/(?<save>\bq\b)|(?<restore>\bQ\b)|(?<cm>([\d.-]+) ([\d.-]+) ([\d.-]+) ([\d.-]+) ([\d.-]+) ([\d.-]+) cm)'
                .'|(?<text>BT ([\d.-]+) ([\d.-]+) Td \/F\d+ ([\d.]+) Tf\s+\[\((.*?)\)\] TJ)/s',
                $ops,
                $tokens,
                PREG_SET_ORDER,
            );

            foreach ($tokens as $token) {
                if (($token['save'] ?? '') !== '') {
                    $stack[] = $ctm;

                    continue;
                }
                if (($token['restore'] ?? '') !== '') {
                    $ctm = array_pop($stack) ?? [1.0, 0.0];

                    continue;
                }
                if (($token['cm'] ?? '') !== '') {
                    // Compose: new scale = a * old scale; new offset = e * old scale + old offset.
                    $ctm = [(float) $token[3] * $ctm[0], (float) $token[8] * $ctm[0] + $ctm[1]];

                    continue;
                }

                // dompdf writes the string as UTF-16BE inside the content stream.
                $text = mb_convert_encoding(stripcslashes($token[14]), 'UTF-8', 'UTF-16BE');
                $x = (float) $token[11] * $ctm[0] + $ctm[1];
                // DejaVu Sans digits are a uniform 0.636em, so this is accurate for the money
                // tokens the overflow test actually measures.
                $width = mb_strlen($text) * (float) $token[13] * $ctm[0] * 0.64;

                $runs[] = ['x' => $x, 'right' => $x + $width, 'text' => $text];
            }
        }

        return $runs;
    }

    /**
     * The variable set the results page hands its view — read from the component itself so the
     * completeness guard above cannot drift from the screen it is guarding.
     *
     * @return array<string, mixed>
     */
    private function screenData(Scenario $scenario): array
    {
        $component = new ScenarioResults;
        $component->mount($scenario);

        return $component->render()->getData();
    }
}
