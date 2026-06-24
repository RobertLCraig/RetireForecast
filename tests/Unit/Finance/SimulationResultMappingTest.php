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

        $this->assertEquals($result, $rebuilt);
        $this->assertSame($payload, SimulationResultMapper::toArray($rebuilt));
    }
}
