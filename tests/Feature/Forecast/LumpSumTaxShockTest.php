<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Enums\ScenarioStatus;
use App\Enums\ScenarioVariant;
use App\Forecast\LumpSumTaxShock;
use App\Models\Household;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household as HouseholdDto;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use Tests\Support\HouseholdFixture;
use Tests\TestCase;

/**
 * The headline lump-sum tax-shock panel. The acceptance test reproduces HMRC worked
 * example A end to end through the app service, proving the panel surfaces the engine's
 * penny-accurate figures and does not re-implement the tax logic.
 */
class LumpSumTaxShockTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reproduces_worked_example_a_for_the_first_ufpls(): void
    {
        // Worked example A: a £60,000 UFPLS on top of £20,000 other income, 2025/26,
        // England, pot not emptied. The owner is still working (no planned retirement),
        // so the £20,000 salary is the other income.
        $dto = new HouseholdDto(
            name: 'Worked Example A',
            region: RegionProfile::EnglandWalesNi,
            persons: [
                new Person(
                    id: 'p1',
                    dob: HouseholdFixture::date('1965-01-01'),
                    sex: Sex::Male,
                    employmentStatus: EmploymentStatus::Employed,
                    grossSalary: Money::fromPounds(20_000),
                ),
            ],
            expenseProfile: new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(18_000),
                discretionaryAnnualSpend: Money::fromPounds(6_000),
                survivorSpendFactor: Percent::fromPercent(70),
            ),
            pensions: [
                new DcPension(
                    ownerId: 'p1',
                    currentValue: Money::fromPounds(400_000),
                    ongoingContribution: Money::zero(),
                    employerContribution: Money::zero(),
                    earliestAccessAge: 55,
                    withdrawalPlan: [
                        new WithdrawalInstruction(WithdrawalKind::Ufpls, Money::fromPounds(60_000), 60),
                    ],
                    pclsTakenToDate: Money::fromPounds(0),
                ),
            ],
        );

        $shock = (new LumpSumTaxShock)->assess($this->scenarioWith($dto, '2025-26'));

        $this->assertNotNull($shock);
        $this->assertSame(1_500_000, $shock['raw']['taxFreePence'], '25% tax-free');
        $this->assertSame(4_500_000, $shock['raw']['taxablePence'], '75% taxable');
        $this->assertSame(1_868_124, $shock['raw']['taxAtSourcePence'], 'emergency tax deducted');
        $this->assertSame(1_194_600, $shock['raw']['marginalTaxPence'], 'true marginal tax');
        $this->assertSame(673_524, $shock['raw']['overDeductionPence'], 'reclaimable over-deduction');
        $this->assertSame('P55', $shock['reclaimForm']);
        $this->assertTrue($shock['mpaaTriggered']);
        $this->assertTrue($shock['workingAssumed']);
        $this->assertSame('£20,000.00', $shock['otherIncome']);
    }

    public function test_it_picks_the_earliest_flexible_withdrawal_and_skips_pcls(): void
    {
        // The rich fixture plans a PCLS at 66, a UFPLS at 67 and drawdown at 68. The
        // first *flexible* withdrawal (the one the emergency basis hits) is the UFPLS.
        $shock = (new LumpSumTaxShock)->assess($this->scenarioFromFixture('2026-27'));

        $this->assertNotNull($shock);
        $this->assertStringContainsString('UFPLS', $shock['kind']);
        $this->assertSame(67, $shock['atAge']);
    }

    public function test_other_income_is_zero_once_retired_by_the_withdrawal_age(): void
    {
        // The fixture owner plans to retire at 66 but the UFPLS is at 67, so no salary
        // counts as other income that year.
        $shock = (new LumpSumTaxShock)->assess($this->scenarioFromFixture('2026-27'));

        $this->assertFalse($shock['workingAssumed']);
        $this->assertSame('£0.00', $shock['otherIncome']);
    }

    public function test_it_returns_null_when_no_flexible_withdrawal_is_planned(): void
    {
        $dto = new HouseholdDto(
            name: 'No flexible withdrawal',
            region: RegionProfile::EnglandWalesNi,
            persons: [
                new Person('p1', HouseholdFixture::date('1960-01-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            expenseProfile: new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(18_000),
                discretionaryAnnualSpend: Money::fromPounds(6_000),
                survivorSpendFactor: Percent::fromPercent(70),
            ),
            pensions: [
                new DcPension(
                    ownerId: 'p1',
                    currentValue: Money::fromPounds(200_000),
                    ongoingContribution: Money::zero(),
                    employerContribution: Money::zero(),
                    earliestAccessAge: 57,
                    // Only a tax-free PCLS planned: no taxable withdrawal, no shock.
                    withdrawalPlan: [
                        new WithdrawalInstruction(WithdrawalKind::Pcls, Money::fromPounds(50_000), 60),
                    ],
                ),
            ],
        );

        $this->assertNull((new LumpSumTaxShock)->assess($this->scenarioWith($dto, '2025-26')));
    }

    private function scenarioFromFixture(string $taxYear): Scenario
    {
        return $this->scenarioWith(HouseholdFixture::household(), $taxYear);
    }

    private function scenarioWith(HouseholdDto $dto, string $taxYear): Scenario
    {
        $user = User::factory()->create();
        $household = Household::fromDto($dto, $user->id);
        $household->save();

        $scenario = new Scenario([
            'household_id' => $household->id,
            'user_id' => $user->id,
            'name' => 'Tax shock',
            'variant' => ScenarioVariant::Rent,
            'base_tax_year' => $taxYear,
            'iht_modelled' => false,
            'status' => ScenarioStatus::Ready,
        ]);
        $scenario->setHousingAction(HouseholdFixture::housingAction());
        $scenario->save();

        return $scenario->fresh();
    }
}
