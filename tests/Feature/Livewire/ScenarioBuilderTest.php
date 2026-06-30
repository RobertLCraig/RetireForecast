<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Livewire\ScenarioBuilder;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
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
            ->assertSee('EPC & removals')
            // The default estate-agent line is a % of the sale; the flat fees are £.
            ->assertSet('housing.sellingCosts.estate_agent.value', '1.25')
            ->assertSet('housing.sellingCosts.estate_agent.basis', 'percent')
            ->assertSet('housing.sellingCosts.legal.basis', 'fixed');
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
        // A "Mortgage" line left on Auto shows what it infers (while-owning), and the override
        // options are present so the user can pin it to always / while-working instead.
        Livewire::test(ScenarioBuilder::class)
            ->set('step', 4)
            ->set('expenseLines', [
                ['id' => 'm1', 'label' => 'Mortgage', 'amount' => '12000', 'category' => 'essential', 'savedAsAsset' => false, 'condition' => ''],
            ])
            ->assertSee('Applies')
            ->assertSee('Only while you own this home')         // an override option is offered
            ->assertSee('charged only while you own this home'); // the Auto hint inferred from "Mortgage"
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
            ->assertSee('When it was your main home vs let out')
            ->call('addCgtPeriod', 'let')
            ->assertSet('property.cgtHistory.periods.0.use', 'let');
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
