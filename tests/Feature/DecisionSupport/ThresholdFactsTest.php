<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\Assistant\AssistantFact;
use App\Assistant\AssistantService;
use App\Assistant\ScenarioContext;
use App\Compliance\OutputPhrasing;
use App\DecisionSupport\FrontierOutcome;
use App\DecisionSupport\LeverKey;
use App\DecisionSupport\LeverThresholdService;
use App\DecisionSupport\ThresholdFacts;
use App\DecisionSupport\ThresholdOutcome;
use App\DecisionSupport\ThresholdRunner;
use App\Enums\SimulationStatus;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use App\Models\ThresholdResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Sweep\Crossing;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;
use RetireForecast\FinanceEngine\Sweep\Frontier;
use RetireForecast\FinanceEngine\Sweep\FrontierPoint;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepCurve;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use RetireForecast\FinanceEngine\Sweep\SweepPoint;
use Tests\Support\FakeChatClient;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Decision-support Phase 6: the assistant STATES computed lever limits, never calculates one.
 * These pin the load-bearing staleness gate (a limit reaches the assistant's context ONLY while
 * its inputs hash still matches the scenario's current inputs — so G1 can't restate a stale
 * figure because a stale figure is never in the allow-list), the grounded-band answer path, the
 * refusal after an input edit, and the neutral wording of every generated fact.
 */
