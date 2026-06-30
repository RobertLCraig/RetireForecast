<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Finance\Mapping\SimulationResultMapper;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use Tests\Support\HouseholdFixture;

class SimulationResultMappingTest extends TestCase
{
    public function test_a_simulation_result_round_trips_through_a_json_cycle(): void
    {
        $result = (new Simulator(TaxYearRegistry::for('2026-27')))->run(
            HouseholdFixture::household(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            new CohortLifeTable,
            nPaths: 30,
            seed: 5,
        );

        $payload = SimulationResultMapper::toArray($result);
        $decoded = json_decode(json_encode($payload), true);
        $rebuilt = SimulationResultMapper::fromArray($decoded);

        $this->assertEquals($result, $rebuilt); // covers the longevity distribution too
        $this->assertSame($payload, SimulationResultMapper::toArray($rebuilt));
        $this->assertNotNull($rebuilt->longevity);
    }

    public function test_a_run_persisted_before_longevity_existed_rehydrates_with_null(): void
    {
        $result = (new Simulator(TaxYearRegistry::for('2026-27')))->run(
            HouseholdFixture::household(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            new CohortLifeTable,
            nPaths: 20,
            seed: 5,
        );

        $payload = SimulationResultMapper::toArray($result);
        unset($payload['longevity']); // an older stored run has no longevity key

        $this->assertNull(SimulationResultMapper::fromArray($payload)->longevity);
    }
}
