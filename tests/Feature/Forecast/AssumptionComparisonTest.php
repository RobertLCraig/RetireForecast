<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Forecast\AssumptionComparison;
use App\Models\Household;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HouseholdFixture;
use Tests\TestCase;

class AssumptionComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_the_central_projection_under_each_shipped_set(): void
    {
        $rows = (new AssumptionComparison)->compare($this->scenario());

        $this->assertCount(3, $rows);

        $names = array_column($rows, 'name');
        $this->assertStringContainsString('FCA default', $names[0]); // default first
        $this->assertContains('DMS historical', $names);
        $this->assertContains('OBR/BoE inflation-anchored', $names);

        foreach ($rows as $row) {
            $this->assertIsInt($row['terminalPence']);
        }
    }

    public function test_it_is_reproducible(): void
    {
        $scenario = $this->scenario();

        $this->assertEquals(
            (new AssumptionComparison)->compare($scenario),
            (new AssumptionComparison)->compare($scenario),
        );
    }

    private function scenario(): Scenario
    {
        $user = User::factory()->create();
        $household = Household::fromDto(HouseholdFixture::household(), $user->id);
        $household->save();

        $scenario = new Scenario([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'name' => 'Sensitivity',
            'variant' => ScenarioVariant::Rent,
            'base_tax_year' => '2026-27',
            'iht_modelled' => false,
            'status' => ScenarioStatus::Ready,
        ]);
        $scenario->setHousingAction(HouseholdFixture::housingAction());
        $scenario->save();

        return $scenario->fresh();
    }
}
