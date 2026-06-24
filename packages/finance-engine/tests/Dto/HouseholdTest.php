<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Dto;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\PensionType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

final class HouseholdTest extends TestCase
{
    private function household(): Household
    {
        $working = new Person(
            id: 'p1',
            dob: new DateTimeImmutable('1962-05-01'),
            sex: Sex::Female,
            employmentStatus: EmploymentStatus::Employed,
            grossSalary: Money::fromPounds(35_000),
            plannedRetirementAge: 66,
        );
        $retired = new Person(
            id: 'p2',
            dob: new DateTimeImmutable('1959-09-01'),
            sex: Sex::Male,
            employmentStatus: EmploymentStatus::Retired,
        );

        return new Household(
            name: 'Test couple',
            region: RegionProfile::EnglandWalesNi,
            persons: [$working, $retired],
            expenseProfile: new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(24_000),
                discretionaryAnnualSpend: Money::fromPounds(8_000),
                survivorSpendFactor: Percent::fromPercent(70),
            ),
            pensions: [
                new DcPension(
                    ownerId: 'p2',
                    currentValue: Money::fromPounds(300_000),
                    ongoingContribution: Money::zero(),
                    employerContribution: Money::zero(),
                    earliestAccessAge: 55,
                    withdrawalPlan: [
                        new WithdrawalInstruction(WithdrawalKind::Ufpls, Money::fromPounds(20_000), 67),
                    ],
                ),
                new StatePensionEntitlement(ownerId: 'p2', weeklyForecast: Money::of(230, 25)),
            ],
            accounts: [
                new Account('p1', AccountType::Isa, Money::fromPounds(40_000)),
            ],
        );
    }

    public function test_household_assembles_and_resolves_people(): void
    {
        $household = $this->household();

        $this->assertCount(2, $household->persons);
        $this->assertSame('p1', $household->person('p1')?->id);
        $this->assertNull($household->person('nobody'));
    }

    public function test_expense_profile_totals_essential_plus_discretionary(): void
    {
        $this->assertSame(3_200_000, $this->household()->expenseProfile->targetAnnualSpend()->pence);
    }

    public function test_pensions_carry_their_type_and_owner(): void
    {
        $pensions = $this->household()->pensions;

        $this->assertSame(PensionType::DefinedContribution, $pensions[0]->type());
        $this->assertSame('p2', $pensions[0]->ownerId());
        $this->assertSame(PensionType::State, $pensions[1]->type());
    }
}
