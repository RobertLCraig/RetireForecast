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
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
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
            ->set('pensions.0.annuityRate', '7.2')
            ->set('pensions.0.annuityEscalation', 'rpi')
            ->set('pensions.0.annuityJoint', true)
            ->set('pensions.0.annuitySurvivorFraction', '50')
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
