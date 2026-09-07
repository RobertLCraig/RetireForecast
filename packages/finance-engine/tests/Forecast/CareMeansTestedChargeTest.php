<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Benefits\DisabilityBenefitInCare;
use RetireForecast\FinanceEngine\Care\DeferredPaymentAgreement;
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

    private function project(Household $household, array $deathAges, array $careFromAge, bool $modelIht = false): ForecastResult
    {
        $projector = new PathProjector(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi));

        return $projector->project(
            $household,
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelIht: $modelIht, homeToDescendants: true),
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

    public function test_a_residents_pension_credit_is_counted_into_their_contribution(): void
    {
        // Guarantee Credit is assessable income for the care charge, but it is tax-free, so it
        // never appears in the tax pass the assessment reads. A resident whose own income sits
        // below the minimum guarantee therefore has to be charged (own income + the credit) less
        // the PEA — the credit is handed to the home, not banked. Spend well above income drains
        // the £8,000 of cash in the first year, so capital is nil by the care years and neither
        // the tariff nor the capital-above-limit term muddies the arithmetic.
        $household = new Household(
            'CreditResident', RegionProfile::EnglandWalesNi,
            [$this->person('p1')],
            $this->spend(30_000),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(8_000))],
            incomeStreams: [$this->income('p1', 6_000)],
        );

        $years = [];
        foreach ($this->project($household, deathAges: ['p1' => 90], careFromAge: ['p1' => 88])->years as $year) {
            $years[$year->calendarYear] = $year;
        }

        // The PEA is read from the tax-year registry that owns it, never restated here.
        $config = TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi);
        $peaAnnual = $config->care->personalExpensesAllowanceWeekly->pence * $config->statePension->weeksPerYear;

        $credit = $years[2046]->incomeBySource['means_tested_benefit']->pence;
        $this->assertGreaterThan(0, $credit, 'the fixture is on Guarantee Credit');

        $this->assertSame(
            600_000 + $credit - $peaAnnual,
            $years[2046]->spendTarget->pence - $years[2045]->spendTarget->pence,
            'the care charge counts the credit as income',
        );
    }

    /** A disability award component, tax-free and flat, running for life from age 60. */
    private function disability(string $ownerId, IncomeStreamType $type, int $pounds): IncomeStream
    {
        return new IncomeStream($ownerId, $type, Money::fromPounds($pounds), taxable: false, inflationLinked: false, startAge: 60);
    }

    /** The Personal Expenses Allowance a year, read from the registry that owns it. */
    private function peaAnnual(): int
    {
        $config = TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi);

        return $config->care->personalExpensesAllowanceWeekly->pence * $config->statePension->weeksPerYear;
    }

    /**
     * Board card 0050. A local-authority financial assessment takes the CARE component of
     * DLA (and Attendance Allowance) into account as income; only the MOBILITY component is
     * disregarded. The engine assessed neither, so a resident who was funding their own care
     * was charged too little.
     *
     * Both runs are identical bar the component the £5,000 is entered as. Capital is £23,300 —
     * £50 over the upper limit, so the resident is a self-funder whose charge is the crossing-year
     * sum (capital down to the limit, then income less the PEA) rather than the capped £80,000
     * fee, which is what makes the income side visible at all.
     */
    public function test_the_care_component_is_assessed_for_a_self_funder_and_the_mobility_component_is_not(): void
    {
        $charge = function (IncomeStreamType $type): int {
            $household = new Household(
                'SelfFunderOnDla', RegionProfile::EnglandWalesNi,
                [$this->person('p1')],
                $this->spend(33_514), // net £26,514 taxed income + £7,000 tax-free: wealth is flat
                accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(23_300))],
                incomeStreams: [
                    $this->income('p1'),
                    $this->disability('p1', $type, 5_000),
                    $this->disability('p1', IncomeStreamType::DisabilityBenefitMobility, 2_000),
                ],
            );

            return $this->project($household, deathAges: ['p1' => 88], careFromAge: ['p1' => 88])->careCostReal()->pence;
        };

        // Mobility only: the £5,000 is entered as a second mobility award, so nothing of the
        // disability money is assessed and the charge is £50 of capital plus £30,000 less the PEA.
        $this->assertSame(
            5_000 + 3_000_000 - $this->peaAnnual(),
            $charge(IncomeStreamType::DisabilityBenefitMobility),
            'the mobility component is disregarded in full',
        );

        // The same £5,000 as the care component raises the charge by exactly itself.
        $this->assertSame(
            500_000,
            $charge(IncomeStreamType::DisabilityBenefit) - $charge(IncomeStreamType::DisabilityBenefitMobility),
            'the care component is assessable income for the charge',
        );
    }

    /**
     * Board card 0050, the other side of the same split. Attendance Allowance and the DLA care
     * component STOP after 28 days in a care home the local authority funds; the mobility
     * component keeps running. The engine paid both right through a modelled spell.
     *
     * £10,000 of capital is below the lower limit, so the resident is funded from the first care
     * year: the care component is paid for the statutory 28 days of that year and for none of the
     * next two, while the £2,000 mobility component is paid in full throughout.
     */
    public function test_a_local_authority_funded_placement_stops_the_care_component_after_the_statutory_period(): void
    {
        $household = new Household(
            'FundedResidentOnDla', RegionProfile::EnglandWalesNi,
            [$this->person('p1')],
            $this->spend(33_514),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(10_000))],
            incomeStreams: [
                $this->income('p1'),
                $this->disability('p1', IncomeStreamType::DisabilityBenefit, 5_000),
                $this->disability('p1', IncomeStreamType::DisabilityBenefitMobility, 2_000),
            ],
        );

        $years = [];
        foreach ($this->project($household, deathAges: ['p1' => 90], careFromAge: ['p1' => 88])->years as $year) {
            $years[$year->calendarYear] = $year;
        }

        $paidFor28Days = (int) round(500_000 * DisabilityBenefitInCare::PAYMENT_STOP_DAYS / DisabilityBenefitInCare::DAYS_PER_YEAR);

        $this->assertSame(700_000, $years[2045]->incomeBySource['tax_free_income']->pence, 'both components before care');
        $this->assertSame(200_000 + $paidFor28Days, $years[2046]->incomeBySource['tax_free_income']->pence, 'the statutory period only');
        $this->assertSame(200_000, $years[2047]->incomeBySource['tax_free_income']->pence, 'care component stopped, mobility runs on');
        $this->assertSame(200_000, $years[2048]->incomeBySource['tax_free_income']->pence, 'and stays stopped');
    }

    /**
     * Board card 0055, criterion 1. The statutory property disregard is MANDATORY where the home
     * is occupied by the resident's spouse or civil partner, a relative aged 60 or over, an
     * incapacitated relative, or a child under 18. The engine disregarded it only while a partner
     * was still alive, so any household with a resident older relative was assessed on a home no
     * authority could have charged against.
     *
     * The fixture is the lone-owner one above — the same £300,000 home, the same £10,000 of cash,
     * the same £30,000 income, nobody else in the household — with the one flag set. Without the
     * disregard the equity makes them a self-funder charged the full £80,000 fee; with it, only
     * their own money is assessed and the charge is the funded resident's contribution.
     */
    public function test_a_home_occupied_by_a_qualifying_relative_is_disregarded_from_the_care_means_test(): void
    {
        $home = fn (bool $relative): Property => new Property(
            currentValue: Money::fromPounds(300_000),
            ownership: OwnershipType::Outright,
            occupiedByQualifyingRelative: $relative,
        );

        $charge = fn (bool $relative): int => $this->project(
            new Household(
                'RelativeInResidence', RegionProfile::EnglandWalesNi,
                [$this->person('p1')],
                $this->spend(26_514),
                accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(10_000))],
                incomeStreams: [$this->income('p1')],
                primaryResidence: $home($relative),
            ),
            deathAges: ['p1' => 90],
            careFromAge: ['p1' => 88],
        )->careCostReal()->pence;

        // The gate: without the flag this is the lone owner whose bricks make them a self-funder.
        $this->assertSame(3 * self::FEE_REAL, $charge(false));

        // With a qualifying relative living there the home drops out of the assessment entirely,
        // so the resident is charged what any funded resident is: income less the PEA.
        $this->assertSame(3 * self::CONTRIBUTION, $charge(true));
    }

    /**
     * Board card 0055, criterion 2. `fundShortfall` draws on cash, investments, ISAs and pensions
     * and never on the home, so a self-funding lone homeowner ran an £80,000 care charge every
     * year that nothing could pay: the year failed its essentials and the plan was penalised for
     * keeping a property that in life would simply have carried the debt. What the authority
     * actually offers is a deferred payment secured on the home.
     *
     * The lone-owner fixture again: £10,000 of cash against a £80,000 fee, so the first care year
     * is £70,000 short and the two after it are £80,000 short with nothing left to draw on.
     */
    public function test_an_unfundable_care_charge_is_deferred_against_the_home_not_reported_as_unmet(): void
    {
        $household = new Household(
            'DeferredPayment', RegionProfile::EnglandWalesNi,
            [$this->person('p1')],
            $this->spend(26_514),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(10_000))],
            incomeStreams: [$this->income('p1')],
            primaryResidence: new Property(currentValue: Money::fromPounds(300_000), ownership: OwnershipType::Outright),
        );

        $years = [];
        foreach ($this->project($household, deathAges: ['p1' => 90], careFromAge: ['p1' => 88])->years as $year) {
            $years[$year->calendarYear] = $year;
        }

        // The gate: the household really is being charged the full self-funder fee, and really
        // has run out of liquid assets — this is the state the old code called a plan failure.
        $this->assertSame(self::FEE_REAL, $years[2046]->spendTarget->pence - $years[2045]->spendTarget->pence);
        $this->assertSame(0, $years[2047]->liquidWealth->pence);

        // Nothing is unmet and no essential fails: the shortfall became a debt on the home.
        foreach ([2046, 2047, 2048] as $careYear) {
            $this->assertSame(0, $years[$careYear]->unmetSpend->pence, "{$careYear} unmet spend");
            $this->assertTrue($years[$careYear]->essentialsMet, "{$careYear} essentials met");
        }

        // The first year defers only what its £10,000 of cash could not cover.
        $this->assertSame(self::FEE_REAL - 1_000_000, $years[2046]->deferredCareBalance()->pence);

        // And the home is worth that much less: the debt is secured on it, so the wealth line
        // cannot flatter a household whose home is being spent.
        $this->assertSame(
            $years[2046]->propertyWealth->minus($years[2046]->deferredCareBalance())->pence,
            $years[2046]->homeEquity()->pence,
        );
    }

    /**
     * Board card 0055, criterion 3. The deferred balance is a loan: it accrues interest at the
     * statutory maximum rate and is repaid out of the estate when the resident dies.
     */
    public function test_the_deferred_balance_accrues_interest_and_comes_off_the_estate_at_death(): void
    {
        $household = new Household(
            'DeferredPayment', RegionProfile::EnglandWalesNi,
            [$this->person('p1')],
            $this->spend(26_514),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(10_000))],
            incomeStreams: [$this->income('p1')],
            primaryResidence: new Property(currentValue: Money::fromPounds(300_000), ownership: OwnershipType::Outright),
        );

        $result = $this->project($household, deathAges: ['p1' => 90], careFromAge: ['p1' => 88], modelIht: true);

        $years = [];
        foreach ($result->years as $year) {
            $years[$year->calendarYear] = $year;
        }

        // Year two: last year's balance rolls up at the statutory rate, read from the constant
        // that owns it, and this year's whole £80,000 charge is added on top.
        $rate = DeferredPaymentAgreement::interestRate()->asFraction();
        $this->assertSame(
            (int) round($years[2046]->deferredCareBalance()->pence * (1.0 + $rate)) + self::FEE_REAL,
            $years[2047]->deferredCareBalance()->pence,
            'the balance rolls up before the year is added',
        );
        $this->assertGreaterThan(
            $years[2046]->deferredCareBalance()->pence + self::FEE_REAL,
            $years[2047]->deferredCareBalance()->pence,
            'interest is actually charged, not just the new fees added',
        );

        // The estate holds nothing but the home by now, so the deduction is readable to the penny.
        $last = $years[2048];
        $this->assertSame(0, $last->liquidWealth->pence, 'nothing but the home is left');
        $this->assertSame(0, $last->pensionWealth->pence);
        $this->assertNotNull($result->iht);

        // The estate is settled at the start of the year AFTER the last living one, so the balance
        // has rolled up one final period by then, exactly as the mortgage and the SMI charge do.
        $this->assertSame(
            $last->propertyWealth->pence - (int) round($last->deferredCareBalance()->pence * (1.0 + $rate)),
            $result->iht->secondDeath->totalEstate->pence,
            'the balance standing at death comes off the estate',
        );
        $this->assertLessThan(
            $last->propertyWealth->pence,
            $result->iht->secondDeath->totalEstate->pence,
            'the estate is smaller than the whole home by the debt secured on it',
        );
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
