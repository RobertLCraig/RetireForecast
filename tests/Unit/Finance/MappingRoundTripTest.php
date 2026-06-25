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
}
