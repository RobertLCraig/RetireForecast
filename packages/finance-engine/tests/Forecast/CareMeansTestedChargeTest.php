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
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
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
 * The care means test in the projection: each care year is charged at what the HOUSEHOLD
 * bears, not the gross self-funder fee. England assesses the individual, so these pin the
 * four load-bearing rules against penny-exact expectations (zero growth/inflation, so
 * nominal == real and incomes are flat):
 *
 *  - a funded resident (own capital below the limits) pays income minus the PEA, and the
 *    local authority pays the balance of the fee;
 *  - a comfortable self-funder still pays the full fee (behaviour preserved);
 *  - the home is assessed once the resident lives alone — home equity makes a lone
 *    homeowner a self-funder;
 *  - a partner still living in the home shields it, and only the RESIDENT's own income
 *    and accounts are assessable (the partner's are not, so a resident with nothing of
 *    their own is fully LA-funded even in a wealthy household).
 *
 * Fixture arithmetic (2026-27): a £30,000 taxable income bears £3,486 tax (net £26,514,
 * set equal to essential spend so wealth is flat and Pension Credit never bites); the
 * care fee is £80,000 a year for the final 3 years of life (ages 88-90); the income
 * contribution is £30,000 − £1,653.60 PEA = £28,346.40 a year.
 */
final class CareMeansTestedChargeTest extends TestCase
{
    public const FEE_REAL = 8_000_000; // £80,000 a year, real pence (read by the draws stub)

    public const CONTRIBUTION = 2_834_640; // £30,000 − £1,653.60 PEA, pence a year

    private function project(Household $household, array $deathAges, array $careFromAge): ForecastResult
    {
        $projector = new PathProjector(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi));

