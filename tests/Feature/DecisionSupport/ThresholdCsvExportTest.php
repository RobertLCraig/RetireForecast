<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\LeverKey;
use App\DecisionSupport\ThresholdCsvExporter;
use App\DecisionSupport\ThresholdRunner;
use App\Models\Scenario;
use App\Models\ThresholdResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use Tests\Feature\Forecast\ScenarioPdfTest;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * A computed threshold exports as CSV — and, like every export in the app, never travels
 * without its guidance-only disclaimer. The download is owner-scoped and the threshold must
 * belong to the scenario in the URL. Mirrors {@see ScenarioPdfTest}.
 */
final class ThresholdCsvExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function computedThreshold(?Scenario $scenario = null): ThresholdResult
    {
        $scenario ??= ScenarioFixture::rich($this->user);

        // sync queue -> the sweep runs inline; tiny grid + paths for speed.
        return app(ThresholdRunner::class)
            ->request($scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, [62.0, 66.0, 70.0], 40)
            ->fresh();
    }

    public function test_the_exporter_carries_the_disclaimer_the_provenance_and_the_grid(): void
    {
        $run = $this->computedThreshold();

        $csv = implode("\n", array_map(fn (array $row): string => implode('|', $row), ThresholdCsvExporter::rows($run)));

        $this->assertStringContainsString('guidance only, not financial advice', $csv);
        $this->assertStringContainsString('Free, impartial guidance', $csv);
        $this->assertStringContainsString('Lever value', $csv); // the grid header
        $this->assertStringContainsString('Verdict', $csv);      // the honest crossing verdict
        $this->assertStringContainsString('Paths per point', $csv);
        $this->assertStringContainsString('62.00', $csv);        // a swept grid value
    }

    public function test_the_care_csv_is_a_pinned_before_after_with_no_interpolated_threshold(): void
    {
        // The care lever is a binary pin-and-compare, so its CSV must NOT print a crossing verdict
        // or an interpolated "threshold estimate" (63% of care modelled is meaningless) — it states
        // the two states plainly and names them, not a bare 0/1.
        $scenario = ScenarioFixture::rich($this->user);
        $run = app(ThresholdRunner::class)
            ->request($scenario, LeverKey::Care, SweepMetric::Essentials, 0.90, [0.0, 1.0], 40)
            ->fresh();

        $csv = implode("\n", array_map(fn (array $row): string => implode('|', $row), ThresholdCsvExporter::rows($run)));

        // Still carries the disclaimer + provenance, like every export.
        $this->assertStringContainsString('guidance only, not financial advice', $csv);
        $this->assertStringContainsString('Paths per point', $csv);

        // No crossing / interpolated threshold — instead a plain before/after statement + named states.
        $this->assertStringNotContainsString('Verdict', $csv);
        $this->assertStringNotContainsString('Threshold estimate', $csv);
        $this->assertStringContainsString('pinned before/after', $csv);
        $this->assertStringContainsString('State', $csv); // the grid header names the states, not "Lever value"
        $this->assertStringContainsString('care fees not modelled', $csv);
        $this->assertStringContainsString('care fees modelled', $csv);
    }

    public function test_the_owner_can_download_the_threshold_csv(): void
    {
        $run = $this->computedThreshold();

        $response = $this->get(route('scenarios.threshold.csv', [$run->scenario_id, $run->id]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('guidance only, not financial advice', $response->streamedContent());
    }

    public function test_the_csv_is_owner_scoped(): void
    {
        $run = $this->computedThreshold();

        $this->actingAs(User::factory()->create());

        $this->get(route('scenarios.threshold.csv', [$run->scenario_id, $run->id]))->assertForbidden();
    }

    public function test_a_threshold_must_belong_to_the_scenario_in_the_url(): void
    {
        $run = $this->computedThreshold();
        $otherScenario = ScenarioFixture::rich($this->user);

        $this->get(route('scenarios.threshold.csv', [$otherScenario->id, $run->id]))->assertNotFound();
    }
}
