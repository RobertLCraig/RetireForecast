<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Pension;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Pension\AnnualAllowanceCalculator;
use RetireForecast\FinanceEngine\Pension\FlexibleWithdrawalAssessor;
use RetireForecast\FinanceEngine\Pension\TaxFreeCashCalculator;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\Tax\TaxableIncome;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class AnnualAllowanceCalculatorTest extends TestCase
{
    private function calculator(string $taxYear = '2025-26'): AnnualAllowanceCalculator
    {
        return new AnnualAllowanceCalculator(TaxYearRegistry::for($taxYear));
    }

    public function test_full_allowance_plus_carry_forward_when_no_mpaa_or_taper(): void
    {
        $result = $this->calculator()->assess(
            moneyPurchaseContributions: Money::fromPounds(15_000),
            mpaaTriggered: false,
            adjustedIncome: Money::fromPounds(50_000),
            thresholdIncome: Money::fromPounds(40_000),
            carryForwardAvailable: Money::fromPounds(30_000),
        );

        $this->assertSame(9_000_000, $result->availableAllowance->pence); // £60k + £30k carry-forward
        $this->assertSame(0, $result->excessContributions->pence);
        $this->assertFalse($result->mpaaApplies);
    }

    public function test_taper_reduces_allowance_above_adjusted_income_threshold(): void
    {
        // Adjusted income £280,000 (threshold income also breached): reduce by
        // (£280,000 - £260,000) / 2 = £10,000, giving a £50,000 tapered allowance.
        $result = $this->calculator()->assess(
            moneyPurchaseContributions: Money::zero(),
            mpaaTriggered: false,
            adjustedIncome: Money::fromPounds(280_000),
            thresholdIncome: Money::fromPounds(250_000),
            carryForwardAvailable: Money::zero(),
        );

        $this->assertSame(5_000_000, $result->taperedAllowance->pence);
    }

    public function test_no_taper_when_threshold_income_within_limit(): void
    {
        // Adjusted income is high but threshold income is within £200,000, so the
        // taper does not apply at all.
        $result = $this->calculator()->assess(
            moneyPurchaseContributions: Money::zero(),
            mpaaTriggered: false,
            adjustedIncome: Money::fromPounds(280_000),
            thresholdIncome: Money::fromPounds(150_000),
            carryForwardAvailable: Money::zero(),
        );

        $this->assertSame(6_000_000, $result->taperedAllowance->pence);
    }

    public function test_taper_cannot_fall_below_the_minimum(): void
    {
        $result = $this->calculator()->assess(
            moneyPurchaseContributions: Money::zero(),
            mpaaTriggered: false,
            adjustedIncome: Money::fromPounds(500_000),
            thresholdIncome: Money::fromPounds(450_000),
            carryForwardAvailable: Money::zero(),
        );

        $this->assertSame(1_000_000, $result->taperedAllowance->pence); // £10,000 floor
    }

    /**
     * Worked example B: a £400,000 DC pot. Taking the 25% PCLS (£100,000, within the
     * Lump Sum Allowance) leaves £300,000 in drawdown. Drawing taxable income then
     * triggers the MPAA, so the still-working partner's £15,000 of DC contributions
     * are capped at £10,000, carry-forward is voided, and a £5,000 excess is flagged.
     */
    public function test_worked_example_b_pcls_then_drawdown_triggers_mpaa(): void
    {
        $config = TaxYearRegistry::for('2025-26');

        // Step 1: the 25% PCLS on crystallising the £400k pot, within the LSA.
        $pcls = (new TaxFreeCashCalculator($config))
            ->split(Money::fromPounds(400_000), $config->pension->lumpSumAllowance);
        $this->assertSame(10_000_000, $pcls->taxFree->pence);     // £100,000 tax-free
        $this->assertSame(30_000_000, $pcls->taxable->pence);     // £300,000 into drawdown
        $this->assertFalse($pcls->lsaRestricted);

        // Step 2: drawing taxable drawdown income triggers the MPAA.
        $draw = (new FlexibleWithdrawalAssessor($config))->assessDrawdownIncome(
            gross: Money::fromPounds(20_000),
            otherIncome: TaxableIncome::ofNonSavings(Money::zero()),
            potEmptied: false,
        );
        $this->assertTrue($draw->mpaaTriggered);

        // Step 3: the working partner's contributions are now capped at the MPAA.
        $aa = $this->calculator()->assess(
            moneyPurchaseContributions: Money::fromPounds(15_000),
            mpaaTriggered: $draw->mpaaTriggered,
            adjustedIncome: Money::fromPounds(60_000),
            thresholdIncome: Money::fromPounds(55_000),
            carryForwardAvailable: Money::fromPounds(30_000),
        );

        $this->assertTrue($aa->mpaaApplies);
        $this->assertSame(1_000_000, $aa->availableAllowance->pence); // £10,000 MPAA
        $this->assertSame(0, $aa->carryForwardUsed->pence);           // carry-forward voided
        $this->assertSame(500_000, $aa->excessContributions->pence);  // £5,000 over the MPAA

        $codes = array_map(fn ($w) => $w->code, $aa->warnings);
        $this->assertContains(WarningCode::MPAA_TRIGGERED, $codes);
        $this->assertContains(WarningCode::ANNUAL_ALLOWANCE_EXCEEDED, $codes);
    }
}
