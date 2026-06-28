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

        $html = view('pdf.results', app(ScenarioPdfController::class)->data($scenario))->render();

        $this->assertStringContainsString($scenario->name, $html);
        $this->assertStringContainsString('Guidance only, not financial advice', $html);
        $this->assertStringContainsString('Spending budget', $html);
        $this->assertStringContainsString('Cashflow projection', $html);
        // Deterministic-only report says so when no Monte Carlo run exists yet.
        $this->assertStringContainsString('No completed Monte Carlo run yet', $html);
    }

    public function test_the_report_adds_the_monte_carlo_summary_once_a_run_exists(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        (new SimulationRunner(new ScenarioForecaster))->preview($scenario, paths: 20);

        $html = view('pdf.results', app(ScenarioPdfController::class)->data($scenario))->render();

        $this->assertStringContainsString('Will the money last?', $html);
        $this->assertStringContainsString('Essentials always met', $html);
    }
}
