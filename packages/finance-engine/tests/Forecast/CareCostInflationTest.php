<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PathDraws;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A1 — care fees carry an above-CPI escalation ({@see AssumptionSet::$careCostRealGrowth}).
 * The engine draws one CPI series and models care as a real spread over it; care is the
 * fastest-inflating major late-life cost, so the projector compounds the sampled self-funder
 * fee at CPI + this real rate to the year the spell falls, exactly as the property-costs
 * bucket escalates. These pin: the escalation compounds by the expected factor; a null (or
 * zero) rate is byte-identical to the pre-feature engine (so an old stored run reproduces);
 * and the escalated cost demonstrably reaches the result (completeness — care inflation bites,
 * it is not silently flat).
 *
 * Care is a Monte-Carlo-only risk, so these drive the shared {@see PathProjector} directly
 * with a controlled care draw (as {@see CareMeansTestedChargeTest} does), zero returns/inflation
 * so nominal == real and the escalation is the only moving part, and a large cash balance so the
 * resident is always a full self-funder (the means test passes the whole gross fee through,
 * keeping the arithmetic penny-exact).
 */
final class CareCostInflationTest extends TestCase
{
    public const CARE_FEE_REAL = 5_200_000; // £52,000/yr gross self-funder fee (today's money)

    public const CARE_AGE = 78;             // a single care year at age 78 = yearIndex 10 (base age 68)

    private const BASE_YEAR = 2026;

    /** The household-borne real care total for a run with the given real care escalation. */
    private function careCostReal(?Percent $growth): int
    {
        $result = $this->project($growth);
        $this->assertNotNull($result->careCostReal(), 'a care spell was modelled');

        return $result->careCostReal()->pence;
    }

    private function project(?Percent $growth): ForecastResult
    {
        $household = new Household(
            'CareInflation', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Female, EmploymentStatus::Retired)],
            // Modest essentials, fully funded — a huge cash balance keeps the resident above the
            // upper capital limit for life, so the means test charges the full gross fee.
            new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(100)),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(2_000_000))],
        );

        return (new PathProjector(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi)))
            ->project(
                $household,
                new ForecastSettings(baseYear: self::BASE_YEAR, baseTaxYear: '2026-27'),
                $this->draws($growth),
            );
    }

    public function test_care_fees_compound_above_cpi_to_the_year_of_the_spell(): void
    {
        // Flat (no escalation): the care year costs the today's-money fee unchanged.
        $this->assertSame(self::CARE_FEE_REAL, $this->careCostReal(null), 'flat-real without the rate');

        // CPI + 2% real: the single care year at yearIndex 10 has compounded 10 years of 2% real.
        $yearIndex = self::CARE_AGE - (self::BASE_YEAR - 1958); // 78 - 68 = 10
        $expected = (int) round(self::CARE_FEE_REAL * (1.02 ** $yearIndex));
        $this->assertSame($expected, $this->careCostReal(Percent::fromPercent(2)), 'compounds at CPI + 2% real');

        // The escalation is real, not rounding noise: ~22% more over the decade.
        $this->assertGreaterThan(self::CARE_FEE_REAL, $expected);
    }

    public function test_zero_or_null_growth_is_byte_identical_to_the_pre_feature_engine(): void
    {
        // Null (the back-compat default) and an explicit zero rate both leave care flat-real —
        // and identical, so an old stored run (which snapshots no care rate) reproduces exactly.
        $none = $this->project(null);
        $zero = $this->project(Percent::zero());

        $this->assertSame(self::CARE_FEE_REAL, $none->careCostReal()->pence);
        $this->assertSame($none->careCostReal()->pence, $zero->careCostReal()->pence);

        // The whole wealth path matches too (the escalation is the only thing the rate touches).
        $this->assertSame(count($none->years), count($zero->years));
        foreach ($none->years as $i => $year) {
            $this->assertSame($year->liquidWealth->pence, $zero->years[$i]->liquidWealth->pence, "year {$i} liquid wealth");
            $this->assertSame($year->spendTarget->pence, $zero->years[$i]->spendTarget->pence, "year {$i} spend");
        }
    }

    /**
     * Flat draws (zero returns/inflation) with a fixed self-funder fee in a single year at
     * {@see CARE_AGE}, and the given real care escalation.
     */
    private function draws(?Percent $growth): PathDraws
    {
        $rate = $growth?->asFraction() ?? 0.0;

        return new class($rate) implements PathDraws
        {
            public function __construct(private readonly float $careGrowth) {}

            public function investmentRealReturn(int $yearIndex): float
            {
                return 0.0;
            }

            public function cashRealReturn(int $yearIndex): float
            {
                return 0.0;
            }

            public function investmentIncomeYield(): float
            {
                return 0.0;
            }

            public function investmentChargeRate(): float
            {
                return 0.0;
            }

            public function inflation(int $yearIndex): float
            {
                return 0.0;
            }

            public function propertyGrowthReal(int $yearIndex, ?float $meanReal = null): float
            {
                return $meanReal ?? 0.0;
            }

            public function salaryGrowthReal(int $yearIndex): float
            {
                return 0.0;
            }

            public function deathAge(string $personId): int
            {
                return 90;
            }

            public function careAnnualCost(string $personId, int $age): int
            {
                return $age === CareCostInflationTest::CARE_AGE ? CareCostInflationTest::CARE_FEE_REAL : 0;
            }

            public function careCostRealGrowth(): float
            {
                return $this->careGrowth;
            }
        };
    }
}
