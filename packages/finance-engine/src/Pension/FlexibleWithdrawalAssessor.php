<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\Tax\IncomeTaxCalculator;
use RetireForecast\FinanceEngine\Tax\TaxableIncome;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Assembles the full consequences of a flexible pension withdrawal: the tax-free /
 * taxable split, the tax actually deducted at source (emergency basis on a first
 * withdrawal), the tax truly due at the person's marginal rate, the resulting
 * over-deduction and how to reclaim it, and the warnings the user needs to see.
 *
 * This is the engine behind the flagship "lump-sum tax shock" output.
 */
final class FlexibleWithdrawalAssessor
{
    private readonly IncomeTaxCalculator $incomeTax;

    private readonly EmergencyTaxCalculator $emergencyTax;

    private readonly TaxFreeCashCalculator $taxFreeCash;

    public function __construct(private readonly TaxYearConfig $config)
    {
        $this->incomeTax = new IncomeTaxCalculator($config);
        $this->emergencyTax = new EmergencyTaxCalculator($config);
        $this->taxFreeCash = new TaxFreeCashCalculator($config);
    }

    /**
     * Assess an Uncrystallised Funds Pension Lump Sum (25% tax-free, 75% taxable).
     *
     * @param  Money  $gross  the UFPLS amount taken
     * @param  Money  $lsaRemaining  Lump Sum Allowance left across all pensions
     * @param  TaxableIncome  $otherIncome  the rest of the year's taxable income
     * @param  bool  $potEmptied  whether this empties the pension pot
     * @param  bool  $firstFlexibleAccessInYear  whether the emergency basis applies
     */
    public function assessUfpls(
        Money $gross,
        Money $lsaRemaining,
        TaxableIncome $otherIncome,
        bool $potEmptied,
        bool $firstFlexibleAccessInYear = true,
    ): FlexibleWithdrawalResult {
        $split = $this->taxFreeCash->split($gross, $lsaRemaining);

        return $this->assess(
            kind: WithdrawalKind::Ufpls,
            gross: $gross,
            taxFree: $split->taxFree,
            taxable: $split->taxable,
            lsaUsed: $split->lsaUsed,
            lsaRestricted: $split->lsaRestricted,
            otherIncome: $otherIncome,
            potEmptied: $potEmptied,
            firstFlexibleAccessInYear: $firstFlexibleAccessInYear,
        );
    }

    /**
     * Assess taxable income drawn from flexi-access drawdown. There is no tax-free
     * element here (that was taken as PCLS at crystallisation); the whole payment
     * is taxable and it triggers the MPAA.
     */
    public function assessDrawdownIncome(
        Money $gross,
        TaxableIncome $otherIncome,
        bool $potEmptied,
        bool $firstFlexibleAccessInYear = true,
    ): FlexibleWithdrawalResult {
        return $this->assess(
            kind: WithdrawalKind::DrawdownIncome,
            gross: $gross,
            taxFree: Money::zero(),
            taxable: $gross,
            lsaUsed: Money::zero(),
            lsaRestricted: false,
            otherIncome: $otherIncome,
            potEmptied: $potEmptied,
            firstFlexibleAccessInYear: $firstFlexibleAccessInYear,
        );
    }

    private function assess(
        WithdrawalKind $kind,
        Money $gross,
        Money $taxFree,
        Money $taxable,
        Money $lsaUsed,
        bool $lsaRestricted,
        TaxableIncome $otherIncome,
        bool $potEmptied,
        bool $firstFlexibleAccessInYear,
    ): FlexibleWithdrawalResult {
        $correctMarginalTax = $this->marginalTaxOnExtraPensionIncome($otherIncome, $taxable);

        if ($firstFlexibleAccessInYear) {
            $taxAtSource = $this->emergencyTax->onFlexiblePayment($taxable)->taxDeducted;
            $emergencyApplied = true;
        } else {
            $taxAtSource = $correctMarginalTax;
            $emergencyApplied = false;
        }

        $overDeduction = $taxAtSource->minus($correctMarginalTax)->minZero();

        $reclaimForm = ($emergencyApplied && $overDeduction->isPositive())
            ? ReclaimForm::determine($potEmptied, $otherIncome->total()->isPositive())
            : null;

        $warnings = $this->warnings($kind, $emergencyApplied, $overDeduction, $reclaimForm, $lsaRestricted, $lsaUsed, $gross);

        return new FlexibleWithdrawalResult(
            kind: $kind,
            gross: $gross,
            taxFree: $taxFree,
            taxable: $taxable,
            lsaUsed: $lsaUsed,
            taxDeductedAtSource: $taxAtSource,
            emergencyBasisApplied: $emergencyApplied,
            correctMarginalTax: $correctMarginalTax,
            overDeduction: $overDeduction,
            reclaimForm: $reclaimForm,
            netReceived: $gross->minus($taxAtSource),
            mpaaTriggered: $kind->triggersMpaa(),
            warnings: $warnings,
        );
    }

    /**
     * The extra income tax caused by adding taxable pension income on top of the
     * year's other income. Pension income is non-savings income, so it stacks
     * below savings and dividends and can push them into higher bands too — hence
     * the difference of two full computations rather than a flat marginal rate.
     */
    private function marginalTaxOnExtraPensionIncome(TaxableIncome $otherIncome, Money $extra): Money
    {
        $base = $this->incomeTax->compute($otherIncome)->total;

        $withExtra = $this->incomeTax->compute(new TaxableIncome(
            nonSavings: $otherIncome->nonSavings->plus($extra),
            savings: $otherIncome->savings,
            dividends: $otherIncome->dividends,
        ))->total;

        return $withExtra->minus($base);
    }

    /**
     * @return list<Warning>
     */
    private function warnings(
        WithdrawalKind $kind,
        bool $emergencyApplied,
        Money $overDeduction,
        ?ReclaimForm $reclaimForm,
        bool $lsaRestricted,
        Money $lsaUsed,
        Money $gross,
    ): array {
        $warnings = [];

        if ($emergencyApplied && $overDeduction->isPositive() && $reclaimForm !== null) {
            $warnings[] = new Warning(
                WarningCode::EMERGENCY_TAX,
                'This first flexible withdrawal is taxed on the emergency (Month-1) basis, '
                .'over-deducting '.$overDeduction->format().' of tax at source. '
                .'The excess can be reclaimed from HMRC using form '.$reclaimForm->value.'.',
            );
        }

        if ($kind->triggersMpaa()) {
            $warnings[] = new Warning(
                WarningCode::MPAA_TRIGGERED,
                'Taking taxable pension income this way triggers the Money Purchase Annual '
                .'Allowance, limiting future money-purchase contributions to '
                .$this->config->pension->moneyPurchaseAnnualAllowance->format().' a year.',
            );
        }

        if ($lsaRestricted) {
            $warnings[] = new Warning(
                WarningCode::LSA_EXCEEDED,
                'The tax-free part was capped by the remaining Lump Sum Allowance ('
                .$lsaUsed->format().' used); the balance is taxable.',
            );
        }

        return $warnings;
    }
}
