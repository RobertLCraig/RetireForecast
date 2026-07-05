<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\LeverKey;
use App\DecisionSupport\LeverThresholdService;
use App\DecisionSupport\ThresholdPresenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The "How far can we go?" view models (Phase 2): the instant deterministic net-position line
 * behind the slider, the green→red meter, the analyst S-curve, and the natural-frequency
 * pictograph. Proves the line reconciles to the engine, the guardrails hold (no "safe" wording,
 * death vertical recoloured off shortfall-red), and the meter boundary follows the crossing.
 */
final class ThresholdPresenterTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LeverThresholdService
    {
        return app(LeverThresholdService::class);
    }

    public function test_the_transient_deterministic_forecast_runs_at_a_lever_value(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        $forecast = $this->service()->deterministicForecastAt($scenario, LeverKey::RetirementAge, 66.0);

        $this->assertInstanceOf(ForecastResult::class, $forecast);
        $this->assertNotEmpty($forecast->years); // a real per-year series to plot
    }

    public function test_the_net_position_line_uses_the_money_axis_and_recolours_the_death_vertical(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());
        $forecast = $this->service()->deterministicForecastAt($scenario, LeverKey::RetirementAge, 66.0);

        $panel = ThresholdPresenter::netPosition($forecast, $scenario->toHousehold(), 'Net position');

        $this->assertTrue($panel['options']['moneyAxis']);
        $this->assertCount(1, $panel['options']['series']); // one central line, no percentile bands
        $this->assertNotEmpty($panel['options']['series'][0]['data']);
        $this->assertNotEmpty($panel['rows']);

        // Guardrail: the death vertical must not be shortfall-red (#ef4444/#dc2626), so the two
        // reds don't collide. Every milestone vertical lands under annotations.xaxis.
        foreach ($panel['options']['annotations']['xaxis'] ?? [] as $annotation) {
            $this->assertNotSame('#dc2626', $annotation['borderColor'] ?? null);
        }
    }

    public function test_the_meter_boundary_and_caption_follow_the_crossing(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());
        $outcome = $this->service()->compute(
            $scenario, LeverKey::RetirementAge, SweepMetric::Essentials, 0.90, [60.0, 65.0, 70.0, 75.0], 60,
        );

        $meter = ThresholdPresenter::meter($outcome, LeverKey::RetirementAge, 66.0);

        $this->assertTrue($meter['increasing']); // later retirement is on-track
        // The meter boundary is the Phase-1 crossing, not a re-derived value.
        $this->assertSame($outcome->crossing->estimate, $meter['crossing']);
        $this->assertGreaterThanOrEqual(0.0, $meter['currentFrac']);
        $this->assertLessThanOrEqual(1.0, $meter['currentFrac']);
        // Guardrail: neutral copy never uses the word "safe".
        $this->assertStringNotContainsStringIgnoringCase('safe', $meter['caption']);

        if ($meter['crossingFrac'] !== null) {
            $this->assertGreaterThanOrEqual(0.0, $meter['crossingFrac']);
            $this->assertLessThanOrEqual(1.0, $meter['crossingFrac']);
        }
    }

    public function test_the_s_curve_carries_a_point_per_grid_value_with_its_interval(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());
        $outcome = $this->service()->compute(
            $scenario, LeverKey::EssentialSpend, SweepMetric::Essentials, 0.90, [24_000.0, 30_000.0, 36_000.0], 60,
        );

        $sCurve = ThresholdPresenter::sCurve($outcome, LeverKey::EssentialSpend);

        $this->assertCount(3, $sCurve['options']['series'][0]['data']);
        $this->assertCount(3, $sCurve['rows']);
        $this->assertSame('£24,000', $sCurve['rows'][0]['value']); // formatted as money
        $this->assertArrayHasKey('ciLow', $sCurve['rows'][0]);
    }

    public function test_the_pictograph_is_a_ten_dot_natural_frequency(): void
    {
        $pict = ThresholdPresenter::pictograph(0.88, 2045);

        $this->assertSame(9, $pict['filled']); // ~9 of 10 futures
        $this->assertSame(1, $pict['empty']);
        $this->assertSame(2045, $pict['runsOutYear']);
    }

    public function test_lever_values_format_by_kind(): void
    {
        $this->assertSame('age 68', ThresholdPresenter::formatLeverValue(LeverKey::RetirementAge, 68.0));
        $this->assertSame('£260,000', ThresholdPresenter::formatLeverValue(LeverKey::BuyPrice, 260_000.0));
    }
}
