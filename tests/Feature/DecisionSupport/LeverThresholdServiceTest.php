<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\LeverKey;
use App\DecisionSupport\LeverThresholdService;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use Tests\Support\BuilderStateFixture;
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

        // Survivor's DB fraction: the whole 0–100% range (none through the full pension).
        $survivor = $service->defaultGrid(LeverKey::SurvivorDbFraction, $household, $action);
        $this->assertSame(0.0, $survivor[0]);
        $this->assertSame(100.0, end($survivor));

        // Survivor's annuity fraction: likewise the whole 0–100% joint-life range.
        $annuity = $service->defaultGrid(LeverKey::SurvivorAnnuityFraction, $household, $action);
        $this->assertSame(0.0, $annuity[0]);
        $this->assertSame(100.0, end($annuity));

        // Per-person longevity: a ± year offset from the cohort peer, −5 to +15 years.
        $longevity = $service->defaultGrid(LeverKey::PersonLongevity, $household, $action);
        $this->assertSame(-5.0, $longevity[0]);
        $this->assertSame(15.0, end($longevity));

        // Care: a categorical toggle, exactly two points (off, on) — not a range to bracket.
        $this->assertSame([0.0, 1.0], $service->defaultGrid(LeverKey::Care, $household, $action));
    }

    public function test_a_scenario_computes_a_care_off_vs_on_comparison(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        $outcome = $this->service()->compute(
            $scenario,
            LeverKey::Care,
            SweepMetric::Essentials,
            targetProbability: 0.90,
            grid: [0.0, 1.0],
            nPaths: 60,
        );

        // Exactly two pinned states, labelled as the care toggle, read as a before/after (not monotone).
        $this->assertSame(LeverKey::Care, $outcome->lever);
        $this->assertSame('whether care fees are modelled', $outcome->curve->leverName);
        $this->assertSame([0.0, 1.0], array_map(fn ($p) => $p->leverValue, $outcome->curve->points));
        $this->assertSame(LeverDirection::Unknown, $outcome->curve->direction);
    }

    public function test_a_scenario_computes_a_per_person_longevity_threshold(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        $outcome = $this->service()->compute(
            $scenario,
            LeverKey::PersonLongevity,
            SweepMetric::Essentials,
            targetProbability: 0.90,
            grid: [0.0, 8.0, 15.0],
            nPaths: 60,
            leverParam: 'p2', // the survivor-side partner
        );

        $this->assertSame(LeverKey::PersonLongevity, $outcome->lever);
        $this->assertSame('how long one of you lives', $outcome->curve->leverName);
        $this->assertSame('years', $outcome->curve->leverUnit);
        $this->assertSame([0.0, 8.0, 15.0], array_map(fn ($p) => $p->leverValue, $outcome->curve->points));
        // Longevity is not provably monotone (whose life it is decides the sign), so the curve
        // declares its direction Unknown — the sweep reports the first crossing, never a monotone fit.
        $this->assertSame(LeverDirection::Unknown, $outcome->curve->direction);
    }

    public function test_a_scenario_computes_an_annuity_survivor_fraction_threshold(): void
    {
        $scenario = $this->annuityScenario(User::factory()->create());

        $outcome = $this->service()->compute(
            $scenario,
            LeverKey::SurvivorAnnuityFraction,
            SweepMetric::Essentials,
            targetProbability: 0.90,
            grid: [0.0, 50.0, 100.0],
            nPaths: 60,
        );

        $this->assertSame(LeverKey::SurvivorAnnuityFraction, $outcome->lever);
        $this->assertSame('annuity survivor income', $outcome->curve->leverName);
        $this->assertSame('%', $outcome->curve->leverUnit);
        $this->assertSame([0.0, 50.0, 100.0], array_map(fn ($p) => $p->leverValue, $outcome->curve->points));
    }

    /** A rich scenario whose DC pot buys a joint-life annuity, so the annuity survivor lever applies. */
    private function annuityScenario(User $user): Scenario
    {
        $pensions = BuilderStateFixture::full()['pensions'];
        $pensions[0] = array_merge($pensions[0], [
            'annuitise' => '1', 'annuityAmount' => '150000', 'annuityAtAge' => '66',
            'annuityRate' => '6.5', 'annuityEscalation' => 'none',
            'annuityJoint' => '1', 'annuitySurvivorFraction' => '50',
        ]);

        return ScenarioFixture::rich($user, ['pensions' => $pensions]);
    }

    public function test_a_scenario_computes_a_buy_price_by_retirement_age_frontier_with_progress(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        $progress = [];
        $outcome = $this->service()->computeFrontier(
            $scenario,
            LeverKey::BuyPrice,
            LeverKey::RetirementAge,
            SweepMetric::Essentials,
            targetProbability: 0.90,
            thresholdGrid: [150_000.0, 250_000.0],
            conditionGrid: [62.0, 70.0],
            nPaths: 40,
            onProgress: function (int $done, int $total) use (&$progress): void {
                $progress[] = [$done, $total];
            },
        );

        // The outcome names both levers and carries the pinned seed as provenance.
        $this->assertSame(LeverKey::BuyPrice, $outcome->thresholdLever);
        $this->assertSame(LeverKey::RetirementAge, $outcome->conditionLever);
        $this->assertSame(0.90, $outcome->targetProbability);
        $this->assertSame(LeverThresholdService::SEED, $outcome->frontier->seed);
        $this->assertSame([62.0, 70.0], array_map(fn ($p) => $p->conditionValue, $outcome->frontier->points));

        // Every column keeps its full measured curve — the heatmap's cells, not just the iso-line.
        foreach ($outcome->frontier->points as $point) {
            $this->assertCount(2, $point->curve->points);
        }

        // Progress ticks once per cell against the whole frontier (2 columns × 2 cells).
        $this->assertSame([[1, 4], [2, 4], [3, 4], [4, 4]], $progress);
    }

    public function test_a_frontier_column_matches_the_one_dimensional_threshold_at_that_held_value(): void
    {
        // The Phase-5 correctness pin (docs/build/PLAN-decision-support.md): the iso-line is the Phase-1
        // threshold repeated per held value, so a frontier column must reproduce the 1-D compute on
        // a scenario that already HOLDS the condition — same pinned seed, byte-identical curve.
        $user = User::factory()->create();
        $scenario = ScenarioFixture::rich($user);
        $grid = [20_000.0, 30_000.0];

        $frontier = $this->service()->computeFrontier(
            $scenario,
            LeverKey::EssentialSpend,
            LeverKey::RetirementAge,
            SweepMetric::Essentials,
            targetProbability: 0.90,
            thresholdGrid: $grid,
            conditionGrid: [62.0, 70.0],
            nPaths: 50,
        );

        // The same plan with retirement at 62 actually entered in the builder state.
        $people = BuilderStateFixture::full()['people'];
        $people[0]['plannedRetirementAge'] = '62';
        $held = ScenarioFixture::rich($user, ['people' => $people]);
        $oneD = $this->service()->compute($held, LeverKey::EssentialSpend, SweepMetric::Essentials, 0.90, $grid, 50);

        $column = $frontier->frontier->points[0];
        $this->assertSame(62.0, $column->conditionValue);
        $this->assertSame(
            array_map(fn ($p) => $p->successProbability, $oneD->curve->points),
            array_map(fn ($p) => $p->successProbability, $column->curve->points),
        );
        $this->assertEquals($oneD->crossing, $column->crossing);
    }

    public function test_a_frontier_refuses_a_degenerate_lever_pair(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        try {
            $this->service()->computeFrontier($scenario, LeverKey::BuyPrice, LeverKey::BuyPrice, SweepMetric::Essentials, 0.90);
            $this->fail('the same lever on both axes should be refused');
        } catch (InvalidArgumentException) {
            // one swept, one held — they must differ
        }

        // The care toggle is a categorical pin-and-compare: no range to sweep or hold at.
        $this->expectException(InvalidArgumentException::class);
        $this->service()->computeFrontier($scenario, LeverKey::EssentialSpend, LeverKey::Care, SweepMetric::Essentials, 0.90);
    }

    public function test_the_default_condition_grid_is_a_coarse_span_of_the_lever_range(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        // Five held values across the same span the 1-D grid sweeps — every extra column costs a
        // whole Monte Carlo sweep, so the condition axis is deliberately coarser.
        $grid = $this->service()->defaultConditionGrid(
            LeverKey::RetirementAge, $scenario->toHousehold(), $scenario->toHousingAction(),
        );
        $this->assertSame([55.0, 60.0, 65.0, 70.0, 75.0], $grid);
    }

    public function test_a_scenario_computes_a_survivor_db_fraction_threshold(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        $outcome = $this->service()->compute(
            $scenario,
            LeverKey::SurvivorDbFraction,
            SweepMetric::Essentials,
            targetProbability: 0.90,
            grid: [0.0, 50.0, 100.0],
            nPaths: 60,
        );

        // The swept curve is labelled as the survivor lever and its points span the 0–100% grid.
        $this->assertSame(LeverKey::SurvivorDbFraction, $outcome->lever);
        $this->assertSame('survivor pension', $outcome->curve->leverName);
        $this->assertSame('%', $outcome->curve->leverUnit);
        $this->assertSame([0.0, 50.0, 100.0], array_map(fn ($p) => $p->leverValue, $outcome->curve->points));
    }
}
