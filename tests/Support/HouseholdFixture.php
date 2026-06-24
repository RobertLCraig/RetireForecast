<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

/**
 * A deliberately rich household DTO that exercises every nested DTO and every
 * optional field, so the mapping round-trip tests cover the whole shape: two
 * people, all three pension subtypes (with a withdrawal plan), three account
 * types, an income stream, an expense profile with a one-off cost, and a property.
 *
 * Dates are built with the same parser the codec uses, so a round-tripped DTO is
 * equal under == regardless of the server timezone.
 */
final class HouseholdFixture
{
    public static function date(string $iso): DateTimeImmutable
    {
        return DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
    }

    public static function household(): Household
    {
        return new Household(
            name: 'The Worked-Example Couple',
            region: RegionProfile::EnglandWalesNi,
            persons: [
                new Person(
                    id: 'p1',
                    dob: self::date('1961-04-02'),
                    sex: Sex::Male,
                    employmentStatus: EmploymentStatus::Employed,
                    grossSalary: Money::fromPounds(62_000),
                    salaryGrowth: Percent::fromPercent(2.5),
                    plannedRetirementAge: 66,
                    niCategory: 'A',
                ),
                new Person(
                    id: 'p2',
                    dob: self::date('1963-11-20'),
                    sex: Sex::Female,
                    employmentStatus: EmploymentStatus::Retired,
                ),
            ],
            expenseProfile: new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(28_000),
                discretionaryAnnualSpend: Money::fromPounds(12_500),
                survivorSpendFactor: Percent::fromPercent(70),
                oneOffCosts: [
                    ['atAge' => 80, 'amount' => Money::fromPounds(45_000), 'label' => 'Care top-up'],
                ],
            ),
            pensions: [
                new DcPension(
                    ownerId: 'p1',
                    currentValue: Money::fromPounds(410_000),
                    ongoingContribution: Money::fromPounds(8_000),
                    employerContribution: Money::fromPounds(4_000),
                    earliestAccessAge: 57,
                    withdrawalPlan: [
                        new WithdrawalInstruction(WithdrawalKind::Pcls, Money::fromPounds(100_000), 66),
                        new WithdrawalInstruction(WithdrawalKind::Ufpls, Money::fromPounds(20_000), 67),
                        new WithdrawalInstruction(WithdrawalKind::DrawdownIncome, Money::fromPounds(15_000), 68),
                    ],
                    pclsTakenToDate: Money::fromPounds(0),
                    growthAssumptionOverride: Percent::fromPercent(4.5),
                ),
                new DbPension(
                    ownerId: 'p2',
                    accruedAnnualPension: Money::fromPounds(9_200),
                    normalRetirementAge: 65,
                    revaluationBasis: PensionEscalationBasis::Cpi,
                    escalationInPayment: PensionEscalationBasis::CpiCappedAt5,
                    spousePensionFraction: Percent::fromPercent(50),
                    commutationLumpSum: Money::fromPounds(30_000),
                    commutationFactor: Percent::fromPercent(1_200),
                ),
                new StatePensionEntitlement(
                    ownerId: 'p1',
                    weeklyForecast: Money::of(230, 25),
                    deferralWeeks: 0,
                ),
                new StatePensionEntitlement(
                    ownerId: 'p2',
                    qualifyingYears: 34,
                    deferralWeeks: 8,
                ),
            ],
            accounts: [
                new Account('p1', AccountType::Isa, Money::fromPounds(85_000), yield: Percent::fromPercent(3)),
                new Account('p2', AccountType::Gia, Money::fromPounds(40_000), unrealisedGain: Money::fromPounds(6_500), yield: Percent::fromPercent(2)),
                new Account('p1', AccountType::Cash, Money::fromPounds(20_000)),
            ],
            incomeStreams: [
                new IncomeStream('p2', IncomeStreamType::Rental, Money::fromPounds(7_200), taxable: true, inflationLinked: true, startAge: 60, endAge: 90),
            ],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(525_000),
                ownership: OwnershipType::Mortgaged,
                isPrimaryResidence: true,
                everLet: false,
                outstandingMortgage: Money::fromPounds(48_000),
                runningCosts: Money::fromPounds(6_400),
                growthAssumptionOverride: Percent::fromPercent(1),
                ownershipShare: Percent::fromPercent(100),
            ),
        );
    }

    public static function housingAction(): HousingAction
    {
        return new HousingAction(
            salePrice: Money::fromPounds(525_000),
            buyPrice: Money::fromPounds(320_000),
            annualRent: Money::fromPounds(18_000),
            rentInflationReal: Percent::fromPercent(0.5),
            movingCosts: Money::fromPounds(9_500),
            sellingCostRate: Percent::fromPercent(1.5),
        );
    }
}
