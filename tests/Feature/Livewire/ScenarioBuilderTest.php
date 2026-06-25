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
        $state['expense']['essential'] = '20000.123';

        $this->fill($state)->call('save')->assertHasErrors('expense.essential');
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
