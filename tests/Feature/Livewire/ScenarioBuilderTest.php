<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Forecast\ScenarioForecaster;
use App\Livewire\ScenarioBuilder;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\DisabilityAwardRate;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Forecast\AllocationProfile;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;
use RetireForecast\FinanceEngine\StatePension\StatePensionUprating;
use Tests\Support\BuilderStateFixture;
use Tests\Support\HouseholdFixture;
use Tests\TestCase;

class ScenarioBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_a_valid_minimal_forecast_saves_and_redirects_to_its_results(): void
    {
        $this->fill(BuilderStateFixture::minimalValid())->call('save');

        $this->assertSame(1, Scenario::count());
        $this->assertSame(ScenarioStatus::Ready, Scenario::firstOrFail()->status);
    }

    public function test_a_dc_annuity_purchase_round_trips_through_the_saved_scenario(): void
    {
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }

        $component->call('addPension', 'dc')
            ->set('pensions.0.currentValue', '200000')
            ->set('pensions.0.earliestAccessAge', '57')
            ->set('pensions.0.annuitise', true)
            ->set('pensions.0.annuityAmount', '120000')
            ->set('pensions.0.annuityAtAge', '66')
            ->set('pensions.0.annuityEscalation', 'rpi')
            ->set('pensions.0.annuityJoint', true)
            ->set('pensions.0.annuitySurvivorFraction', '50')
            // The reader's own quote goes in LAST, because changing the shape re-quotes the rate
            // (board card 0065). Typing it here is what proves an entered figure still wins.
            ->set('pensions.0.annuityRate', '7.2')
            ->call('save')
            ->assertHasNoErrors();

        $dc = null;
        foreach (Scenario::firstOrFail()->toHousehold()->pensions as $p) {
            if ($p instanceof DcPension && $p->annuityPurchase !== null) {
                $dc = $p;
                break;
            }
        }

        $this->assertNotNull($dc, 'the saved scenario should carry the DC annuity purchase');
        $this->assertSame(66, $dc->annuityPurchase->atAge);
        $this->assertSame(12_000_000, $dc->annuityPurchase->amount->pence);
        $this->assertSame(720, $dc->annuityPurchase->rate->basisPoints);
        $this->assertSame(PensionEscalationBasis::Rpi, $dc->annuityPurchase->escalation);
        $this->assertSame(5000, $dc->annuityPurchase->survivorFraction->basisPoints);
    }

    public function test_an_annuity_bought_from_a_non_pension_account_round_trips_through_the_saved_scenario(): void
    {
        // Board card 0060. The screen must be able to say "buy secured income with the cash", or a
        // household with no pension pot cannot express the one thing that removes a survivor's
        // longevity risk. Stored sparsely like the pot's, so an untouched account records nothing.
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }

        $component->call('addAccount')
            ->set('accounts.0.type', 'cash')
            ->set('accounts.0.balance', '150000')
            ->set('accounts.0.annuitise', true)
            ->set('accounts.0.annuityAmount', '100000')
            ->set('accounts.0.annuityAtAge', '68')
            ->set('accounts.0.annuityIncomeFromAge', '72')
            ->set('accounts.0.annuityEnhanced', true)
            ->set('accounts.0.annuityRate', '7.2')
            ->call('save')
            ->assertHasNoErrors();

        $account = Scenario::firstOrFail()->toHousehold()->accounts[0];
        $this->assertNotNull($account->annuityPurchase, 'the saved scenario should carry the account annuity');
        $this->assertSame(68, $account->annuityPurchase->atAge);
        $this->assertSame(72, $account->annuityPurchase->incomeStartAge());
        $this->assertSame(10_000_000, $account->annuityPurchase->amount->pence);
        $this->assertTrue($account->annuityPurchase->enhanced);
    }

    public function test_an_account_that_buys_no_annuity_stores_no_annuity_fields(): void
    {
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }
        $component->call('addAccount')
            ->set('accounts.0.balance', '150000')
            ->call('save')
            ->assertHasNoErrors();

        $account = Scenario::firstOrFail()->builder_state['accounts'][0];
        $this->assertArrayNotHasKey('annuitise', $account);
        $this->assertArrayNotHasKey('annuityAmount', $account);
        $this->assertArrayNotHasKey('annuityRate', $account);
    }

    public function test_the_care_cost_toggle_flows_through_to_the_forecast_settings(): void
    {
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }
        $component->set('modelCareCost', true)->call('save')->assertHasNoErrors();

        $scenario = Scenario::firstOrFail();
        $this->assertTrue($scenario->effectiveBuilderState()['modelCareCost']);
        $this->assertTrue(app(ScenarioForecaster::class)->settings($scenario)->modelCareCost);
    }

    public function test_the_iht_toggles_flow_through_and_home_to_descendants_stores_sparsely(): void
    {
        $save = function (callable $mutate) {
            $component = Livewire::test(ScenarioBuilder::class);
            foreach (BuilderStateFixture::minimalValid() as $key => $value) {
                $component->set($key, $value);
            }
            $mutate($component);
            $component->call('save')->assertHasNoErrors();

            return Scenario::latest('id')->firstOrFail();
        };

        // IHT on, home left to descendants (the default): the toggle reaches the forecast settings,
        // and the default-on flag is stored sparsely (absent = on), so no spurious what-if delta.
        $on = $save(fn ($c) => $c->set('ihtModelled', true));
        $this->assertTrue(app(ScenarioForecaster::class)->settings($on)->modelIht);
        $this->assertTrue(app(ScenarioForecaster::class)->settings($on)->homeToDescendants);
        $this->assertArrayNotHasKey('homeToDescendants', $on->effectiveBuilderState());

        // Home NOT left to descendants: the off value is stored and read back off.
        $off = $save(fn ($c) => $c->set('ihtModelled', true)->set('homeToDescendants', false));
        $this->assertFalse($off->effectiveBuilderState()['homeToDescendants']);
        $this->assertFalse(app(ScenarioForecaster::class)->settings($off)->homeToDescendants);
    }

    /**
     * Board card 0057. An unused pension pot left on a death at or after 75 is taxed twice, and
     * both halves of the second charge were unreachable from the form: the beneficiary's assumed
     * tax rate, and who each pot is actually nominated to (which decides the spouse exemption on
     * it, because a pension is not covered by the will).
     */
    public function test_the_beneficiary_tax_rate_and_pension_nomination_reach_the_forecast(): void
    {
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }
        $component
            ->set('ihtModelled', true)
            ->set('beneficiaryTaxRate', '20')
            ->call('addPension', 'dc')
            ->set('pensions.'.(count($component->get('pensions')) - 1).'.currentValue', '150000')
            ->set('pensions.'.(count($component->get('pensions')) - 1).'.nominatedBeneficiary', 'spouse_or_civil_partner')
            ->call('save')
            ->assertHasNoErrors();

        $scenario = Scenario::latest('id')->firstOrFail();
        $this->assertSame(
            2000,
            app(ScenarioForecaster::class)->settings($scenario)->beneficiaryMarginalRate?->basisPoints,
        );

        $nominated = null;
        foreach ($scenario->toHousehold()->pensions as $p) {
            if ($p instanceof DcPension) {
                $nominated = $p;
            }
        }
        $this->assertNotNull($nominated);
        $this->assertTrue($nominated->nominatedToSpouse());
        $this->assertFalse($nominated->nominationIsAssumed());
    }

    public function test_an_unset_beneficiary_rate_stores_nothing_and_leaves_the_engine_default(): void
    {
        // Sparse, like every other default-following field: a scenario that never touched it must
        // record no key, so a what-if child shows no spurious delta and the engine's own adverse
        // default stays in force.
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }
        $component->set('ihtModelled', true)->call('save')->assertHasNoErrors();

        $scenario = Scenario::latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('beneficiaryTaxRate', $scenario->effectiveBuilderState());
        $this->assertNull(app(ScenarioForecaster::class)->settings($scenario)->beneficiaryMarginalRate);
    }

    /**
     * Board card 0038. The triple lock was assumed to survive the whole plan with no source, no
     * setting and no control, which is the optimistic branch of contested policy chosen silently.
     * The reader now picks one of three futures, and the picked one has to reach the settings the
     * projection runs on, not just the form.
     */
    public function test_the_state_pension_uprating_choice_reaches_the_forecast_settings(): void
    {
        $save = function (array $overrides): Scenario {
            $component = Livewire::test(ScenarioBuilder::class);
            foreach (BuilderStateFixture::minimalValid() as $key => $value) {
                $component->set($key, $value);
            }
            foreach ($overrides as $key => $value) {
                $component->set("assumptionOverrides.{$key}", $value);
            }
            $component->call('save')->assertHasNoErrors();

            return Scenario::latest('id')->firstOrFail();
        };

        // Untouched: stored sparsely (absent = the engine's default), so a scenario predating the
        // control and a what-if that changes nothing record no delta for it.
        $default = $save([]);
        $this->assertArrayNotHasKey('assumptionOverrides', $default->effectiveBuilderState());
        $settings = app(ScenarioForecaster::class)->settings($default);
        $this->assertSame(StatePensionUprating::TripleLock, $settings->statePensionUprating);
        $this->assertTrue($settings->statePensionUpratingIsAssumed(), 'an untouched choice is the engine\'s, and has to disclose itself');

        // The middle choice carries its year through to the projection.
        $until = app(ScenarioForecaster::class)->settings($save([
            'statePensionUprating' => 'triple_lock_until',
            'statePensionUpratingUntilYear' => '2035',
        ]));
        $this->assertSame(StatePensionUprating::TripleLockUntil, $until->statePensionUprating);
        $this->assertSame(2035, $until->tripleLockUntilYear);
        $this->assertFalse($until->statePensionUpratingIsAssumed());

        // And prices alone, which is the adverse branch and the one nobody could ask for before.
        $this->assertSame(
            StatePensionUprating::Inflation,
            app(ScenarioForecaster::class)->settings($save(['statePensionUprating' => 'inflation']))->statePensionUprating,
        );
    }

    public function test_the_state_pension_uprating_control_offers_all_three_choices(): void
    {
        // The floor on the label is READ from the enum that owns it, so re-sourcing the figure
        // moves the screen with it rather than leaving a number the projection is not using.
        $floor = rtrim(rtrim(number_format(StatePensionUprating::floor()->asPercent(), 2), '0'), '.');

        Livewire::test(ScenarioBuilder::class)
            ->set('step', 1)
            ->assertSee("{$floor}% floor for ever")
            ->assertSee('Lasts until a year I choose, then rises with prices only')
            ->assertSee('the State Pension rises with prices only')
            // The end-year box only exists for the choice that needs one, so a reader on the full
            // lock is not shown a year that would change nothing.
            ->assertDontSee('Last year the lock applies')
            ->set('assumptionOverrides.statePensionUprating', 'triple_lock_until')
            ->assertSee('Last year the lock applies');
    }

    /**
     * Board card 0061. The plan ran to each person's own median age at death — a coin flip — and
     * there was no control at all. The three named percentiles have to be offered, and the one
     * picked has to reach the settings the projection runs on.
     */
    public function test_the_planning_horizon_control_offers_the_three_named_percentiles(): void
    {
        $component = Livewire::test(ScenarioBuilder::class)->set('step', 1);

        foreach (PlanningHorizon::cases() as $case) {
            $component->assertSee($case->label());
        }
    }

    public function test_the_planning_horizon_choice_reaches_the_forecast_settings(): void
    {
        $save = function (array $overrides): Scenario {
            $component = Livewire::test(ScenarioBuilder::class);
            foreach (BuilderStateFixture::minimalValid() as $key => $value) {
                $component->set($key, $value);
            }
            foreach ($overrides as $key => $value) {
                $component->set("assumptionOverrides.{$key}", $value);
            }
            $component->call('save')->assertHasNoErrors();

            return Scenario::latest('id')->firstOrFail();
        };

        // Untouched: stored sparsely, and the engine's own cautious default applies — which is a
        // figure the reader never chose, so it has to disclose itself.
        $default = $save([]);
        $this->assertArrayNotHasKey('assumptionOverrides', $default->effectiveBuilderState());
        $settings = app(ScenarioForecaster::class)->settings($default);
        $this->assertSame(PlanningHorizon::DEFAULT, $settings->planningHorizon);
        $this->assertTrue($settings->planningHorizonIsAssumed());

        // And a chosen percentile carries through.
        $chosen = app(ScenarioForecaster::class)->settings($save(['planningHorizon' => 'p50']));
        $this->assertSame(PlanningHorizon::P50, $chosen->planningHorizon);
        $this->assertFalse($chosen->planningHorizonIsAssumed());
    }

    /**
     * Board card 0075. The draw order is one of the biggest levers on lifetime tax the tool has,
     * and the results page prices three of them and names the cheapest — but every forecast ran on
     * one order chosen in code, which the reader could neither see nor change. So the tool could
     * say a different order saves thousands and offer no way to model it.
     */
    public function test_the_builder_stores_a_chosen_draw_order(): void
    {
        $save = function (array $overrides): Scenario {
            $component = Livewire::test(ScenarioBuilder::class);
            foreach (BuilderStateFixture::minimalValid() as $key => $value) {
                $component->set($key, $value);
            }
            foreach ($overrides as $key => $value) {
                $component->set("assumptionOverrides.{$key}", $value);
            }
            $component->call('save')->assertHasNoErrors();

            return Scenario::latest('id')->firstOrFail();
        };

        // Every order the engine supports is offered by name.
        $component = Livewire::test(ScenarioBuilder::class)->set('step', 1);
        foreach (DrawdownStrategy::cases() as $case) {
            $component->assertSee(ucfirst($case->label()));
        }

        // Untouched: stored sparsely, and the engine's own order applies — a figure the reader
        // never chose, so it has to disclose itself.
        $default = $save([]);
        $this->assertArrayNotHasKey('assumptionOverrides', $default->effectiveBuilderState());
        $settings = app(ScenarioForecaster::class)->settings($default);
        $this->assertSame(DrawdownStrategy::DEFAULT, $settings->drawdownStrategy);
        $this->assertTrue($settings->drawdownStrategyIsAssumed());

        // And a chosen order carries through to the settings the projection runs on.
        foreach ([DrawdownStrategy::PensionAware, DrawdownStrategy::FillBands] as $chosen) {
            $settings = app(ScenarioForecaster::class)->settings($save(['drawdownStrategy' => $chosen->value]));
            $this->assertSame($chosen, $settings->drawdownStrategy);
            $this->assertFalse($settings->drawdownStrategyIsAssumed());
        }
    }

    /**
     * Board card 0062, criteria 1 and 3. The asset mix was hardcoded: the engine fell back to a
     * cautious 40/60 and no caller ever passed anything else, so the single largest determinant
     * of the answer was the one thing the household could not say. It has to be on the screen,
     * it has to default to the mix every stored scenario ran on, and a de-risking glidepath has
     * to be offered.
     */
    public function test_the_asset_mix_and_its_glidepath_are_offered_and_reach_the_forecast_settings(): void
    {
        $component = Livewire::test(ScenarioBuilder::class)->set('step', 1);
        foreach (AllocationProfile::cases() as $case) {
            $component->assertSee($case->label());
        }

        $save = function (array $overrides): Scenario {
            $component = Livewire::test(ScenarioBuilder::class);
            foreach (BuilderStateFixture::minimalValid() as $key => $value) {
                $component->set($key, $value);
            }
            foreach ($overrides as $key => $value) {
                $component->set("assumptionOverrides.{$key}", $value);
            }
            $component->call('save')->assertHasNoErrors();

            return Scenario::latest('id')->firstOrFail();
        };

        // Untouched: the engine's own cautious mix, still reported as a figure the reader did
        // not choose, so the no-invisible-figures disclosure keeps firing.
        $settings = app(ScenarioForecaster::class)->settings($save([]));
        $this->assertSame(PortfolioAllocation::cautious40_60()->weights, $settings->allocation()->weights);
        $this->assertTrue($settings->allocationIsAssumed());
        $this->assertFalse($settings->allocation()->glides());

        // A chosen mix reaches the projection and stops being ours.
        $chosen = app(ScenarioForecaster::class)->settings($save(['allocation' => 'balanced']));
        $this->assertSame(AllocationProfile::Balanced->allocation()->weights, $chosen->allocation()->weights);
        $this->assertFalse($chosen->allocationIsAssumed());

        // ...and so does a glidepath, which de-risks the mix as the plan runs on.
        $gliding = app(ScenarioForecaster::class)->settings(
            $save(['allocation' => 'balanced', 'allocationGlideTo' => 'defensive', 'allocationGlideYears' => '15']),
        )->allocation();
        $this->assertTrue($gliding->glides());
        $this->assertSame(AllocationProfile::Balanced->allocation()->weights, $gliding->at(0)->weights);
        $this->assertEqualsWithDelta(
            AllocationProfile::Defensive->allocation()->weights[0],
            $gliding->at(15)->weights[0],
            1e-9,
        );
    }

    /**
     * Board card 0062, criterion 2. Raising "investment growth" used to shift every asset class's
     * expected return and leave the volatilities and correlations exactly where they were, so a
     * reader could buy an equity return at a cautious portfolio's risk. A target no mix of these
     * asset classes can reach is now refused at the point of entry rather than manufactured.
     */
    public function test_an_investment_growth_target_no_mix_can_reach_is_refused(): void
    {
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }

        // The default set's best asset class returns 4.4% real, so 7% is unbuyable at any risk.
        $component->set('assumptionOverrides.investmentGrowth', '7')
            ->call('save')
            ->assertHasErrors('assumptionOverrides.investmentGrowth');

        // A reachable one is accepted, and it lands on the target by RE-WEIGHTING the mix, which
        // is what makes the risk move with it.
        $component->set('assumptionOverrides.investmentGrowth', '3')->call('save')->assertHasNoErrors();

        $forecaster = app(ScenarioForecaster::class);
        $scenario = Scenario::latest('id')->firstOrFail();
        $allocation = $forecaster->settings($scenario)->allocation();
        $set = $forecaster->assumptions($scenario);

        $this->assertEqualsWithDelta(0.03, $allocation->blendedRealReturn($set), 1e-6);
        $this->assertGreaterThan(
            PortfolioAllocation::cautious40_60()->blendedVolatility($set),
            $allocation->blendedVolatility($set),
        );
        // The asset classes themselves are untouched: no return was invented.
        $this->assertSame(
            AssumptionSetLibrary::default()->assetClasses[0]->expectedRealReturn->basisPoints,
            $set->assetClasses[0]->expectedRealReturn->basisPoints,
        );
    }

    public function test_a_state_pension_uprating_end_year_outside_the_modelled_range_is_rejected(): void
    {
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }

        $component->set('assumptionOverrides.statePensionUprating', 'triple_lock_until')
            ->set('assumptionOverrides.statePensionUpratingUntilYear', '35')
            ->call('save')
            ->assertHasErrors('assumptionOverrides.statePensionUpratingUntilYear');
    }

    public function test_an_unannuitised_pot_stores_no_annuity_fields(): void
    {
        // Sparse storage: a DC pot with the toggle off records none of the annuity keys, so a
        // scenario predating the feature — and a what-if that changes nothing — shows no delta.
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }
        $component->call('addPension', 'dc')
            ->set('pensions.0.currentValue', '200000')
            ->set('pensions.0.earliestAccessAge', '57')
            ->call('save')
            ->assertHasNoErrors();

        $pension = Scenario::firstOrFail()->builder_state['pensions'][0];
        $this->assertArrayNotHasKey('annuitise', $pension);
        $this->assertArrayNotHasKey('annuityAmount', $pension);
        $this->assertArrayNotHasKey('annuityRate', $pension);
    }

    public function test_the_property_cost_growth_input_offers_its_sourced_alternatives(): void
    {
        // Card 0028: the rate has to be a figure the reader can see, challenge and change, so the
        // input states the default that applies when it is blank AND the optimistic alternative,
        // with where both came from. The default is READ from the constant that owns it, so the
        // screen cannot drift from what the projection charges.
        $default = ScenarioBuilder::propertyCostsGrowthDefaultPct();

        Livewire::test(ScenarioBuilder::class)
            ->set('step', 4)
            ->assertSee("{$default}% a year above inflation")
            ->assertSee('1.5%')
            ->assertSee('expert property review of 2026-08-19');
    }

    public function test_a_one_off_cost_can_be_tied_to_owning_the_home(): void
    {
        // Card 0028: a Section 20 major-works demand is a liability of owning the flat. The builder
        // has to be able to say so, store it, and land it on the engine profile. Otherwise the
        // only shape a lumpy property bill can take is one that follows the household after a sale.
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }
        $component->call('addOneOff')
            ->set('oneOffCosts.0.atAge', '78')
            ->set('oneOffCosts.0.amount', '15000')
            ->set('oneOffCosts.0.label', 'Section 20 major works')
            ->set('oneOffCosts.0.condition', 'while_owning_home')
            ->call('save')
            ->assertHasNoErrors();

        $scenario = Scenario::latest('id')->firstOrFail();
        $this->assertSame('while_owning_home', $scenario->builder_state['oneOffCosts'][0]['condition']);
        $this->assertSame(
            'while_owning_home',
            $scenario->toHousehold()->expenseProfile->oneOffCosts[0]['condition'] ?? null,
            'the marker must reach the engine, or the cost is charged after the home is sold',
        );
    }

    public function test_an_ordinary_one_off_cost_stores_no_condition(): void
    {
        // Sparse, like the growth rate: an unmarked one-off is charged always, so it records no
        // key, so a base saved before the field and a what-if that changes nothing carry no delta.
        $component = Livewire::test(ScenarioBuilder::class);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }
        $component->call('addOneOff')
            ->set('oneOffCosts.0.atAge', '80')
            ->set('oneOffCosts.0.amount', '5000')
            ->set('oneOffCosts.0.label', 'New car')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertArrayNotHasKey('condition', Scenario::latest('id')->firstOrFail()->builder_state['oneOffCosts'][0]);
    }

    public function test_property_costs_growth_stores_sparsely_and_reaches_the_profile(): void
    {
        $save = function (callable $mutate) {
            $component = Livewire::test(ScenarioBuilder::class);
            foreach (BuilderStateFixture::minimalValid() as $key => $value) {
                $component->set($key, $value);
            }
            $mutate($component);
            $component->call('save')->assertHasNoErrors();

            return Scenario::latest('id')->firstOrFail();
        };

        // Blank (the default): no key stored, so a scenario predating the field — and a
        // what-if that changes nothing — records no spurious delta.
        $blank = $save(fn ($c) => $c);
        $this->assertArrayNotHasKey('propertyCostsGrowthPct', $blank->effectiveBuilderState()['expense'] ?? []);
        $this->assertNull($blank->toHousehold()->expenseProfile->propertyCostsRealGrowth);

        // Set: stored, and demonstrably reaches the engine profile (completeness).
        $set = $save(fn ($c) => $c->set('expense.propertyCostsGrowthPct', '1.5'));
        $this->assertSame('1.5', $set->effectiveBuilderState()['expense']['propertyCostsGrowthPct']);
        $this->assertSame(150, $set->toHousehold()->expenseProfile->propertyCostsRealGrowth?->basisPoints);
    }

    public function test_the_spending_guardrail_is_off_by_default_and_its_two_figures_are_editable(): void
    {
        // Board card 0063. The guardrail is opt-in (it only ever makes a plan look better), and
        // when it is on both of its figures are the reader's to set, with the engine's disclosed
        // default behind a blank one. Storage is sparse, so nothing predating the field moves.
        $save = function (callable $mutate) {
            $component = Livewire::test(ScenarioBuilder::class);
            foreach (BuilderStateFixture::minimalValid() as $key => $value) {
                $component->set($key, $value);
            }
            $mutate($component);
            $component->call('save')->assertHasNoErrors();

            return Scenario::latest('id')->firstOrFail();
        };

        // The controls are on the spending step, or there is nothing for a reader to edit.
        Livewire::test(ScenarioBuilder::class)
            ->set('step', 4)
            ->assertSee('Cut back if the plan falls short')
            ->set('expense.guardrailOn', true)
            ->assertSeeHtml('wire:model="expense.guardrailTriggerRatio"')
            ->assertSeeHtml('wire:model="expense.guardrailCutPct"');

        $off = $save(fn ($c) => $c);
        $this->assertArrayNotHasKey('guardrailOn', $off->effectiveBuilderState()['expense'] ?? []);
        $this->assertNull($off->toHousehold()->expenseProfile->spendingGuardrail);

        // On, with nothing typed: the guardrail runs on the engine's own two figures.
        $blank = $save(fn ($c) => $c->set('expense.guardrailOn', true));
        $guardrail = $blank->toHousehold()->expenseProfile->spendingGuardrail;
        $this->assertNotNull($guardrail);
        $this->assertTrue($guardrail->triggerIsAssumed());
        $this->assertTrue($guardrail->cutIsAssumed());

        // On, with both typed: what the reader entered is what the engine runs.
        $set = $save(fn ($c) => $c
            ->set('expense.guardrailOn', true)
            ->set('expense.guardrailTriggerRatio', '1.25')
            ->set('expense.guardrailCutPct', '20'));
        $entered = $set->toHousehold()->expenseProfile->spendingGuardrail;
        $this->assertSame(12_500, $entered?->triggerFundedRatio()->basisPoints);
        $this->assertSame(2_000, $entered?->discretionaryCut()->basisPoints);
    }

    public function test_adding_a_state_pension_defaults_to_the_full_rate_and_renders_the_level_picker(): void
    {
        Livewire::test(ScenarioBuilder::class)
            ->call('addPension', 'state')
            ->assertSet('pensions.0.level', 'full')
            ->assertSet('pensions.0.weeklyForecast', '241.30') // full new State Pension, 2026-27
            ->set('step', 2)
            ->assertSee('Full new State Pension')
            ->assertSee('gov.uk/check-state-pension');
    }

    public function test_choosing_the_qualifying_years_level_clears_the_weekly_amount(): void
    {
        Livewire::test(ScenarioBuilder::class)
            ->call('addPension', 'state')
            ->set('pensions.0.level', 'years')
            ->assertSet('pensions.0.weeklyForecast', '');
    }

    public function test_a_person_name_is_persisted_to_the_saved_household(): void
    {
        $state = BuilderStateFixture::minimalValid();
        $state['people'][0]['name'] = 'Alex';

        $component = Livewire::test(ScenarioBuilder::class);
        foreach ($state as $key => $value) {
            $component->set($key, $value);
        }
        $component->call('save');

        $this->assertSame('Alex', Scenario::firstOrFail()->toHousehold()->persons[0]->name);
    }

    public function test_a_lifespan_what_if_is_persisted_to_the_saved_household(): void
    {
        $state = BuilderStateFixture::minimalValid();
        $state['people'][0]['longevityMode'] = 'fixed_age';
        $state['people'][0]['longevityValue'] = '88';

        $this->fill($state)->call('save')->assertHasNoErrors();

        $this->assertEquals(
            LongevityAdjustment::fixedAge(88),
            Scenario::firstOrFail()->toHousehold()->persons[0]->longevity,
        );
    }

    public function test_a_lifespan_what_if_requires_a_value_when_it_is_not_peer(): void
    {
        $state = BuilderStateFixture::minimalValid();
        $state['people'][0]['longevityMode'] = 'fixed_age';
        $state['people'][0]['longevityValue'] = '';

        $this->fill($state)->call('save')->assertHasErrors('people.0.longevityValue');

        $this->assertSame(0, Scenario::count());
    }

    public function test_a_saved_scenario_decrypts_to_the_identical_dto(): void
    {
        $state = BuilderStateFixture::full();
        $state['name'] = 'Buy-vs-rent';
        $state['variant'] = 'rent';
        $state['baseTaxYear'] = '2026-27';

        $this->fill($state)->call('save')
            ->assertRedirect(route('scenarios.results', Scenario::firstOrFail()));

        $scenario = Scenario::firstOrFail();
        $this->assertEquals(HouseholdFixture::household(), $scenario->toHousehold());
        $this->assertEquals(HouseholdFixture::housingAction(), $scenario->toHousingAction());
    }

    public function test_salary_is_required_when_a_person_is_employed(): void
    {
        $state = BuilderStateFixture::minimalValid();
        $state['people'][0]['employmentStatus'] = 'employed';
        $state['people'][0]['grossSalary'] = '';

        $this->fill($state)->call('save')->assertHasErrors('people.0.grossSalary');

        $this->assertSame(0, Scenario::count());
    }

    public function test_salary_is_not_required_when_a_person_is_retired(): void
    {
        $this->fill(BuilderStateFixture::minimalValid())
            ->call('save')
            ->assertHasNoErrors('people.0.grossSalary');
    }

    public function test_negative_money_is_rejected(): void
    {
        $state = BuilderStateFixture::minimalValid();
        $state['housing']['salePrice'] = '-100';

        $this->fill($state)->call('save')->assertHasErrors('housing.salePrice');

        $this->assertSame(0, Scenario::count());
    }

    public function test_more_than_two_decimal_places_of_money_is_rejected(): void
    {
        $state = BuilderStateFixture::minimalValid();
        $state['expenseLines'][0]['amount'] = '20000.123';

        $this->fill($state)->call('save')->assertHasErrors('expenseLines.0.amount');
    }

    public function test_scotland_is_refused_until_its_tax_bands_are_loaded(): void
    {
        $state = BuilderStateFixture::minimalValid();
        $state['region'] = 'scotland';

        $this->fill($state)->call('save')->assertHasErrors('region');

        $this->assertSame(0, Scenario::count());
    }

    public function test_the_builder_screen_renders_for_a_signed_in_user(): void
    {
        $this->get(route('scenarios.create'))->assertOk()->assertSee('New forecast');
    }

    public function test_the_wizard_starts_on_the_first_step_and_navigates_freely(): void
    {
        Livewire::test(ScenarioBuilder::class)
            ->assertSet('step', 1)
            ->call('nextStep')->assertSet('step', 2)
            ->call('goToStep', 5)->assertSet('step', 5)
            ->call('nextStep')->assertSet('step', 5)      // clamps at the last step
            ->call('goToStep', 99)->assertSet('step', 5)
            ->call('prevStep')->assertSet('step', 4)
            ->call('goToStep', -3)->assertSet('step', 1); // clamps at the first step
    }

    public function test_the_net_worth_step_groups_savings_and_the_home(): void
    {
        Livewire::test(ScenarioBuilder::class)
            ->set('step', 3)
            ->assertSee('Your net worth')
            ->assertSee('Current home');
    }

    public function test_a_failed_save_lands_on_the_first_step_with_an_error(): void
    {
        // A problem in the last step (the sale price) should pull the user back to it.
        $state = BuilderStateFixture::minimalValid();
        $state['housing']['salePrice'] = '';

        $this->fill($state)->call('save')
            ->assertHasErrors('housing.salePrice')
            ->assertSet('step', 5);
    }

    public function test_income_end_age_cannot_precede_start_age(): void
    {
        $state = BuilderStateFixture::full();
        $state['name'] = 'X';
        $state['incomeStreams'][0]['endAge'] = '50'; // start age is 60

        $this->fill($state)->call('save')->assertHasErrors('incomeStreams.0.endAge');

        $this->assertSame(0, Scenario::count());
    }

    public function test_a_disability_award_can_be_entered_as_separate_care_and_mobility_components(): void
    {
        // Board card 0050. Both rates are normally known separately, and the two are treated
        // differently in a care financial assessment, so the builder must accept a mobility row
        // and carry it through to the engine household.
        $state = BuilderStateFixture::full();
        $state['name'] = 'Split award';
        $state['incomeStreams'][] = [
            'id' => 'dla-care', 'ownerId' => 'p1', 'type' => 'disability_benefit',
            'grossAnnual' => '110.40', 'frequency' => 'weekly', 'inflationLinked' => true, 'startAge' => '60',
        ];
        $state['incomeStreams'][] = [
            'id' => 'dla-mobility', 'ownerId' => 'p1', 'type' => 'disability_benefit_mobility',
            'grossAnnual' => '77.05', 'frequency' => 'weekly', 'inflationLinked' => true, 'startAge' => '60',
        ];

        $this->fill($state)->call('save')->assertHasNoErrors();

        $types = array_map(
            static fn ($s): string => $s->type->value,
            Scenario::firstOrFail()->toHousehold()->incomeStreams,
        );
        $this->assertContains('disability_benefit', $types);
        $this->assertContains('disability_benefit_mobility', $types);
    }

    public function test_an_income_note_persists_as_a_visual_aid_without_reaching_the_engine(): void
    {
        // The user can label each income stream with what it is and where it's from. It is a
        // pure visual aid: it must survive a save (round-trips through builder_state) yet never
        // reach an engine figure (HouseholdAssembler reads named keys only, so it is ignored).
        $state = BuilderStateFixture::full();
        $state['name'] = 'With a note';
        $state['incomeStreams'][0]['note'] = 'Aviva annuity, from the old works pension';

        $this->fill($state)->call('save')->assertHasNoErrors();

        $scenario = Scenario::firstOrFail();
        $this->assertSame(
            'Aviva annuity, from the old works pension',
            $scenario->builder_state['incomeStreams'][0]['note'],
        );

        // The assembled engine household carries no trace of the note — the IncomeStream DTO
        // has no such field, so it cannot affect any forecast figure.
        $stream = $scenario->toHousehold()->incomeStreams[0];
        $this->assertObjectNotHasProperty('note', $stream);
    }

    public function test_only_a_cost_that_dies_with_the_home_is_asked_what_it_buys_in_utilities(): void
    {
        // Card 0033. The figure is only meaningful on a while-owning-home cost, because that is the
        // only one a sale strips. Offering it elsewhere would collect a number the assembler ignores;
        // NOT offering it on the service charge would make the engine's carry-across unreachable.
        $state = BuilderStateFixture::full();
        $state['step'] = 4;
        $state['expenseLines'][] = ['id' => 'sc1', 'label' => 'Service charge', 'amount' => '4000', 'category' => 'essential', 'savedAsAsset' => false];

        $this->fill($state)
            ->assertSeeHtml('expenseLines-2-utilities')
            ->assertDontSeeHtml('expenseLines-0-utilities');
    }

    public function test_the_utilities_inside_a_service_charge_survive_a_save_and_reach_the_engine(): void
    {
        // The figure has to round-trip through builder_state and land on the engine profile, or the
        // sell variants go on deleting the water and the electricity with the charge.
        $state = BuilderStateFixture::full();
        $state['name'] = 'With a service charge';
        $state['expenseLines'][] = ['id' => 'sc1', 'label' => 'Service charge', 'amount' => '4000', 'category' => 'essential', 'savedAsAsset' => false, 'utilities' => '1500'];

        $this->fill($state)->call('save')->assertHasNoErrors();

        $profile = Scenario::firstOrFail()->toHousehold()->expenseProfile;
        $this->assertSame(150_000, $profile->propertyCostsUtilities()->pence);
        // A line with no figure stores none, so a scenario predating the field records no delta.
        $this->assertArrayNotHasKey('utilities', Scenario::firstOrFail()->builder_state['expenseLines'][0]);
    }

    public function test_a_complete_forecast_shows_a_live_deterministic_preview(): void
    {
        // A forecastable set of inputs renders the verdict + end-wealth readout (one cheap
        // deterministic path), so the user sees the effect of an edit before the full run.
        $this->fill(BuilderStateFixture::full())
            ->assertSee('Live preview')
            ->assertSee('On these figures, the money') // the verdict line (lasts / runs short)
            ->assertSee('Spendable at end (excl. home)')
            ->assertSee('Total wealth at end');
    }

    public function test_the_lifespan_lever_shows_the_modelled_age_at_death(): void
    {
        // The plan's "surface the lever and show the resulting age": each person's modelled
        // death age/year (from the same deterministic forecast as the preview) shows on step 1.
        $this->fill(BuilderStateFixture::full())
            ->assertSet('step', 1)
            ->assertSee('is modelled to live to');
    }

    public function test_the_live_preview_invites_completion_while_the_inputs_are_incomplete(): void
    {
        // A half-filled wizard (no valid people yet) cannot be forecast: that is not an error,
        // so the panel asks the user to finish rather than showing a misleading figure.
        Livewire::test(ScenarioBuilder::class)
            ->assertSee('Live preview')
            ->assertSee('Fill in the required fields')
            ->assertDontSee('On these figures, the money');
    }

    public function test_the_housing_step_renders_editable_selling_cost_components_with_defaults(): void
    {
        Livewire::test(ScenarioBuilder::class)
            ->set('step', 5)
            ->assertSee('Selling costs')
            ->assertSee('Estate agent')
            ->assertSee('Legal / conveyancing')
            ->assertSee('Removals')
            // The default estate-agent line is a % of the sale; the flat fees are £.
            ->assertSet('housing.sellingCosts.estate_agent.value', '1.5')
            ->assertSet('housing.sellingCosts.estate_agent.basis', 'percent')
            ->assertSet('housing.sellingCosts.legal.basis', 'fixed');
    }

    public function test_the_housing_step_itemises_the_leasehold_sale_fees_separately_from_conveyancing(): void
    {
        // Card 0032. Selling a leasehold flat costs three things a freehold house sale does not:
        // the managing agent's management pack (mandatory — the buyer's solicitor cannot exchange
        // without it), the licence to assign, and the notice-of-transfer / deed-of-covenant fees.
        // None of them existed, and the conveyancing line beside them was priced for a freehold
        // sale, so every sell plan kept money it would never actually see.
        Livewire::test(ScenarioBuilder::class)
            ->set('step', 5)
            ->assertSee('Management pack')
            ->assertSee('Licence to assign, notices &amp; deed of covenant', escape: false)
            // Each leasehold fee is its own editable line, quoted as a flat fee like the real bill.
            ->assertSet('housing.sellingCosts.management_pack.basis', 'fixed')
            ->assertSet('housing.sellingCosts.management_pack.value', '500')
            ->assertSet('housing.sellingCosts.licence_to_assign.basis', 'fixed')
            ->assertSet('housing.sellingCosts.licence_to_assign.value', '700')
            // Conveyancing keeps its own separate line, priced for a leasehold sale, and removals
            // are no longer bundled with an £80 energy certificate.
            ->assertSet('housing.sellingCosts.legal.value', '2000')
            ->assertSet('housing.sellingCosts.removals.value', '1200')
            ->assertSet('housing.sellingCosts.epc.value', '80');
    }

    public function test_an_old_scenario_seeds_editable_selling_cost_components_from_its_rate(): void
    {
        // A scenario saved before the breakdown carried only the single rate; editing it must
        // open with that rate as the estate-agent component (total preserved), the rest empty.
        $state = BuilderStateFixture::minimalValid();
        unset($state['housing']['sellingCosts']);
        $state['housing']['sellingCostRate'] = '1.5';

        $scenario = new Scenario;
        $scenario->user_id = auth()->id();
        $scenario->fillFromBuilderState($state);
        $scenario->status = ScenarioStatus::Ready;
        $scenario->save();

        Livewire::test(ScenarioBuilder::class, ['scenario' => $scenario])
            ->assertSet('housing.sellingCosts.estate_agent.value', '1.5')
            ->assertSet('housing.sellingCosts.estate_agent.basis', 'percent')
            ->assertSet('housing.sellingCosts.legal.value', '');
    }

    public function test_expense_lines_offer_a_per_line_cost_condition_with_an_auto_hint(): void
    {
        // A "Mortgage" line left on Auto shows what it infers (while the mortgage runs — the
        // payment stops at redemption or sale), and the override options are present so the user
        // can pin it to always / while-owning / while-working instead.
        Livewire::test(ScenarioBuilder::class)
            ->set('step', 4)
            ->set('expenseLines', [
                ['id' => 'm1', 'label' => 'Mortgage', 'amount' => '12000', 'category' => 'essential', 'savedAsAsset' => false, 'condition' => ''],
            ])
            ->assertSee('Applies')
            ->assertSee('Only while the mortgage runs')          // the new override option is offered
            ->assertSee('Only while you own this home')          // and the other housing option too
            ->assertSee('charged only while the mortgage runs'); // the Auto hint inferred from "Mortgage"
    }

    public function test_a_spend_line_can_be_excluded_from_the_forecast_without_being_deleted(): void
    {
        // Switching a line off keeps it but marks it excluded; the live subtotals drop it.
        Livewire::test(ScenarioBuilder::class)
            ->set('step', 4)
            ->set('expenseLines', [
                ['id' => 'a', 'label' => 'Food', 'amount' => '10000', 'category' => 'essential', 'savedAsAsset' => false, 'condition' => '', 'included' => true],
                ['id' => 'b', 'label' => 'Holidays', 'amount' => '6000', 'category' => 'discretionary', 'savedAsAsset' => false, 'condition' => '', 'included' => false],
            ])
            ->assertSee('Include this cost in the forecast')
            ->assertSee('excluded — kept but not counted'); // the off line is retained, flagged
    }

    public function test_the_home_step_reveals_the_cgt_wizard_when_the_home_was_let(): void
    {
        // Flagging the home as let reveals the capital-gains wizard with its occupation timeline.
        Livewire::test(ScenarioBuilder::class)
            ->set('step', 3)
            ->assertDontSee('Capital gains on sale')
            ->set('property.everLet', true)
            ->assertSee('Capital gains on sale')
            ->assertSee('When it was your main home, let out, or you were away')
            ->call('addCgtPeriod', 'let')
            ->assertSet('property.cgtHistory.periods.0.use', 'let')
            // An allowed absence is a third kind of period, offered by its own button.
            ->call('addCgtPeriod', 'absence_uk_work')
            ->assertSet('property.cgtHistory.periods.1.use', 'absence_uk_work')
            // An unknown kind falls back to the main home rather than reaching the assembler.
            ->call('addCgtPeriod', 'holiday_let_but_typoed')
            ->assertSet('property.cgtHistory.periods.2.use', 'main_home');
    }

    public function test_the_safety_buffer_months_is_captured_and_read_back(): void
    {
        $state = BuilderStateFixture::minimalValid();
        $state['expense']['safetyBufferMonths'] = '4';

        $this->fill($state)->call('save')->assertHasNoErrors();

        $this->assertSame(4, Scenario::where('user_id', auth()->id())->firstOrFail()->safetyBufferMonths());
    }

    public function test_the_safety_buffer_defaults_to_two_months_when_unset(): void
    {
        // The minimal fixture sets no buffer, so the scenario falls back to the 2-month default.
        $this->fill(BuilderStateFixture::minimalValid())->call('save');

        $this->assertSame(2, Scenario::where('user_id', auth()->id())->firstOrFail()->safetyBufferMonths());
    }

    public function test_the_mortgage_maturity_action_and_redemption_year_round_trip(): void
    {
        // The user can now say what happens when the mortgage term ends. Without a builder input for
        // these the choice (refinance / repay / forced sale) could not be modelled at all — so this
        // pins the two fields reaching the Property DTO the engine reads.
        $state = BuilderStateFixture::minimalValid();
        $state['hasProperty'] = true;
        $state['property'] = [
            'currentValue' => '400000', 'ownership' => 'mortgaged', 'everLet' => false,
            'outstandingMortgage' => '150000', 'runningCosts' => '3000', 'growthAssumptionOverride' => '', 'ownershipShare' => '',
            'mortgageRedemptionYear' => '2032', 'mortgageMaturityAction' => 'forced_sale',
        ];

        $this->fill($state)->call('save')->assertHasNoErrors();

        $home = Scenario::firstOrFail()->toHousehold()->primaryResidence;
        $this->assertSame(2032, $home->mortgageRedemptionYear);
        $this->assertSame(MortgageMaturityAction::ForcedSale, $home->mortgageMaturityAction);
    }

    public function test_the_buy_mortgage_rate_round_trips_into_the_housing_action(): void
    {
        // The rate that funds a buy above the sale proceeds must reach the HousingAction the engine
        // reads — without it a mortgaged downsize could not be modelled (a silent drop).
        $state = BuilderStateFixture::minimalValid();
        $state['housing']['buyPrice'] = '165000';
        $state['housing']['buyMortgageRate'] = '6';

        $this->fill($state)->call('save')->assertHasNoErrors();

        $this->assertSame(6.0, Scenario::firstOrFail()->toHousingAction()->buyMortgageRate?->asPercent());
    }

    public function test_the_ni_rate_field_shows_only_for_an_employed_person_and_drops_the_auto_categories(): void
    {
        $component = Livewire::test(ScenarioBuilder::class)->set('step', 1);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }

        // The lone person starts retired — employee NI can't apply, so the rate field is hidden.
        $component->assertDontSee('National Insurance rate');

        // Employed → the field appears with only the two genuine working-years overrides (Standard
        // is the default). The auto/moot letters are gone: NI stops at State Pension age and never
        // touches pension income on its own, so "over State Pension age (no NI)" and "not liable"
        // are no longer offered by hand.
        $component->set('people.0.employmentStatus', 'employed')
            ->assertSee('National Insurance rate')
            ->assertSee('Reduced rate')
            ->assertSee('Deferred')
            ->assertDontSee('(no NI)')
            ->assertDontSee('not liable');
    }

    public function test_a_disability_benefit_start_age_is_a_builder_input_and_reaches_the_household(): void
    {
        // Card 0044. The flag alone could only say on or off for life, so the commonest later-life
        // event (claiming Attendance Allowance as health declines) could not be entered at all.
        $this->fill(BuilderStateFixture::minimalValid())
            ->set('people.0.receivesDisabilityBenefit', true)
            ->set('people.0.disabilityBenefitFromAge', '80')
            ->call('save')
            ->assertHasNoErrors();

        $person = Scenario::firstOrFail()->toHousehold()->persons[0];
        $this->assertSame(80, $person->disabilityBenefitFromAge);
        $this->assertFalse($person->receivesDisabilityBenefitAt(79));
        $this->assertTrue($person->receivesDisabilityBenefitAt(80));
    }

    public function test_the_part_of_a_disability_award_is_a_builder_input_and_reaches_the_household(): void
    {
        // Card 0051. Only the care side of an award qualifies for the Pension Credit
        // severe-disability and carer additions, so a reader has to be able to say that theirs is
        // mobility-only. Blank keeps the qualifying care rate, which is what the flag alone meant.
        $this->fill(BuilderStateFixture::minimalValid())
            ->set('people.0.receivesDisabilityBenefit', true)
            ->call('save')
            ->assertHasNoErrors();

        $person = Scenario::firstOrFail()->toHousehold()->persons[0];
        $this->assertSame(DisabilityAwardRate::QualifyingCare, $person->disabilityAwardRate);
        $this->assertTrue($person->qualifiesForSevereDisabilityAdditionAt(70));

        $this->fill(BuilderStateFixture::minimalValid())
            ->set('people.0.receivesDisabilityBenefit', true)
            ->set('people.0.disabilityAwardRate', 'mobility_only')
            ->call('save')
            ->assertHasNoErrors();

        $person = Scenario::orderByDesc('id')->firstOrFail()->toHousehold()->persons[0];
        $this->assertSame(DisabilityAwardRate::MobilityOnly, $person->disabilityAwardRate);
        $this->assertTrue($person->receivesDisabilityBenefitAt(70), 'the benefit is still in payment');
        $this->assertFalse($person->qualifiesForSevereDisabilityAdditionAt(70), 'but it is not a qualifying one');
    }

    public function test_caring_for_a_partner_is_a_builder_input_and_reaches_the_household(): void
    {
        // Card 0044. The engine has wired the Pension Credit carer addition since July 2026, but no
        // screen could ever set the flag, so the addition was dead code as far as the app went.
        $state = BuilderStateFixture::minimalValid();
        $state['people'][] = ['id' => 'p2', 'dob' => '1957-03-01', 'sex' => 'male', 'employmentStatus' => 'retired',
            'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => ''];
        // A couple must answer this now (card 0054); it has no default.
        $state['relationshipStatus'] = 'married_or_civil_partnership';

        $this->fill($state)
            ->set('people.0.caresForPartner', true)
            ->set('people.1.receivesDisabilityBenefit', true)
            ->call('save')
            ->assertHasNoErrors();

        $persons = Scenario::firstOrFail()->toHousehold()->persons;
        $this->assertTrue($persons[0]->caresForPartner);
        $this->assertFalse($persons[1]->caresForPartner);
    }

    public function test_the_carer_question_is_only_asked_of_a_household_with_a_partner_to_care_for(): void
    {
        // Card 0044. There is no partner to care for in a one-person household, so asking would be
        // a question that cannot have a true answer.
        $component = Livewire::test(ScenarioBuilder::class)->set('step', 1);
        foreach (BuilderStateFixture::minimalValid() as $key => $value) {
            $component->set($key, $value);
        }

        $component->assertDontSee('Cares for their partner');

        $component->call('addPerson')->assertSee('Cares for their partner');
    }

    public function test_a_couple_must_choose_a_relationship_status(): void
    {
        // Card 0054. Marital status used to DEFAULT to married, in the form, in the DTO and in the
        // assembler, and it is the one input where the wrong value is catastrophic: no spouse
        // exemption, neither transferable band, no State Pension inheritance, and most DB schemes
        // pay a survivor's pension to a spouse or civil partner only. It now has no default.
        $state = BuilderStateFixture::minimalValid();
        $state['people'][] = ['id' => 'p2', 'dob' => '1957-03-01', 'sex' => 'male', 'employmentStatus' => 'retired',
            'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => ''];

        $this->fill($state)
            ->call('save')
            ->assertHasErrors(['relationshipStatus']);

        // A one-person household is never asked: the answer means nothing without a partner.
        $this->fill(BuilderStateFixture::minimalValid())
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_a_will_the_marriage_date_and_the_residence_position_are_builder_inputs(): void
    {
        // Card 0054. A will was assumed, the date of the marriage (which decides which State Pension
        // inheritance rules apply) was never asked, and neither was whether the surviving spouse is
        // a UK long-term resident, which caps the spouse exemption when they are not.
        $state = BuilderStateFixture::minimalValid();
        $state['people'][0]['hasWill'] = true;
        $state['people'][0]['ukLongTermResident'] = 'no';
        $state['marriageDate'] = '1990-06-14';

        $this->fill($state)->call('save')->assertHasNoErrors();

        $household = Scenario::firstOrFail()->toHousehold();
        $this->assertTrue($household->persons[0]->hasWill);
        $this->assertFalse($household->persons[0]->isUkLongTermResident());
        $this->assertSame('1990-06-14', $household->marriageDate);
    }

    public function test_a_will_is_not_assumed_when_nobody_answered(): void
    {
        $this->fill(BuilderStateFixture::minimalValid())->call('save')->assertHasNoErrors();

        $person = Scenario::firstOrFail()->toHousehold()->persons[0];
        $this->assertFalse($person->hasWill, 'no will unless the reader says there is one');
        $this->assertTrue($person->ukLongTermResidenceIsAssumed(), 'the residence position was never asked');
    }

    /** @param array<string, mixed> $state */
    private function fill(array $state): Testable
    {
        $component = Livewire::test(ScenarioBuilder::class);

        foreach ($state as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }
}
