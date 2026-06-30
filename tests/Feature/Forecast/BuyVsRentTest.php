<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\BuyVsRentCompare;
use App\Livewire\ScenarioCompare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuilderStateFixture;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * One-click "compare buy vs rent": generates the alternative housing strategies for a base
 * as ordinary delta-child what-ifs (variant-only overrides) and opens Compare. The
 * properties that matter: only the *meaningful* strategies are offered (buy needs a buy
 * price, rent needs a rent) and never the base's own; repeated clicks don't duplicate; the
 * endpoint is owner-scoped; and Compare projects each plan on ITS OWN strategy so the
 * columns actually differ.
 */
class BuyVsRentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_the_other_meaningful_strategies_as_variant_only_deltas(): void
    {
        // A stay-put base with both a buy price (£320k) and a rent (£18k) set → buy + rent are
        // offered, stay-put (its own strategy) is not.
        $base = ScenarioFixture::rich(User::factory()->create(), ['variant' => 'stay_put']);

        $children = BuyVsRentCompare::children($base);

        $this->assertEqualsCanonicalizing(['buy_outright', 'rent'], array_column($children, 'variant'));
        foreach ($children as $child) {
            $this->assertSame(['variant' => $child['variant']], $child['overrides']); // minimal, variant only
        }
    }

    public function test_it_offers_only_strategies_whose_inputs_are_present(): void
    {
        // A stay-put base with a buy price but no rent → only "buy" is meaningful.
        $state = BuilderStateFixture::minimalValid();
        $state['variant'] = 'stay_put';
        $state['housing']['buyPrice'] = '250000';
        $state['housing']['annualRent'] = '';
        $base = ScenarioFixture::fromState(User::factory()->create(), $state);

        $children = BuyVsRentCompare::children($base);

        $this->assertCount(1, $children);
        $this->assertSame('buy_outright', $children[0]['variant']);
    }

    public function test_the_endpoint_generates_the_children_and_opens_compare(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put']);

        $this->post(route('scenarios.compare.housing', $base))
            ->assertRedirect(route('scenarios.compare', $base));

        $names = $base->children()->pluck('name')->all();
        $this->assertContains('Buy somewhere cheaper', $names);
        $this->assertContains('Sell & rent', $names);

        $buy = $base->children()->where('variant', 'buy_outright')->firstOrFail();
        $this->assertSame(['name' => 'Buy somewhere cheaper', 'variant' => 'buy_outright'], $buy->overrides);
    }

    public function test_repeated_clicks_do_not_duplicate_the_strategy_children(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put']);

        $this->post(route('scenarios.compare.housing', $base));
        $this->post(route('scenarios.compare.housing', $base));

        $this->assertSame(2, $base->children()->count()); // buy + rent, not 4
    }

    public function test_compare_projects_each_plan_on_its_own_strategy(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $base = ScenarioFixture::rich($user, ['variant' => 'stay_put']);
        $this->post(route('scenarios.compare.housing', $base));

        $plans = collect(Livewire::test(ScenarioCompare::class, ['scenario' => $base])->viewData('plans'));

        // Stay-put (base) keeps the home; sell & rent frees the equity into usable wealth — so the
        // usable-wealth figures differ. Without per-variant projection both would show the raw
        // stay-put basis and be identical, so this pins the buy-vs-rent comparison's correctness.
        $stayPut = $plans->firstWhere('isBase', true);
        $rent = $plans->firstWhere('name', 'Sell & rent');
        $this->assertNotNull($rent);
        $this->assertNotSame($stayPut['usableWealth'], $rent['usableWealth']);
    }

    public function test_the_endpoint_is_owner_scoped(): void
    {
        $base = ScenarioFixture::rich(User::factory()->create(), ['variant' => 'stay_put']);
        $this->actingAs(User::factory()->create());

        $this->post(route('scenarios.compare.housing', $base))->assertForbidden();
        $this->assertSame(0, $base->children()->count());
    }
}
