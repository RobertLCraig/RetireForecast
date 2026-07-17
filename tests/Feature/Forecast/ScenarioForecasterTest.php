<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\ScenarioForecaster;
use App\Forecast\WithdrawalStrategyComparison;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuilderStateFixture;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

class ScenarioForecasterTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): Scenario
    {
        return ScenarioFixture::rich(User::factory()->create());
    }

    public function test_the_deterministic_forecast_runs_for_a_persisted_scenario(): void
    {
        $result = (new ScenarioForecaster)->deterministic($this->scenario());

        $this->assertNotEmpty($result->years);
        $this->assertGreaterThanOrEqual(2026, $result->finalCalendarYear);
    }

    public function test_the_withdrawal_strategy_comparison_prices_each_strategy_and_reconciles(): void
    {
        $comparison = WithdrawalStrategyComparison::for(new ScenarioForecaster, $this->scenario());

        // A rich household pays tax over the plan under either withdrawal strategy.
        $this->assertGreaterThan(0, $comparison->baselineTaxPence);
        $this->assertGreaterThan(0, $comparison->fillBandsTaxPence);
        // The saving is EXACTLY the difference of the two engine-computed lifetime-tax totals
        // (one figure, one home - never re-derived from something else).
        $this->assertSame($comparison->baselineTaxPence - $comparison->fillBandsTaxPence, $comparison->savingPence);
    }

    public function test_the_monte_carlo_run_records_its_seed_and_bounded_probabilities(): void
    {
        $result = (new ScenarioForecaster)->simulate($this->scenario(), nPaths: 50, seed: 7);

        $this->assertSame(50, $result->nPaths);
        $this->assertSame(7, $result->seed);
        $this->assertGreaterThanOrEqual(0.0, $result->successProbabilityEssentials);
        $this->assertLessThanOrEqual(1.0, $result->successProbabilityEssentials);
    }

    public function test_deterministic_variants_apply_the_housing_transforms_per_strategy(): void
    {
        $scenario = $this->scenario();
        $forecaster = new ScenarioForecaster;

        $variants = $forecaster->deterministicVariants($scenario);
        $this->assertSame(['stay_put', 'buy_outright', 'rent'], array_keys($variants));

        // stay_put is the raw household as entered — byte-identical to deterministic() (the
        // single source: variantInputs()['stay_put'] is the unchanged household + settings).
        $stay = $variants['stay_put'];
        $raw = $forecaster->deterministic($scenario);
        $this->assertSame($raw->finalCalendarYear, $stay->finalCalendarYear);
        $this->assertSame($raw->years[0]->totalWealth->pence, $stay->years[0]->totalWealth->pence);

        // Sell & rent owns no home: property wealth is zero in every year, so usable == total.
        $rent = $variants['rent'];
        foreach ($rent->years as $year) {
            $this->assertSame(0, $year->propertyWealth->pence, "rent kept property wealth in {$year->calendarYear}");
        }

        // Staying put keeps the home as an (illiquid) floor; selling frees its equity into
        // investments — so the sell variant carries more spendable (liquid) wealth from year 0.
        $this->assertGreaterThan(0, $stay->years[0]->propertyWealth->pence);
        $this->assertGreaterThan(
            $stay->years[0]->liquidWealth->pence,
            $rent->years[0]->liquidWealth->pence,
        );

        // Buying a cheaper home (£320k) leaves less in the home than staying put (£525k).
        $this->assertGreaterThan(0, $variants['buy_outright']->years[0]->propertyWealth->pence);
        $this->assertLessThan(
            $stay->years[0]->propertyWealth->pence,
            $variants['buy_outright']->years[0]->propertyWealth->pence,
        );
    }

    public function test_a_capital_receipt_reaches_the_forecast(): void
    {
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;

        // The rich fixture carries a £90k family gift in 2029; the same scenario without it
        // must be visibly poorer — a documented receipt that did not reach the result would
        // be a silent drop (per-source completeness).
        $with = $forecaster->deterministic(ScenarioFixture::rich($user));
        $without = $forecaster->deterministic(ScenarioFixture::rich($user, ['capitalReceipts' => []]));

        $withYears = [];
        foreach ($with->years as $year) {
            $withYears[$year->calendarYear] = $year;
        }
        // Visible on the ladder in its year (real money — ±1p inflation round-trip).
        $this->assertEqualsWithDelta(90_000_00, $withYears[2029]->incomeBySource['capital_receipt']->pence, 1);
        $this->assertSame(0, $withYears[2028]->incomeBySource['capital_receipt']->pence);

        $this->assertGreaterThan(
            $without->terminalTotalWealth->pence,
            $with->terminalTotalWealth->pence,
            'the banked receipt must leave the household visibly better off',
        );
    }

    public function test_savings_drawn_to_fund_a_buy_reach_the_forecast(): void
    {
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;

        // A £700k buy far exceeds the sale proceeds AND the fixture's £145k of liquid savings
        // (no buy mortgage configured): the savings are drained into the purchase and the
        // remainder is an unfunded gap that fails year 0 loudly — never free home equity.
        $variants = $forecaster->deterministicVariants(
            ScenarioFixture::rich($user, ['housing' => array_replace(
                BuilderStateFixture::full()['housing'], ['buyPrice' => '700000'],
            )]),
        );
        $buy = $variants['buy_outright'];
        $stay = $variants['stay_put'];

        $this->assertGreaterThan(0, $buy->years[0]->unmetSpend->pence, 'the unfunded gap surfaces as year-0 unmet spend');
        $this->assertLessThan(
            $stay->years[0]->liquidWealth->pence,
            $buy->years[0]->liquidWealth->pence,
            'the liquid savings were actually spent on the home',
        );
    }

    public function test_an_edited_assumption_reaches_the_forecast(): void
    {
        $user = User::factory()->create();
        $forecaster = new ScenarioForecaster;

        // The same household, once on the preset and once with the user's investment growth
        // cut to 0% real — the override must demonstrably change the forecast (completeness:
        // an edited assumption that did not reach the result would be a silent drop).
        $base = $forecaster->deterministic(ScenarioFixture::rich($user));
        $slowed = $forecaster->deterministic(
            ScenarioFixture::rich($user, ['assumptionOverrides' => ['investmentGrowth' => '0']]),
        );

        $this->assertLessThan(
            $base->terminalTotalWealth->pence,
            $slowed->terminalTotalWealth->pence,
            'Lower investment growth should leave less terminal wealth — the override did not reach the engine',
        );
    }

    public function test_the_assumption_set_carries_the_users_edits(): void
    {
        $user = User::factory()->create();
        $scenario = ScenarioFixture::rich($user, ['assumptionOverrides' => ['inflation' => '3.5']]);

        // The single resolution point hands the customised set to every consumer.
        $this->assertSame(350, (new ScenarioForecaster)->assumptions($scenario)->inflationMean->basisPoints);
    }

    public function test_buy_vs_rent_returns_all_three_variants_and_is_reproducible(): void
    {
        $scenario = $this->scenario();
        $forecaster = new ScenarioForecaster;

        $a = $forecaster->compareHousing($scenario, nPaths: 50, seed: 42);
        $b = $forecaster->compareHousing($scenario, nPaths: 50, seed: 42);

        $this->assertSame(['stay_put', 'buy_outright', 'rent'], array_keys($a));

        // Identical seed gives byte-identical aggregates (the golden-master property).
        foreach (array_keys($a) as $variant) {
            $this->assertSame(
                $a[$variant]->terminalWealthPercentiles['p50']->pence,
                $b[$variant]->terminalWealthPercentiles['p50']->pence,
                "Variant {$variant} was not reproducible",
            );
        }
    }

    public function test_a_forced_sale_scenario_carries_the_post_sale_rent_and_selling_costs_into_settings(): void
    {
        $user = User::factory()->create();

        // A home whose mortgage is called for redemption in 2030 and cannot be refinanced: the
        // projector sells it in place and the household rents, so the entered post-sale rent and
        // the selling-cost basis must reach the run settings — the projector has no HousingAction
        // to read them from, so a drop here would silently omit the rent from the forecast.
        $forced = ScenarioFixture::rich($user, [
            'variant' => 'stay_put',
            'property' => [
                'currentValue' => '525000', 'ownership' => 'mortgaged', 'everLet' => false,
                'outstandingMortgage' => '48000', 'runningCosts' => '6400', 'ownershipShare' => '100',
                'mortgageRedemptionYear' => '2030', 'mortgageMaturityAction' => 'forced_sale',
            ],
        ]);
        $settings = (new ScenarioForecaster)->settings($forced);
        $this->assertSame(1_800_000, $settings->annualRent?->pence, 'the £18k post-sale rent reaches the settings');
        $this->assertNotNull($settings->sellingCosts, 'the entered selling-cost basis reaches the settings');
        $this->assertNotNull($settings->rentInflationReal, 'rent inflation falls back to the assumption set');

        // A refinancing owner keeps the home, so no rent is charged (settings stay bare).
        $this->assertNull((new ScenarioForecaster)->settings(ScenarioFixture::rich($user))->annualRent);
    }
}
