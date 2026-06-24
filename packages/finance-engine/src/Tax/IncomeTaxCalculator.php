<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tax;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Income tax on non-savings, non-dividend income (earnings and pension income)
 * for England, Wales and Northern Ireland.
 *
 * Savings and dividend income are taxed by their own calculators, stacking on
 * top of the bands consumed here; this class exposes {@see remainingBasicRateRoom}
 * and the granted personal allowance so those layers can be added without a
 * rewrite. State Pension is non-savings income and flows through here too.
 */
final class IncomeTaxCalculator
{
    public function __construct(private readonly TaxYearConfig $config)
    {
    }

    /**
     * The personal allowance after the £1-per-£2 taper above the taper threshold.
     * The taper is assessed on total (adjusted) income, which may exceed the
     * non-savings income being taxed once savings and dividends are layered in.
     */
    public function personalAllowance(Money $totalIncome): Money
    {
        $params = $this->config->incomeTax;

        $excess = $totalIncome->minus($params->taperThreshold)->minZero();
        $reduction = $excess->applyRate($params->taperRate, RoundingMode::Floor);

        return $params->personalAllowance->minus($reduction)->minZero();
    }

    /**
     * Tax due on non-savings income.
     *
     * @param Money      $income             the non-savings income to tax
     * @param Money|null $totalIncomeForTaper total adjusted income for the allowance
     *                                        taper; defaults to $income when there is
     *                                        no other income on top
     */
    public function onNonSavingsIncome(Money $income, ?Money $totalIncomeForTaper = null): IncomeTaxResult
    {
        $params = $this->config->incomeTax;
        $totalIncome = $totalIncomeForTaper ?? $income;

        $allowance = $this->personalAllowance($totalIncome);

        $basicLower = $allowance;
        $basicUpper = $allowance->plus($params->basicRateBand);
        $additionalThreshold = $params->additionalRateThreshold;

        $basicAmount = $this->slice($income, $basicLower, $basicUpper);
        $higherAmount = $this->slice($income, $basicUpper, $additionalThreshold);
        $additionalAmount = $this->slice($income, $additionalThreshold, null);

        $basicTax = $basicAmount->applyRate($params->basicRate);
        $higherTax = $higherAmount->applyRate($params->higherRate);
        $additionalTax = $additionalAmount->applyRate($params->additionalRate);

        $total = $basicTax->plus($higherTax)->plus($additionalTax);

        return new IncomeTaxResult(
            total: $total,
            personalAllowance: $allowance,
            bands: [
                ['rate' => $params->basicRate, 'amount' => $basicAmount, 'tax' => $basicTax],
                ['rate' => $params->higherRate, 'amount' => $higherAmount, 'tax' => $higherTax],
                ['rate' => $params->additionalRate, 'amount' => $additionalAmount, 'tax' => $additionalTax],
            ],
        );
    }

    /**
     * The slice of $income that falls between $lower and $upper (null = no upper
     * bound), never negative.
     */
    private function slice(Money $income, Money $lower, ?Money $upper): Money
    {
        $top = $upper === null ? $income : Money::min($income, $upper);

        return $top->minus($lower)->minZero();
    }
}
