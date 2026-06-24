<?php

declare(strict_types=1);

namespace Tests\Feature\Persistence;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Models\AssumptionSet;
use App\Models\Household;
use App\Models\Scenario;
use App\Models\User;
use Database\Seeders\AssumptionSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use Tests\Support\HouseholdFixture;
use Tests\TestCase;

class ScenarioPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_household_decrypts_to_an_identical_dto(): void
    {
        $user = User::factory()->create();
        $dto = HouseholdFixture::household();

        $saved = Household::fromDto($dto, $user->id);
        $saved->save();

        $reloaded = Household::findOrFail($saved->id);

        $this->assertEquals($dto, $reloaded->toDto());
    }

    public function test_the_household_payload_is_encrypted_at_rest(): void
    {
        $user = User::factory()->create();
        $saved = Household::fromDto(HouseholdFixture::household(), $user->id);
        $saved->save();

        $raw = DB::table('households')->where('id', $saved->id)->value('payload');

        // The £62,000 salary is 6,200,000 pence in the payload; it must not be readable.
        $this->assertStringNotContainsString('6200000', $raw);
        $this->assertStringNotContainsString('persons', $raw);

        // ...but it is a Laravel encryption envelope, not just opaque text.
        $envelope = json_decode(base64_decode($raw), true);
        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('iv', $envelope);
        $this->assertArrayHasKey('value', $envelope);

        // Clear structural columns stay readable for listing/filtering.
        $this->assertSame('The Worked-Example Couple', DB::table('households')->where('id', $saved->id)->value('name'));
        $this->assertSame('england_wales_ni', DB::table('households')->where('id', $saved->id)->value('region'));
    }

    public function test_a_saved_scenario_round_trips_and_links_its_household_and_assumption_set(): void
    {
        $this->seed(AssumptionSetSeeder::class);

        $user = User::factory()->create();
        $household = Household::fromDto(HouseholdFixture::household(), $user->id);
        $household->save();

        $default = AssumptionSet::where('is_default', true)->firstOrFail();
        $action = HouseholdFixture::housingAction();

        $scenario = new Scenario([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'assumption_set_id' => $default->id,
            'name' => 'Sell and rent',
            'variant' => ScenarioVariant::Rent,
            'base_tax_year' => '2025-26',
            'iht_modelled' => true,
            'status' => ScenarioStatus::Ready,
        ]);
        $scenario->setHousingAction($action);
        $scenario->save();

        $reloaded = Scenario::findOrFail($scenario->id);

        $this->assertEquals($action, $reloaded->housingAction());
        $this->assertSame(ScenarioVariant::Rent, $reloaded->variant);
        $this->assertSame(ScenarioStatus::Ready, $reloaded->status);
        $this->assertTrue($reloaded->iht_modelled);
        $this->assertTrue($reloaded->household->is($household));
        $this->assertEquals(AssumptionSetLibrary::default(), $reloaded->assumptionSet->toDto());

        // The scenario payload is encrypted at rest too.
        $raw = DB::table('scenarios')->where('id', $scenario->id)->value('payload');
        $this->assertStringNotContainsString('salePrice', $raw);
    }

    public function test_the_seeder_loads_the_shipped_sets_with_exactly_one_default(): void
    {
        $this->seed(AssumptionSetSeeder::class);

        $this->assertSame(count(AssumptionSetLibrary::all()), AssumptionSet::count());
        $this->assertSame(1, AssumptionSet::where('is_default', true)->count());

        $default = AssumptionSet::where('is_default', true)->firstOrFail();
        $this->assertEquals(AssumptionSetLibrary::default(), $default->toDto());
    }

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(AssumptionSetSeeder::class);
        $this->seed(AssumptionSetSeeder::class);

        $this->assertSame(count(AssumptionSetLibrary::all()), AssumptionSet::count());
    }
}
