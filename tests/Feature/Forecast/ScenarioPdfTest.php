<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Enums\ScenarioStatus;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Http\Controllers\ScenarioPdfController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The downloadable PDF results summary. The route streams a real PDF; the view-render
 * tests assert the figures + the guidance-only disclaimer are present, built from the
 * same ResultPresenter the on-screen page uses (so the print cannot drift).
 */
class ScenarioPdfTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_report_renders_the_key_figures_and_the_disclaimer(): void
    {
        $scenario = ScenarioFixture::rich($this->user);

        $html = view('pdf.results', ['reports' => [app(ScenarioPdfController::class)->data($scenario)]])->render();

        $this->assertStringContainsString($scenario->name, $html);
        $this->assertStringContainsString('Guidance only, not financial advice', $html);
        $this->assertStringContainsString('Spending budget', $html);
        $this->assertStringContainsString('Cashflow projection', $html);
        // The house-sale funding waterfall, single-sourced from the same presenter the screen
        // uses: the rich fixture sells & buys, so the "If you sell" block and its net-proceeds
        // line must print (the PDF must not silently drop the sale explainer — the blocker fixed).
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

        $html = view('pdf.results', ['reports' => [app(ScenarioPdfController::class)->data($scenario)]])->render();

        $this->assertStringContainsString('Will the money last?', $html);
        $this->assertStringContainsString('Essentials always met', $html);
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
}
