<?php

declare(strict_types=1);

namespace Tests\Feature\Gdpr;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Forecast\ScenarioForecaster;
use App\Forecast\SimulationRunner;
use App\Models\Household;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HouseholdFixture;
use Tests\TestCase;

class GdprTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithData(): User
    {
        $user = User::factory()->create();

        $household = Household::fromDto(HouseholdFixture::household(), $user->id);
        $household->save();

        $scenario = new Scenario([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'name' => 'Sell and rent',
            'variant' => ScenarioVariant::Rent,
            'base_tax_year' => '2025-26',
            'iht_modelled' => false,
            'status' => ScenarioStatus::Draft,
        ]);
        $scenario->setHousingAction(HouseholdFixture::housingAction());
        $scenario->save();

        return $user;
    }

    public function test_export_returns_all_of_the_callers_data(): void
    {
        $user = $this->makeUserWithData();

        $response = $this->actingAs($user)->get(route('account.export'));
        $response->assertOk();

        $data = json_decode($response->streamedContent(), true);

        $this->assertSame($user->email, $data['user']['email']);
        $this->assertCount(1, $data['households']);
        $this->assertCount(1, $data['scenarios']);
        $this->assertSame('The Worked-Example Couple', $data['households'][0]['name']);
        $this->assertSame('rent', $data['scenarios'][0]['variant']);
        // The decrypted detail is present in the export (data portability).
        $this->assertSame(52_500_000, $data['scenarios'][0]['housing_action']['salePrice']);
        $this->assertNotEmpty($data['households'][0]['detail']['persons']);
    }

    public function test_export_only_returns_the_callers_own_data(): void
    {
        $me = $this->makeUserWithData();
        $someoneElse = $this->makeUserWithData();

        $data = json_decode(
            $this->actingAs($me)->get(route('account.export'))->streamedContent(),
            true,
        );

        $this->assertCount(1, $data['households']);
        $this->assertSame($me->id, Household::find($data['households'][0]['id'])->user_id);
        $this->assertNotSame($someoneElse->id, $data['households'][0]['id']);
    }

    public function test_export_includes_the_users_simulation_runs_and_results(): void
    {
        $user = $this->makeUserWithData();
        $run = (new SimulationRunner(new ScenarioForecaster))
            ->preview($user->scenarios()->firstOrFail(), seed: 1, paths: 20);

        $data = json_decode(
            $this->actingAs($user)->get(route('account.export'))->streamedContent(),
            true,
        );

        $this->assertCount(1, $data['simulation_runs']);
        $this->assertSame($run->id, $data['simulation_runs'][0]['id']);
        $this->assertCount(3, $data['simulation_runs'][0]['results']);
        $variants = array_column($data['simulation_runs'][0]['results'], 'variant');
        $this->assertEqualsCanonicalizing(['stay_put', 'buy_outright', 'rent'], $variants);
    }

    public function test_erase_hard_deletes_the_account_and_cascades(): void
    {
        $user = $this->makeUserWithData();
        $run = (new SimulationRunner(new ScenarioForecaster))
            ->preview($user->scenarios()->firstOrFail(), seed: 1, paths: 20);

        $this->actingAs($user)->delete(route('account.destroy'))->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, Household::where('user_id', $user->id)->count());
        $this->assertSame(0, Scenario::where('user_id', $user->id)->count());
        // The forecast history cascades away too — erased means gone.
        $this->assertSame(0, SimulationRun::where('user_id', $user->id)->count());
        $this->assertSame(0, Result::where('simulation_run_id', $run->id)->count());
    }

    public function test_anonymous_callers_cannot_export_or_erase_and_nothing_changes(): void
    {
        $user = $this->makeUserWithData();
        $householdCount = Household::count();

        // A guest is bounced to login (it is a web route, not an API), so neither
        // the export nor the erase ever runs.
        $this->get(route('account.export'))->assertRedirect(route('login'));
        $this->delete(route('account.destroy'))->assertRedirect(route('login'));

        // The guest requests neither read, deleted, nor wrote anything.
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame($householdCount, Household::count());
    }
}
