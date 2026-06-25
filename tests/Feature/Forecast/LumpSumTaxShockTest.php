<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\LumpSumTaxShock;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * The headline lump-sum tax-shock panel. The acceptance test reproduces HMRC worked
 * example A end to end through the app service, proving the panel surfaces the engine's
 * penny-accurate figures and does not re-implement the tax logic.
 *
 * The scenario's inputs are the builder form-state (the single source of truth); the
 * engine household is derived from it, so these double as a check that the assembly
 * reproduces the worked example exactly.
 */
class LumpSumTaxShockTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reproduces_worked_example_a_for_the_first_ufpls(): void
    {
        // Worked example A: a £60,000 UFPLS on top of £20,000 other income, 2025/26,
        // England, pot not emptied. The owner is still working (no planned retirement),
        // so the £20,000 salary is the other income.
        $state = [
            'name' => 'Worked Example A',
            'householdName' => 'Worked Example A',
            'region' => 'england_wales_ni',
            'baseTaxYear' => '2025-26',
            'variant' => 'rent',
            'ihtModelled' => false,
            'people' => [
                ['id' => 'p1', 'name' => '', 'dob' => '1965-01-01', 'sex' => 'male', 'employmentStatus' => 'employed',
                    'grossSalary' => '20000', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => ''],
            ],
            'expense' => ['essential' => '18000', 'discretionary' => '6000', 'survivorFactor' => '70'],
            'pensions' => [
                ['ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '400000', 'ongoingContribution' => '',
                    'employerContribution' => '', 'earliestAccessAge' => '55', 'pclsTakenToDate' => '0',
                    'growthAssumptionOverride' => '', 'withdrawals' => [
                        ['kind' => 'ufpls', 'amount' => '60000', 'atAge' => '60'],
                    ]],
            ],
            'hasProperty' => false,
            'housing' => ['salePrice' => '0'],
        ];

        $shock = (new LumpSumTaxShock)->assess($this->scenarioWith($state));

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
        $shock = (new LumpSumTaxShock)->assess(ScenarioFixture::rich(User::factory()->create()));

        $this->assertNotNull($shock);
        $this->assertStringContainsString('UFPLS', $shock['kind']);
        $this->assertSame(67, $shock['atAge']);
    }

    public function test_other_income_is_zero_once_retired_by_the_withdrawal_age(): void
    {
        // The fixture owner plans to retire at 66 but the UFPLS is at 67, so no salary
        // counts as other income that year.
        $shock = (new LumpSumTaxShock)->assess(ScenarioFixture::rich(User::factory()->create()));

        $this->assertFalse($shock['workingAssumed']);
        $this->assertSame('£0.00', $shock['otherIncome']);
    }

    public function test_it_returns_null_when_no_flexible_withdrawal_is_planned(): void
    {
        $state = [
            'name' => 'No flexible withdrawal',
            'householdName' => 'No flexible withdrawal',
            'region' => 'england_wales_ni',
            'baseTaxYear' => '2025-26',
            'variant' => 'rent',
            'ihtModelled' => false,
            'people' => [
                ['id' => 'p1', 'name' => '', 'dob' => '1960-01-01', 'sex' => 'female', 'employmentStatus' => 'retired',
                    'grossSalary' => '', 'salaryGrowth' => '', 'plannedRetirementAge' => '', 'niCategory' => ''],
            ],
            'expense' => ['essential' => '18000', 'discretionary' => '6000', 'survivorFactor' => '70'],
            'pensions' => [
                // Only a tax-free PCLS planned: no taxable withdrawal, no shock.
                ['ownerId' => 'p1', 'subtype' => 'dc', 'currentValue' => '200000', 'ongoingContribution' => '',
                    'employerContribution' => '', 'earliestAccessAge' => '57', 'pclsTakenToDate' => '',
                    'growthAssumptionOverride' => '', 'withdrawals' => [
                        ['kind' => 'pcls', 'amount' => '50000', 'atAge' => '60'],
                    ]],
            ],
            'hasProperty' => false,
            'housing' => ['salePrice' => '0'],
        ];

        $this->assertNull((new LumpSumTaxShock)->assess($this->scenarioWith($state)));
    }

    /** @param array<string, mixed> $state */
    private function scenarioWith(array $state): Scenario
    {
        return ScenarioFixture::fromState(User::factory()->create(), $state);
    }
}
