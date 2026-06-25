<?php

declare(strict_types=1);

namespace Tests\Feature\Persistence;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Models\AssumptionSet;
use App\Models\Scenario;
use App\Models\User;
use Database\Seeders\AssumptionSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use Tests\Support\HouseholdFixture;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

class ScenarioPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_saved_scenario_derives_the_identical_engine_dtos_from_its_builder_state(): void
    {
        $user = User::factory()->create();

        $scenario = ScenarioFixture::rich($user);
        $reloaded = Scenario::findOrFail($scenario->id);

        // The builder form-state is the single source of truth; the engine DTOs are
        // derived from it and must equal the canonical fixture exactly.
        $this->assertEquals(HouseholdFixture::household(), $reloaded->toHousehold());
        $this->assertEquals(HouseholdFixture::housingAction(), $reloaded->toHousingAction());
    }

    public function test_the_structural_columns_are_projected_from_the_builder_state(): void
    {
        $user = User::factory()->create();

        $scenario = ScenarioFixture::rich($user, [
            'name' => 'Sell and rent',
            'variant' => 'buy_outright',
            'baseTaxYear' => '2025-26',
            'ihtModelled' => true,
        ]);
        $reloaded = Scenario::findOrFail($scenario->id);

        $this->assertSame('Sell and rent', $reloaded->name);
        $this->assertSame(ScenarioVariant::BuyOutright, $reloaded->variant);
        $this->assertSame('2025-26', $reloaded->base_tax_year);
        $this->assertTrue($reloaded->iht_modelled);
        $this->assertSame(ScenarioStatus::Ready, $reloaded->status);
        $this->assertSame('The Worked-Example Couple', $reloaded->householdName());
    }

    public function test_the_builder_state_is_encrypted_at_rest(): void
    {
        $scenario = ScenarioFixture::rich(User::factory()->create());

        $raw = DB::table('scenarios')->where('id', $scenario->id)->value('builder_state');

        // The household detail (the £62,000 salary string, the people array) must not be readable.
        $this->assertStringNotContainsString('62000', $raw);
        $this->assertStringNotContainsString('people', $raw);

        // ...but it is a Laravel encryption envelope, not just opaque text.
        $envelope = json_decode(base64_decode($raw), true);
        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('iv', $envelope);
        $this->assertArrayHasKey('value', $envelope);

        // Clear structural columns stay readable for listing/filtering.
        $this->assertSame('Buy-vs-rent', DB::table('scenarios')->where('id', $scenario->id)->value('name'));
        $this->assertSame('rent', DB::table('scenarios')->where('id', $scenario->id)->value('variant'));
    }

    public function test_a_saved_scenario_links_its_assumption_set(): void
    {
        $this->seed(AssumptionSetSeeder::class);

        $user = User::factory()->create();
        $default = AssumptionSet::where('is_default', true)->firstOrFail();

        $scenario = ScenarioFixture::rich($user, ['assumptionSetId' => $default->id]);
        $reloaded = Scenario::findOrFail($scenario->id);

        $this->assertTrue($reloaded->assumptionSet->is($default));
        $this->assertEquals(AssumptionSetLibrary::default(), $reloaded->assumptionSet->toDto());
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