        return $projector->project(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            $this->draws($deathAges, $careFromAge),
        );
    }

    /** Flat draws (zero returns/inflation) with a fixed care fee from a given age to death. */
    private function draws(array $deathAges, array $careFromAge): PathDraws
    {
        return new class($deathAges, $careFromAge) implements PathDraws
        {
            public function __construct(private readonly array $deathAges, private readonly array $careFromAge) {}

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

            public function inflation(int $yearIndex): float
            {
                return 0.0;
            }

            public function houseGrowthReal(int $yearIndex): float
            {
                return 0.0;
            }

            public function salaryGrowthReal(int $yearIndex): float
            {
                return 0.0;
            }

            public function deathAge(string $personId): int
            {
                return $this->deathAges[$personId];
            }

            public function careAnnualCost(string $personId, int $age): int
            {
                $from = $this->careFromAge[$personId] ?? null;

                return $from !== null && $age >= $from ? CareMeansTestedChargeTest::FEE_REAL : 0;
            }

            public function careCostRealGrowth(): float
            {
                return 0.0;
            }
        };
    }

    private function person(string $id): Person
    {
        return new Person($id, new DateTimeImmutable('1958-01-01'), Sex::Female, EmploymentStatus::Retired);
    }

    /** £30,000 a year taxable for life, so no Pension Credit ever tops it up. */
    private function income(string $ownerId, int $pounds = 30_000): IncomeStream
    {
        return new IncomeStream($ownerId, IncomeStreamType::Other, Money::fromPounds($pounds), taxable: true, inflationLinked: false, startAge: 60);
    }

    /** Essential spend equal to the net income, so wealth is flat outside the care years. */
    private function spend(int $netPounds): ExpenseProfile
    {
        return new ExpenseProfile(Money::fromPounds($netPounds), Money::zero(), Percent::fromPercent(100));
    }

    public function test_a_funded_resident_is_charged_the_income_contribution_not_the_gross_fee(): void
    {
        // £10,000 of own capital (below the £14,250 lower limit): no capital side at all,
        // so each of the 3 care years costs the household £28,346.40 — not £80,000.
        $household = new Household(
            'FundedResident', RegionProfile::EnglandWalesNi,
            [$this->person('p1')],
            $this->spend(26_514),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(10_000))],
            incomeStreams: [$this->income('p1')],
        );

        $result = $this->project($household, deathAges: ['p1' => 90], careFromAge: ['p1' => 88]);

        $this->assertSame(3 * self::CONTRIBUTION, $result->careCostReal()->pence);
        $this->assertLessThan(3 * self::FEE_REAL, $result->careCostReal()->pence, 'the local authority bears the balance');
    }

    public function test_the_care_year_raises_spend_by_exactly_the_charged_amount(): void
    {
        // Reconciliation: the spend target steps up at the first care year by the charge,
        // not by the gross fee — the same single definition the care total accumulates.
        $household = new Household(
            'FundedResident', RegionProfile::EnglandWalesNi,
            [$this->person('p1')],
            $this->spend(26_514),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(10_000))],
            incomeStreams: [$this->income('p1')],
        );

        $years = [];
        foreach ($this->project($household, deathAges: ['p1' => 90], careFromAge: ['p1' => 88])->years as $year) {
            $years[$year->calendarYear] = $year;
        }

        $this->assertSame(
            self::CONTRIBUTION,
            $years[2046]->spendTarget->pence - $years[2045]->spendTarget->pence,
            'care enters spend at the means-tested charge',
        );
    }

    public function test_a_comfortable_self_funder_still_pays_the_full_fee(): void
    {
        // £500,000 of capital: well above the upper limit in every care year, so the
        // pre-means-test behaviour is preserved to the penny.
        $household = new Household(
            'SelfFunder', RegionProfile::EnglandWalesNi,
            [$this->person('p1')],
            $this->spend(26_514),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(500_000))],
            incomeStreams: [$this->income('p1')],
        );

        $result = $this->project($household, deathAges: ['p1' => 90], careFromAge: ['p1' => 88]);

        $this->assertSame(3 * self::FEE_REAL, $result->careCostReal()->pence);
    }

    public function test_home_equity_is_assessed_once_the_resident_lives_alone(): void
    {
        // Same £10,000 of liquid capital as the funded resident, but a £300,000 home and
        // no partner left to live in it: the equity makes them a self-funder, so the full
        // fee is charged — a lone homeowner cannot shelter care costs behind the bricks.
        $household = new Household(
            'LoneOwner', RegionProfile::EnglandWalesNi,
            [$this->person('p1')],
            $this->spend(26_514),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(10_000))],
            incomeStreams: [$this->income('p1')],
            primaryResidence: new Property(currentValue: Money::fromPounds(300_000), ownership: OwnershipType::Outright),
        );

        $result = $this->project($household, deathAges: ['p1' => 90], careFromAge: ['p1' => 88]);

        $this->assertSame(3 * self::FEE_REAL, $result->careCostReal()->pence);
    }

    public function test_a_partner_living_in_the_home_shields_it_and_only_the_residents_own_means_count(): void
    {
        // A couple in the same £300,000 home, partner alive throughout the care spell: the
        // home is disregarded and only the resident's own £10,000 and £30,000 income are
        // assessed — the same charge as the homeless funded resident, although the
        // household's joint income is twice hers.
        $household = new Household(
            'CoupleShield', RegionProfile::EnglandWalesNi,
            [$this->person('p1'), $this->person('p2')],
            $this->spend(2 * 26_514),
            accounts: [new Account('p2', AccountType::Cash, Money::fromPounds(10_000))],
            incomeStreams: [$this->income('p1'), $this->income('p2')],
            primaryResidence: new Property(currentValue: Money::fromPounds(300_000), ownership: OwnershipType::Outright),
        );

        $result = $this->project($household, deathAges: ['p1' => 95, 'p2' => 90], careFromAge: ['p2' => 88]);

        $this->assertSame(3 * self::CONTRIBUTION, $result->careCostReal()->pence);
    }

    public function test_a_resident_with_nothing_of_their_own_is_fully_funded_even_in_a_wealthy_household(): void
    {
        // All income and savings are the partner's: the resident's own assessment finds
        // nothing (income £0 is below the PEA), so the local authority funds the whole
        // spell and no care cost reaches the household — a spouse's resources are not
        // assessable in England.
        $household = new Household(
            'PartnerMeans', RegionProfile::EnglandWalesNi,
            [$this->person('p1'), $this->person('p2')],
            $this->spend(48_568),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(10_000))],
            incomeStreams: [$this->income('p1', 60_000)],
        );

        $result = $this->project($household, deathAges: ['p1' => 95, 'p2' => 90], careFromAge: ['p2' => 88]);

        $this->assertNull($result->careCostRealValue, 'no household-borne care cost on this path');
    }
}
