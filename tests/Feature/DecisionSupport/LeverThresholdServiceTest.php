<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\LeverKey;
use App\DecisionSupport\LeverThresholdService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The app-layer bridge that turns a persisted scenario into a lever-threshold sweep. Proves a real
 * scenario computes a threshold end-to-end through the same forecaster inputs the results page uses,
 * that the run is reproducible (pinned seed) and reports progress, and that the default grids bracket
 * the scenario's own figures.
 */
final class LeverThresholdServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LeverThresholdService
    {
        return app(LeverThresholdService::class);
    }

    public function test_a_scenario_computes_a_retirement_age_threshold_with_progress(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        $progress = [];
        $outcome = $this->service()->compute(
            $scenario,
            LeverKey::RetirementAge,
            SweepMetric::Essentials,
            targetProbability: 0.90,
            grid: [62.0, 66.0, 70.0],
            nPaths: 80,
            onProgress: function (int $done, int $total) use (&$progress): void {
                $progress[] = [$done, $total];
            },
        );

        // The outcome records what it answered and carries the pinned seed as provenance.
        $this->assertSame(LeverKey::RetirementAge, $outcome->lever);
        $this->assertSame(SweepMetric::Essentials, $outcome->metric);
        $this->assertSame(0.90, $outcome->targetProbability);
        $this->assertCount(3, $outcome->curve->points);
        $this->assertSame(LeverThresholdService::SEED, $outcome->curve->seed);
        $this->assertInstanceOf(CrossingVerdict::class, $outcome->crossing->verdict);

        // Progress is reported per grid point, never a silent long run.
        $this->assertSame([[1, 3], [2, 3], [3, 3]], $progress);
    }

    public function test_the_same_inputs_give_a_reproducible_curve(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());
        $args = [$scenario, LeverKey::EssentialSpend, SweepMetric::Essentials, 0.90, [24_000.0, 30_000.0], 60];

        $a = $this->service()->compute(...$args);
        $b = $this->service()->compute(...$args);

        $this->assertSame(
            array_map(fn ($p) => $p->successProbability, $a->curve->points),
            array_map(fn ($p) => $p->successProbability, $b->curve->points),
        );
    }

    public function test_default_grids_bracket_the_scenario_figures(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());
        $service = $this->service();
        $household = $scenario->toHousehold();
        $action = $scenario->toHousingAction();

        // Retirement age: the builder's 55–75 window.
        $retire = $service->defaultGrid(LeverKey::RetirementAge, $household, $action);
        $this->assertSame(55.0, $retire[0]);
        $this->assertSame(75.0, end($retire));

        // Buy price: from £100k up to the entered sale value (buying dearer is not "buying cheaper").
        $buy = $service->defaultGrid(LeverKey::BuyPrice, $household, $action);
        $this->assertSame(100_000.0, $buy[0]);
        $this->assertLessThanOrEqual((float) intdiv($action->salePrice->pence, 100), end($buy));

        // Essential spend: spanning the current floor (half to one-and-a-half times).
        $essential = (float) intdiv($household->expenseProfile->essentialAnnualSpend->pence, 100);
        $spend = $service->defaultGrid(LeverKey::EssentialSpend, $household, $action);
        $this->assertEqualsWithDelta(0.5 * $essential, $spend[0], 0.01);
        $this->assertEqualsWithDelta(1.5 * $essential, end($spend), 0.01);
    }
}