final class ThresholdFactsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user = User::factory()->create());
    }

    /** @return list<AssistantFact> */
    private function facts(Scenario $scenario): array
    {
        return app(ThresholdFacts::class)->for($scenario);
    }

    /** @param  list<AssistantFact>  $facts */
    private function block(array $facts): string
    {
        return implode("\n", array_map(static fn (AssistantFact $f): string => "- {$f->label}: {$f->value}", $facts));
    }

    /**
     * A Done essential-spend threshold with a deterministic hand-built crossing (band £24k–£30k,
     * estimate £27k), stamped with the CORRECT inputs hash for the scenario's current state — so
     * the fact wording can be asserted exactly, without Monte Carlo variance.
     */
    private function completedThreshold(Scenario $scenario): ThresholdResult
    {
        $runner = app(ThresholdRunner::class);
        $grid = [18_000.0, 30_000.0, 42_000.0];
        $hash = $runner->inputsHash($scenario, LeverKey::EssentialSpend, SweepMetric::Essentials, 0.90, $grid, 40);
        $run = $runner->createRun($scenario, LeverKey::EssentialSpend, SweepMetric::Essentials, 0.90, $grid, 40, $hash);

        $run->setThresholdOutcome(new ThresholdOutcome(
            lever: LeverKey::EssentialSpend,
            metric: SweepMetric::Essentials,
            targetProbability: 0.90,
            curve: new SweepCurve(
                points: [
                    new SweepPoint(18_000.0, 0.97, 0.95, 0.99, 40),
                    new SweepPoint(30_000.0, 0.85, 0.80, 0.90, 40),
                    new SweepPoint(42_000.0, 0.60, 0.55, 0.65, 40),
                ],
                metric: SweepMetric::Essentials,
                direction: LeverDirection::Decreasing,
                leverName: 'essential spend',
                leverUnit: '£',
                pathsPerPoint: 40,
                seed: LeverThresholdService::SEED,
            ),
            crossing: new Crossing(CrossingVerdict::Crosses, 0.90, 24_000.0, 30_000.0, 27_000.0),
        ))->fill(['status' => SimulationStatus::Done, 'progress_pct' => 100])->save();

        return $run->fresh();
    }

    public function test_no_computed_limits_yields_the_honest_none_computed_fact(): void
    {
        $facts = $this->facts(ScenarioFixture::rich($this->user));

        $this->assertCount(1, $facts);
        $this->assertStringContainsString('None computed for the current inputs yet', $facts[0]->value);
    }

    public function test_a_fresh_limit_reaches_the_facts_as_the_meter_sentence_with_its_band(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        $this->completedThreshold($scenario);

        $block = $this->block($this->facts($scenario));

        // The meter's own sentence, the crossing band, and the success bar — all stated, all banded.
        $this->assertStringContainsString('How far can we go — Your essential spending', $block);
        $this->assertStringContainsString('up to about £27,000', $block);
        $this->assertStringContainsString('between £24,000 and £30,000', $block);
        $this->assertStringContainsString('a Monte Carlo band, not a hard line', $block);
        $this->assertStringContainsString('Success bar: a 90% chance the essential spending is covered for life', $block);

        // Neutral wording holds in the facts themselves (G2's banned phrases never appear).
        $this->assertSame([], OutputPhrasing::violations($block));
        $this->assertStringNotContainsStringIgnoringCase('safe', $block);
    }

    public function test_an_input_edit_makes_the_limit_stale_and_it_never_reaches_the_facts(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        $this->completedThreshold($scenario);
        $this->assertStringContainsString('£27,000', $this->block($this->facts($scenario)));

        // Edit the plan DIRECTLY (bypassing the builder's delete-on-edit invalidation) — the
        // belt-and-braces case the hash-match exists for: the row survives, but it no longer
        // answers the scenario's current inputs.
        $state = $scenario->effectiveBuilderState();
        $state['people'][0]['plannedRetirementAge'] = '63';
        $scenario->fillFromBuilderState($state);
        $scenario->save();
        $scenario = $scenario->fresh();

        $facts = $this->facts($scenario);

        $this->assertSame(1, ThresholdResult::count()); // the stale row still exists…
        $this->assertStringNotContainsString('£27,000', $this->block($facts)); // …but never surfaces
        $this->assertStringContainsString('None computed for the current inputs yet', $this->block($facts));
    }

    public function test_a_fresh_frontier_reaches_the_facts_with_both_levers_pinned(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        $runner = app(ThresholdRunner::class);
        $thresholdGrid = [200_000.0, 300_000.0];
        $conditionGrid = [62.0, 70.0];
        $hash = $runner->inputsHash(
            $scenario, LeverKey::BuyPrice, SweepMetric::Essentials, 0.90, $thresholdGrid, 40,
            conditionLever: LeverKey::RetirementAge, conditionGrid: $conditionGrid,
        );
        $run = $runner->createRun(
            $scenario, LeverKey::BuyPrice, SweepMetric::Essentials, 0.90, $thresholdGrid, 40, $hash,
            conditionLever: LeverKey::RetirementAge, conditionGrid: $conditionGrid,
        );

        $column = static fn (float $age, float $estimate): FrontierPoint => new FrontierPoint(
            conditionValue: $age,
            crossing: new Crossing(CrossingVerdict::Crosses, 0.90, $estimate - 25_000.0, $estimate + 25_000.0, $estimate),
            curve: new SweepCurve(
                [new SweepPoint(200_000.0, 0.95, 0.93, 0.97, 40), new SweepPoint(300_000.0, 0.80, 0.75, 0.85, 40)],
                SweepMetric::Essentials, LeverDirection::Decreasing, 'buy price', '£', 40, LeverThresholdService::SEED,
            ),
        );
        $run->setFrontierOutcome(new FrontierOutcome(
            thresholdLever: LeverKey::BuyPrice,
            conditionLever: LeverKey::RetirementAge,
            metric: SweepMetric::Essentials,
            targetProbability: 0.90,
            frontier: new Frontier(
                [$column(62.0, 250_000.0), $column(70.0, 310_000.0)],
                'buy price', 'retirement age', SweepMetric::Essentials, 0.90, 40, LeverThresholdService::SEED,
            ),
        ))->fill(['status' => SimulationStatus::Done, 'progress_pct' => 100])->save();

        $block = $this->block($this->facts($scenario));

        // Both levers pinned in the summary, plus each column's banded chip.
        $this->assertStringContainsString('trade-off between How much you spend on a new home and When you retire', $block);
        $this->assertStringContainsString('£250,000', $block);
        $this->assertStringContainsString('age 62', $block);
        $this->assertStringContainsString('£310,000', $block);
        $this->assertStringContainsString('age 70', $block);
        $this->assertStringContainsString('Column by column', $block);
        $this->assertSame([], OutputPhrasing::violations($block));
    }

    public function test_a_care_row_reads_as_a_pinned_before_after_not_a_limit(): void
    {
        $scenario = ScenarioFixture::rich($this->user);
        $runner = app(ThresholdRunner::class);
        $grid = [0.0, 1.0];
        $hash = $runner->inputsHash($scenario, LeverKey::Care, SweepMetric::Essentials, 0.90, $grid, 40);
        $run = $runner->createRun($scenario, LeverKey::Care, SweepMetric::Essentials, 0.90, $grid, 40, $hash);

        $run->setThresholdOutcome(new ThresholdOutcome(
            lever: LeverKey::Care,
            metric: SweepMetric::Essentials,
            targetProbability: 0.90,
            curve: new SweepCurve(
                [new SweepPoint(0.0, 0.93, 0.91, 0.95, 40), new SweepPoint(1.0, 0.82, 0.79, 0.85, 40)],
                SweepMetric::Essentials, LeverDirection::Unknown, 'whether care fees are modelled', 'state', 40, LeverThresholdService::SEED,
            ),
            crossing: new Crossing(CrossingVerdict::NonMonotone, 0.90), // ignored: care has no interpolated limit
        ))->fill(['status' => SimulationStatus::Done, 'progress_pct' => 100])->save();

        $block = $this->block($this->facts($scenario));

        $this->assertStringContainsString('a pinned before/after, not a limit', $block);
        $this->assertStringContainsString('93%', $block);
        $this->assertStringContainsString('82%', $block);
        // Never an interpolated care "limit" — the crossing is deliberately unstated.
        $this->assertStringNotContainsString('up to about', $block);
        $this->assertSame([], OutputPhrasing::violations($block));
    }

    public function test_the_context_volunteers_the_income_floor_and_the_survivor_cliff(): void
    {
        // Phase 6's "volunteers the survivor-cliff fact": a couple's context carries the income
        // floor for the both-alive year AND the survivor-year twin, so the assistant can raise
        // what the first death does to guaranteed income without the reader knowing to ask.
        $scenario = ScenarioFixture::rich($this->user);

        $block = ScenarioContext::for($scenario, app(ScenarioForecaster::class))->promptBlock();

        $this->assertStringContainsString('Income floor — guaranteed income vs essentials', $block);
        $this->assertStringContainsString("Income floor — the survivor's year", $block);
        $this->assertStringContainsString('Guaranteed-for-life income', $block);
        $this->assertSame([], OutputPhrasing::violations($block));
    }

    public function test_the_assistant_states_a_grounded_limit_and_refuses_it_once_stale(): void
    {
        // The spec's "Done when": the assistant answers with the grounded band while the limit is
        // fresh, and refuses the SAME sentence once the context no longer carries the limit.
        $scenario = ScenarioFixture::rich($this->user);
        $this->completedThreshold($scenario);

        $forecast = new ForecastResult(
            years: [],
            essentialsAlwaysMet: true,
            fullSpendAlwaysMet: true,
            depletionCalendarYear: null,
            terminalTotalWealth: Money::zero(),
            terminalUsableWealth: Money::zero(),
            finalCalendarYear: 2058,
        );
        $reply = 'On these figures the money stays on track up to about £27,000 of essential spending a year; spend more and the odds slip below your target.';
        $question = 'How much essential spending can the plan support?';

        // Fresh: the limit is in the context, so the model's sentence is fully grounded.
        $freshContext = ScenarioContext::fromForecast('Plan', 'Sell and rent', $forecast, extraFacts: $this->facts($scenario));
        $answer = (new AssistantService(new FakeChatClient([$reply])))->answer($freshContext, $question, adviceAllowed: false);
        $this->assertSame('answered', $answer->status);

        // Stale: after an input edit the facts carry no limit, so the very same sentence now
        // contains an unverifiable figure — G1 refuses rather than restate it.
        $state = $scenario->effectiveBuilderState();
        $state['people'][0]['plannedRetirementAge'] = '63';
        $scenario->fillFromBuilderState($state);
        $scenario->save();

        $staleContext = ScenarioContext::fromForecast('Plan', 'Sell and rent', $forecast, extraFacts: $this->facts($scenario->fresh()));
        $refused = (new AssistantService(new FakeChatClient([$reply])))->answer($staleContext, $question, adviceAllowed: false);
        $this->assertSame('ungrounded_refused', $refused->status);
    }
}
