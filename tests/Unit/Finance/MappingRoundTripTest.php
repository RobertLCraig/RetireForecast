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

    public function test_a_shipped_set_serialises_its_house_volatility_and_correlation(): void
    {
        $payload = AssumptionSetMapper::payload(AssumptionSetLibrary::default());

        // The stochastic-house fields must reach storage, or a stored run would silently lose
        // its house risk (the completeness rule). 9% real vol = 900 bps; correlation as a float.
        $this->assertSame(900, $payload['houseGrowthVolatility']);
        $this->assertSame(0.2, $payload['houseEquityCorrelation']);
    }

    public function test_a_pre_stochastic_house_snapshot_hydrates_to_deterministic_house_growth(): void
    {
        // A run stored before 2026-07-18 has no house-volatility keys. It must hydrate to null
        // volatility (deterministic house growth at the mean) so the old run reproduces exactly
        // as it did — never silently gaining a new risk factor it was not computed with.
        $legacy = AssumptionSetMapper::payload(AssumptionSetLibrary::default());
        unset($legacy['houseGrowthVolatility'], $legacy['houseEquityCorrelation']);

        $rebuilt = AssumptionSetMapper::hydrate('Legacy', 'legacy', true, $legacy);

        $this->assertNull($rebuilt->houseGrowthVolatility);
        $this->assertSame(0.2, $rebuilt->houseEquityCorrelation);
    }
}
