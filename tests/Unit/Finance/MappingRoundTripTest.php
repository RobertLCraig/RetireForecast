<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Finance\Mapping\AssumptionSetMapper;
use App\Finance\Mapping\HouseholdMapper;
use App\Finance\Mapping\HousingActionMapper;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use Tests\Support\HouseholdFixture;

/**
 * The DTO is the single source of truth for the shape; these prove the app maps to
 * and from it losslessly, through a JSON encode/decode cycle (what the encrypted
 * array cast does on the way to and from the database). A rebuilt DTO must equal
 * the original and re-serialise to a byte-identical payload.
 */
class MappingRoundTripTest extends TestCase
{
    public function test_household_round_trips_through_a_json_cycle(): void
    {
        $dto = HouseholdFixture::household();

        $payload = HouseholdMapper::payload($dto);
        $decoded = json_decode(json_encode($payload), true);
        $rebuilt = HouseholdMapper::hydrate($dto->name, $dto->region, $decoded);

        $this->assertEquals($dto, $rebuilt);
        $this->assertSame($payload, HouseholdMapper::payload($rebuilt));
    }

    public function test_housing_action_round_trips_through_a_json_cycle(): void
    {
        $dto = HouseholdFixture::housingAction();

        $payload = HousingActionMapper::toArray($dto);
        $decoded = json_decode(json_encode($payload), true);
        $rebuilt = HousingActionMapper::fromArray($decoded);

        $this->assertEquals($dto, $rebuilt);
        $this->assertSame($payload, HousingActionMapper::toArray($rebuilt));
    }

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
