<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\AdviceCostComparison;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuilderStateFixture;
use Tests\TestCase;

/**
 * "What would paying for advice cost this plan?" — the same projection run twice, once bearing the
 * charges it already bears and once with an adviser's ongoing fee on top.
 *
 * The properties that make it worth showing:
 *  1. the advised side differs from the DIY side by **exactly the advice fee** and nothing else —
 *     the honest construction, since the fee is the only part that can be benchmarked;
 *  2. it costs real money: more lifetime charges, less left at the end;
 *  3. the fee is **editable** and a scenario's own figure beats the benchmark;
 *  4. a household with nothing invested gets **null**, not a panel of zeroes;
 *  5. the comparison follows the housing strategy on display, like every other panel — a sell plan
 *     is priced as a seller.
 */
final class AdviceCostComparisonTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function scenario(array $overrides = []): Scenario
    {
        $user = User::factory()->create();
        $state = array_merge(BuilderStateFixture::full(), ['name' => 'Advice', 'baseTaxYear' => '2026-27', 'variant' => 'stay_put'], $overrides);

        $scenario = new Scenario;
        $scenario->user_id = $user->id;
        $scenario->builder_state = $state;
        $scenario->projectFrom($state);
        $scenario->save();

        return $scenario;
    }

    private function comparison(): AdviceCostComparison
    {
        return app(AdviceCostComparison::class);
    }

    public function test_the_advised_side_is_the_plans_own_charges_plus_the_fee_and_nothing_else(): void
    {
        // The construction is the point: the difference between the two runs IS the advice fee.
        // Building the advised total from an assumed fund uplift would invent the larger half.
        $result = $this->comparison()->forScenario($this->scenario());
        $this->assertNotNull($result);

        $this->assertEqualsWithDelta(
            $result['diyChargePct'] + $result['adviceFeePct'],
            $result['advisedChargePct'],
            0.0001,
        );
        $this->assertSame(config('advice.ongoing_fee_bp') / 100, $result['adviceFeePct']);
        $this->assertFalse($result['isCustomFee']);
    }

    public function test_advice_costs_real_money_over_a_lifetime(): void
    {
        $result = $this->comparison()->forScenario($this->scenario());

        $this->assertGreaterThan(
            $result['diy']['lifetimeCharges']->pence,
            $result['advised']['lifetimeCharges']->pence,
            'the advised run must bear more charges',
        );
        $this->assertTrue($result['extraLifetimeCost']->isPositive());
        $this->assertTrue($result['terminalWealthLost']->isPositive(), 'and leave less at the end');
        $this->assertSame(
            $result['advised']['lifetimeCharges']->pence - $result['diy']['lifetimeCharges']->pence,
            $result['extraLifetimeCost']->pence,
            'the headline cost is the difference between the two runs, not a third calculation',
        );
    }

    public function test_a_scenarios_own_quote_beats_the_benchmark(): void
    {
        // The benchmark is a starting figure; a real quote is better, which is why it is editable.
        $benchmark = $this->comparison()->forScenario($this->scenario());
        $quoted = $this->comparison()->forScenario($this->scenario(['adviceFeePct' => '1.5']));

        $this->assertSame(1.5, $quoted['adviceFeePct']);
        $this->assertTrue($quoted['isCustomFee']);
        $this->assertGreaterThan(
            $benchmark['extraLifetimeCost']->pence,
            $quoted['extraLifetimeCost']->pence,
            'a dearer adviser costs more',
        );
    }

    public function test_a_blank_fee_means_the_benchmark_not_no_fee(): void
    {
        // A blank box must never be read as "advice is free" — that would show a £0 cost of advice.
        $blank = $this->comparison()->forScenario($this->scenario(['adviceFeePct' => '']));

        $this->assertSame(config('advice.ongoing_fee_bp') / 100, $blank['adviceFeePct']);
        $this->assertFalse($blank['isCustomFee']);
        $this->assertTrue($blank['extraLifetimeCost']->isPositive());
    }

    public function test_a_household_with_nothing_invested_gets_no_panel(): void
    {
        // A percentage charge on nothing is nothing on both sides. "£0 either way" is noise, not
        // information, so the panel does not render at all.
        $state = BuilderStateFixture::full();
        $state['accounts'] = [];
        $state['pensions'] = array_values(array_filter(
            $state['pensions'],
            static fn (array $p): bool => ($p['subtype'] ?? '') !== 'dc',
        ));
        // No surplus to accumulate into a pot either: spend everything that comes in.
        $state['expenseLines'] = [
            ['id' => 'ess1', 'label' => 'Essentials', 'amount' => '200000', 'category' => 'essential', 'savedAsAsset' => false],
        ];

        $this->assertNull($this->comparison()->forScenario($this->scenario($state)));
    }

    public function test_the_comparison_follows_the_strategy_on_display(): void
    {
        // A sell-and-rent plan invests its proceeds, so it bears far more charges — and far more
        // advice fee — than the same household staying put. Reading a sell plan off the stay-put
        // path is a live trap in this codebase; this asserts the panel does not fall into it.
        $scenario = $this->scenario();

        $stayPut = $this->comparison()->forScenario($scenario, 'stay_put');
        $rent = $this->comparison()->forScenario($scenario, 'rent');

        $this->assertGreaterThan(
            $stayPut['extraLifetimeCost']->pence,
            $rent['extraLifetimeCost']->pence,
            'a plan that invests its house proceeds pays much more advice fee',
        );
    }
}
