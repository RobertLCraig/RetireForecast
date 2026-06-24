<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Tax deducted at source on a FIRST flexible pension withdrawal, under the
 * emergency (Month-1, non-cumulative) PAYE basis.
 *
 * Because the provider has no cumulative tax code for a first withdrawal, HMRC
 * requires it to tax the payment as if it were 1/12 of an annual income: only one
 * twelfth of the personal allowance and of each rate band is applied to the single
 * payment, and the rest is taxed at the top rates. A large one-off withdrawal is
 * therefore massively over-taxed up front, and the excess must be reclaimed (see
 * {@see ReclaimForm}).
 *
 * This models the magnitude of that over-deduction, which is the point of the
 * "tax shock" illustration. Real PAYE uses statutory tax tables whose rounding can
 * differ from this by a few pounds; the figure here is the modelled deduction, not
 * a promise of HMRC's table to the penny.
 */
final class EmergencyTaxCalculator
{
    private const MONTHS = 12;

    public function __construct(private readonly TaxYearConfig $config) {}

    public function onFlexiblePayment(Money $taxablePayment): EmergencyTaxResult
    {
        $params = $this->config->incomeTax;

        // The annual thresholds, divided into one month.
        $monthlyAllowance = $params->personalAllowance->dividedBy(self::MONTHS);
        $monthlyBasicCeiling = $monthlyAllowance->plus($params->basicRateBand->dividedBy(self::MONTHS));
        $monthlyAdditionalThreshold = $params->additionalRateThreshold->dividedBy(self::MONTHS);

        $basicAmount = $this->slice($taxablePayment, $monthlyAllowance, $monthlyBasicCeiling);
        $higherAmount = $this->slice($taxablePayment, $monthlyBasicCeiling, $monthlyAdditionalThreshold);
        $additionalAmount = $this->slice($taxablePayment, $monthlyAdditionalThreshold, null);

        $basicTax = $basicAmount->applyRate($params->basicRate);
        $higherTax = $higherAmount->applyRate($params->higherRate);
        $additionalTax = $additionalAmount->applyRate($params->additionalRate);

        return new EmergencyTaxResult(
            taxablePayment: $taxablePayment,
            taxDeducted: $basicTax->plus($higherTax)->plus($additionalTax),
            bands: [
                ['rate' => $params->basicRate, 'amount' => $basicAmount, 'tax' => $basicTax],
                ['rate' => $params->higherRate, 'amount' => $higherAmount, 'tax' => $higherTax],
                ['rate' => $params->additionalRate, 'amount' => $additionalAmount, 'tax' => $additionalTax],
            ],
        );
    }

    private function slice(Money $amount, Money $lower, ?Money $upper): Money
    {
        $top = $upper === null ? $amount : Money::min($amount, $upper);

        return $top->minus($lower)->minZero();
    }
}
