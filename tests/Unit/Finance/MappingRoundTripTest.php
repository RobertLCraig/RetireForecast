<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Finance\Mapping\AssumptionSetMapper;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use Tests\Feature\Persistence\ScenarioPersistenceTest;
use Tests\Unit\Forecast\HouseholdAssemblerTest;

/**
 * The assumption-set DTO is the single source of truth for its shape; this proves the
 * app maps to and from it losslessly through a JSON encode/decode cycle (what the
 * encrypted-array cast does on the way to and from the database). A rebuilt DTO must
 * equal the original and re-serialise to a byte-identical payload.
 *
 * Household + housing form-state no longer round-trip through a mapper: the builder
 * form-state is stored directly and the engine DTOs are derived from it
 * ({@see HouseholdAssemblerTest},
 * {@see ScenarioPersistenceTest}).
 */
class MappingRoundTripTest extends TestCase
{
    public function test_every_shipped_assumption_set_round_trips_through_a_json_cycle(): void
    {
        foreach (AssumptionSetLibrary::all() as $dto) {
            $payload = AssumptionSetMapper::payload($dto);
            $decoded = json_decode(json_encode($payload), true);
            $rebuilt = AssumptionSetMapper::hydrate($dto->name, $dto->sourceNote, $dto->isDefault, $decoded);

            $this->assertEquals($dto, $rebuilt, "Assumption set '{$dto->name}' did not round-trip");
            $this->assertSame($payload, AssumptionSetMapper::payload($rebuilt));
        }
    }

    public function test_a_shipped_set_serialises_its_house_and_salary_volatilities_and_correlations(): void
    {
        $payload = AssumptionSetMapper::payload(AssumptionSetLibrary::default());

        // The stochastic fields must reach storage, or a stored run would silently lose its
        // house/salary risk (the completeness rule). 9% real house vol = 900 bps, 2% salary
        // vol = 200 bps; each correlation as a float.
        $this->assertSame(900, $payload['houseGrowthVolatility']);
        $this->assertSame(0.2, $payload['houseEquityCorrelation']);
        $this->assertSame(200, $payload['salaryGrowthVolatility']);
        $this->assertSame(0.1, $payload['salaryEquityCorrelation']);
    }

    public function test_a_pre_stochastic_snapshot_hydrates_to_deterministic_house_and_salary_growth(): void
    {
        // A run stored before the stochastic-growth work has no volatility keys. It must hydrate
        // to null volatility (deterministic growth at the mean) so the old run reproduces exactly
        // as it did — never silently gaining a new risk factor it was not computed with.
        $legacy = AssumptionSetMapper::payload(AssumptionSetLibrary::default());
        unset(
            $legacy['houseGrowthVolatility'], $legacy['houseEquityCorrelation'],
            $legacy['salaryGrowthVolatility'], $legacy['salaryEquityCorrelation'],
        );

        $rebuilt = AssumptionSetMapper::hydrate('Legacy', 'legacy', true, $legacy);

        $this->assertNull($rebuilt->houseGrowthVolatility);
        $this->assertSame(0.2, $rebuilt->houseEquityCorrelation);
        $this->assertNull($rebuilt->salaryGrowthVolatility);
        $this->assertSame(0.1, $rebuilt->salaryEquityCorrelation);
    }
}
