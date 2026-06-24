<?php

declare(strict_types=1);

namespace Tests\Feature\Persistence;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Enums\SimulationMode;
use App\Enums\SimulationStatus;
use App\Forecast\ScenarioForecaster;
use App\Models\Household;
use App\Models\Result;
use App\Models\Scenario;
use App\Models\SimulationRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use Tests\Support\HouseholdFixture;
use Tests\TestCase;

class SimulationRunPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(): Scenario
    {
        $user = User::factory()->create();
        $household = Household::fromDto(HouseholdFixture::household(), $user->id);
        $household->save();

        $scenario = new Scenario([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'name' => 'Buy-vs-rent',
            'variant' => ScenarioVariant::Rent,
            'base_tax_year' => '2026-27',
            'iht_modelled' => false,
            'status' => ScenarioStatus::Ready,
        ]);
        $scenario->setHousingAction(HouseholdFixture::housingAction());
        $scenario->save();

        return $scenario->fresh();
    }

    public function test_a_run_and_its_variant_results_persist_and_decrypt_to_identical_dtos(): void
    {
        $scenario = $this->scenario();
        $comparison = (new ScenarioForecaster)->compareHousing($scenario, nPaths: 30, seed: 11);

        $run = new SimulationRun([
            'scenario_id' => $scenario->id,
            'user_id' => $scenario->user_id,
            'mode' => SimulationMode::Preview,
            'n_paths' => 30,
            'seed' => 11,
            'status' => SimulationStatus::Done,
            'progress_pct' => 100,
            'engine_version' => ScenarioForecaster::ENGINE_VERSION,
            'taxyear_config_version' => '2026-06-24',
        ]);
        $run->setAssumptionSnapshot(AssumptionSetLibrary::default());
        $run->save();

        foreach ($comparison as $variant => $result) {
            $row = new Result(['simulation_run_id' => $run->id, 'variant' => $variant]);
            $row->setSimulationResult($result);
            $row->save();
        }

        $reloaded = SimulationRun::with('results')->findOrFail($run->id);

        $this->assertEquals(AssumptionSetLibrary::default(), $reloaded->assumptionSnapshot());
        $this->assertCount(3, $reloaded->results);

        $rentResult = $reloaded->results->firstWhere('variant', ScenarioVariant::Rent);
        $this->assertEquals($comparison['rent'], $rentResult->simulationResult());
    }

    public function test_result_and_snapshot_payloads_are_encrypted_at_rest(): void
    {
        $scenario = $this->scenario();
        $result = (new ScenarioForecaster)->simulate($scenario, nPaths: 20, seed: 3);

        $run = new SimulationRun([
            'scenario_id' => $scenario->id,
            'user_id' => $scenario->user_id,
            'mode' => SimulationMode::Preview,
            'n_paths' => 20,
            'seed' => 3,
            'status' => SimulationStatus::Done,
            'progress_pct' => 100,
            'engine_version' => ScenarioForecaster::ENGINE_VERSION,
            'taxyear_config_version' => '2026-06-24',
        ]);
        $run->setAssumptionSnapshot(AssumptionSetLibrary::default());
        $run->save();

        $row = new Result(['simulation_run_id' => $run->id, 'variant' => ScenarioVariant::StayPut]);
        $row->setSimulationResult($result);
        $row->save();

        $rawResult = DB::table('results')->where('id', $row->id)->value('payload');
        $rawSnapshot = DB::table('simulation_runs')->where('id', $run->id)->value('assumption_snapshot');

        $this->assertStringNotContainsString('fanChart', $rawResult);
        $this->assertStringNotContainsString('Global equities', $rawSnapshot);
        $this->assertArrayHasKey('iv', json_decode(base64_decode($rawResult), true));
        $this->assertArrayHasKey('iv', json_decode(base64_decode($rawSnapshot), true));

        // The variant stays clear for lookup.
        $this->assertSame('stay_put', DB::table('results')->where('id', $row->id)->value('variant'));
    }
}
