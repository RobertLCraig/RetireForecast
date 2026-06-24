<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\ScenarioStatus;
use App\Livewire\ScenarioBuilder;
use App\Models\Household;
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

        $this->assertSame(1, Household::count());
        $this->assertSame(1, Scenario::count());
        $this->assertSame(ScenarioStatus::Ready, Scenario::firstOrFail()->status);
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
        $this->assertEquals(HouseholdFixture::household(), $scenario->household->toDto());
        $this->assertEquals(HouseholdFixture::housingAction(), $scenario->housingAction());
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

        $this->assertSame(0, Household::count());
        $this->assertSame(0, Scenario::count());
    }

    public function test_the_builder_screen_renders_for_a_signed_in_user(): void
    {
        $this->get(route('scenarios.create'))->assertOk()->assertSee('New forecast');
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
