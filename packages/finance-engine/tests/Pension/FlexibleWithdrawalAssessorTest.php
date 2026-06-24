<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Pension;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Pension\EmergencyTaxCalculator;
use RetireForecast\FinanceEngine\Pension\FlexibleWithdrawalAssessor;
use RetireForecast\FinanceEngine\Pension\ReclaimForm;
use RetireForecast\FinanceEngine\Pension\TaxFreeCashCalculator;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\Tax\TaxableIncome;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class FlexibleWithdrawalAssessorTest extends TestCase
{
    private function fullLsa(): Money
    {
        return TaxYearRegistry::for('2025-26')->pension->lumpSumAllowance;
    }

    public function test_tax_free_cash_splits_at_twenty_five_percent(): void
    {
        $split = (new TaxFreeCashCalculator(TaxYearRegistry::for('2025-26')))
            ->split(Money::fromPounds(60_000), $this->fullLsa());

        $this->assertSame(1_500_000, $split->taxFree->pence);
        $this->assertSame(4_500_000, $split->taxable->pence);
        $this->assertFalse($split->lsaRestricted);
    }

    public function test_tax_free_cash_restricted_by_lump_sum_allowance(): void
    {
        // Only £50,000 of LSA left against a £400,000 crystallisation whose 25%
        // would be £100,000: tax-free is capped at £50,000, the rest is taxable.
        $split = (new TaxFreeCashCalculator(TaxYearRegistry::for('2025-26')))
            ->split(Money::fromPounds(400_000), Money::fromPounds(50_000));

        $this->assertSame(5_000_000, $split->taxFree->pence);
        $this->assertSame(35_000_000, $split->taxable->pence);
        $this->assertTrue($split->lsaRestricted);
    }

    public function test_emergency_tax_over_deducts_on_a_large_payment(): void
    {
        // £45,000 taxable on the Month-1 basis: 1/12 of each band applied once,
        // the rest at 40%/45%. Models the deduction at £18,681.24.
        $result = (new EmergencyTaxCalculator(TaxYearRegistry::for('2025-26')))
            ->onFlexiblePayment(Money::fromPounds(45_000));

        $this->assertSame(1_868_124, $result->taxDeducted->pence);
    }

    /**
     * Worked example A: a £60,000 UFPLS for someone with £20,000 of other income,
     * 2025/26, England. £15,000 tax-free + £45,000 taxable. The provider applies the
     * emergency basis (over-deducting), the true marginal tax is far lower, and the
     * excess is reclaimed on form P55 because the pot was not emptied.
     */
    public function test_worked_example_a_ufpls_emergency_tax_and_reclaim(): void
    {
        $result = (new FlexibleWithdrawalAssessor(TaxYearRegistry::for('2025-26')))->assessUfpls(
            gross: Money::fromPounds(60_000),
            lsaRemaining: $this->fullLsa(),
            otherIncome: TaxableIncome::ofNonSavings(Money::fromPounds(20_000)),
            potEmptied: false,
        );

        $this->assertSame(1_500_000, $result->taxFree->pence, '25% tax-free');
        $this->assertSame(4_500_000, $result->taxable->pence, '75% taxable');
        $this->assertSame(1_868_124, $result->taxDeductedAtSource->pence, 'emergency tax deducted');
        $this->assertSame(1_194_600, $result->correctMarginalTax->pence, 'true marginal tax');
        $this->assertSame(673_524, $result->overDeduction->pence, 'reclaimable over-deduction');
        $this->assertSame(ReclaimForm::P55, $result->reclaimForm);
        $this->assertSame(4_131_876, $result->netReceived->pence, 'cash in hand before reclaim');
        $this->assertTrue($result->mpaaTriggered);

        $codes = array_map(fn ($w) => $w->code, $result->warnings);
        $this->assertContains(WarningCode::EMERGENCY_TAX, $codes);
        $this->assertContains(WarningCode::MPAA_TRIGGERED, $codes);
    }

    public function test_reclaim_form_p53_z_when_pot_emptied_with_other_income(): void
    {
        $result = (new FlexibleWithdrawalAssessor(TaxYearRegistry::for('2025-26')))->assessUfpls(
            gross: Money::fromPounds(60_000),
            lsaRemaining: $this->fullLsa(),
            otherIncome: TaxableIncome::ofNonSavings(Money::fromPounds(20_000)),
            potEmptied: true,
        );

        $this->assertSame(ReclaimForm::P53Z, $result->reclaimForm);
    }

    public function test_reclaim_form_p50_z_when_pot_emptied_with_no_other_income(): void
    {
        $result = (new FlexibleWithdrawalAssessor(TaxYearRegistry::for('2025-26')))->assessUfpls(
            gross: Money::fromPounds(60_000),
            lsaRemaining: $this->fullLsa(),
            otherIncome: TaxableIncome::ofNonSavings(Money::zero()),
            potEmptied: true,
        );

        $this->assertSame(ReclaimForm::P50Z, $result->reclaimForm);
    }

    public function test_subsequent_withdrawal_uses_correct_code_not_emergency_basis(): void
    {
        // Not the first access this year: tax at source equals the true marginal tax,
        // so there is nothing to reclaim.
        $result = (new FlexibleWithdrawalAssessor(TaxYearRegistry::for('2025-26')))->assessUfpls(
            gross: Money::fromPounds(60_000),
            lsaRemaining: $this->fullLsa(),
            otherIncome: TaxableIncome::ofNonSavings(Money::fromPounds(20_000)),
            potEmptied: false,
            firstFlexibleAccessInYear: false,
        );

        $this->assertFalse($result->emergencyBasisApplied);
        $this->assertSame(0, $result->overDeduction->pence);
        $this->assertNull($result->reclaimForm);
        $this->assertSame($result->correctMarginalTax->pence, $result->taxDeductedAtSource->pence);
    }

    public function test_pcls_alone_does_not_trigger_mpaa(): void
    {
        $this->assertFalse(WithdrawalKind::Pcls->triggersMpaa());
        $this->assertTrue(WithdrawalKind::Ufpls->triggersMpaa());
        $this->assertTrue(WithdrawalKind::DrawdownIncome->triggersMpaa());
    }
}
