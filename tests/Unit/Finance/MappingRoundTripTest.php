<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Finance\Mapping\AssumptionSetMapper;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Money\Percent;
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

        // The care escalation must reach storage too, or a stored care run would silently drop
        // it and understate the late-life risk. CPI + 2% real = 200 bps.
        $this->assertSame(200, $payload['careCostRealGrowth']);

        // The ongoing investment charge likewise, or a stored run would silently revert to
        // holding the portfolio for free and overstate the wealth it ends with. 0.50% = 50 bps.
        $this->assertSame(50, $payload['investmentCharge']);

        // The single-property volatility stores the RAW field, which is null on a shipped set: the
        // widened figure is derived from the index one, so storing the derived number would freeze
        // a set that should follow a re-sourced index (card 0029).
        $this->assertNull($payload['singlePropertyVolatility']);
        $this->assertSame(
            1800,
            AssumptionSetMapper::payload(AssumptionSetLibrary::default()->withSinglePropertyVolatility(Percent::fromPercent(18)))['singlePropertyVolatility'],
            'a figure the reader entered must reach storage, or their edit is lost on the next run',
        );
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
            $legacy['careCostRealGrowth'],
            $legacy['investmentCharge'],
            $legacy['singlePropertyVolatility'],
        );

        $rebuilt = AssumptionSetMapper::hydrate('Legacy', 'legacy', true, $legacy);

        $this->assertNull($rebuilt->houseGrowthVolatility);
        $this->assertSame(0.2, $rebuilt->houseEquityCorrelation);
        $this->assertNull($rebuilt->salaryGrowthVolatility);
        $this->assertSame(0.1, $rebuilt->salaryEquityCorrelation);
        // A pre-A1 snapshot has no care escalation: it must hydrate to null (flat-real care),
        // so the old care run reproduces exactly rather than gaining a new escalation.
        $this->assertNull($rebuilt->careCostRealGrowth);
        // Same contract for charges: a snapshot stored before they existed hydrates to null, so
        // its returns stay gross and the stored result reproduces byte-identically.
        $this->assertNull($rebuilt->investmentCharge);
        // The single-property volatility is deliberately NOT that contract. A snapshot stored
        // before card 0029 but after house growth went stochastic hydrates to null, which DERIVES
        // the widened figure, so re-running it gives a wider fan than the stored one. That is the
        // point: the stored fan was too narrow, and the ENGINE_VERSION bump records that the two
        // are not comparable.
        $stochastic = AssumptionSetMapper::payload(AssumptionSetLibrary::default());
        unset($stochastic['singlePropertyVolatility']);
        $widened = AssumptionSetMapper::hydrate('Legacy', 'legacy', true, $stochastic);

        $this->assertNull($widened->singlePropertyVolatility);
        $this->assertSame(1800, $widened->singlePropertyVolatility()?->basisPoints);
    }
}
