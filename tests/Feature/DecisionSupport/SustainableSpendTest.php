<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\LeverKey;
use App\DecisionSupport\LeverThresholdService;
use App\DecisionSupport\SustainableSpend;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * "How much could they actually afford to spend on things they choose?" — the one question the rest
 * of the tool cannot answer, because every other figure is bounded by the budget the user entered.
 *
 * The properties that matter:
 *  1. the answer is SENSITIVE to the plan (a richer household can afford more) — the failure mode
 *     found in development was a bar so weak the search always ran to its ceiling;
 *  2. the answer is genuinely affordable (the plan holds AT it) and genuinely maximal (the plan
 *     breaks just above it) — that is what makes it a threshold rather than a guess;
 *  3. a plan that cannot cover essentials at all returns NULL, not £0 — "broken" and "no room for
 *     treats" must not render the same.
 */
final class SustainableSpendTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(array $overrides = []): Scenario
    {
        $user = User::factory()->create();
        $state = array_merge(BuilderStateFixture::full(), $overrides);

        $scenario = new Scenario;
        $scenario->user_id = $user->id;
        $scenario->builder_state = $state;
        $scenario->projectFrom($state);
        $scenario->save();

        return $scenario;
    }

    private function solver(): SustainableSpend
    {
        return app(SustainableSpend::class);
    }

    /** @param array<string, mixed> $state */
    private function withWealth(array $state, string $isaBalance): array
    {
        $state['accounts'] = [
            ['id' => 'acc1', 'ownerId' => 'p1', 'type' => 'isa', 'balance' => $isaBalance, 'unrealisedGain' => '', 'yield' => '3'],
        ];

        return $state;
    }

    public function test_a_wealthier_household_can_afford_to_spend_more(): void
    {
        // Sensitivity is the whole point: if the search is not responsive to the household's means
        // it is not measuring anything. (A too-weak bar made every plan return the ceiling.)
        $poor = $this->solver()->forScenario($this->scenario($this->withWealth(BuilderStateFixture::full(), '60000')));
        $rich = $this->solver()->forScenario($this->scenario($this->withWealth(BuilderStateFixture::full(), '600000')));

        $this->assertNotNull($poor);
        $this->assertNotNull($rich);
        $this->assertGreaterThan(
            $poor['annual']->pence,
            $rich['annual']->pence,
            'ten times the savings must support a larger discretionary budget',
        );
    }

    public function test_the_answer_is_affordable_and_maximal(): void
    {
        // The threshold property: the plan holds at the figure, and breaks meaningfully above it.
        $scenario = $this->scenario($this->withWealth(BuilderStateFixture::full(), '300000'));
        $answer = $this->solver()->forScenario($scenario);

        $this->assertNotNull($answer);
        $this->assertFalse($answer['ceilingHit'], 'this household should have a real, found limit');

        $pounds = $answer['annual']->pence / 100;
        $this->assertTrue($this->holds($scenario, $pounds), 'the plan must hold AT the reported figure');
        $this->assertFalse(
            $this->holds($scenario, $pounds * 1.5 + 5_000),
            'the plan must break well above the reported figure',
        );
    }

    public function test_the_monthly_figure_is_the_annual_divided_once(): void
    {
        $answer = $this->solver()->forScenario($this->scenario($this->withWealth(BuilderStateFixture::full(), '300000')));

        $this->assertNotNull($answer);
        $this->assertSame(intdiv($answer['annual']->pence, 12), $answer['monthly']->pence);
    }

    public function test_a_plan_that_cannot_cover_essentials_returns_null_not_zero(): void
    {
        // No savings, no pension pot, and a large essential floor: the household cannot cover the
        // essentials however much it restrains discretionary spending. "Broken" is not "£0 spare".
        $state = BuilderStateFixture::full();
        $state['accounts'] = [];
        $state['pensions'] = array_values(array_filter(
            $state['pensions'],
            static fn (array $p): bool => ($p['subtype'] ?? '') === 'state',
        ));
        $state['expenseLines'] = [
            ['id' => 'e1', 'label' => 'Essentials', 'amount' => '90000', 'category' => 'essential', 'savedAsAsset' => false],
        ];

        $this->assertNull(
            $this->solver()->forScenario($this->scenario($state)),
            'a plan that fails on essentials alone has no allowance to report',
        );
    }

    /** Does the plan hold with $spend a year of discretionary spending? Mirrors the solver's bar. */
    private function holds(Scenario $scenario, float $spend): bool
    {
        $thresholds = app(LeverThresholdService::class);
        $forecast = $thresholds->deterministicForecastAt(
            $scenario,
            LeverKey::DiscretionarySpend,
            max(0.0, $spend),
        );

        return $forecast->depletionCalendarYear === null
            && $forecast->essentialsAlwaysMet
            && $forecast->fullSpendAlwaysMet;
    }
}
