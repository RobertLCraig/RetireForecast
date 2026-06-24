<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Works out how much can go into money-purchase pension savings in a year without
 * an annual allowance charge, and flags the pitfalls that bite the working partner.
 *
 * Three things reduce the headline £60,000 allowance:
 *  - the high-income taper (£1 lost per £2 of adjusted income above £260,000, but
 *    only if threshold income also exceeds £200,000, down to a £10,000 floor);
 *  - the Money Purchase Annual Allowance: once pension savings are flexibly
 *    accessed, money-purchase contributions are capped at £10,000 and carry-forward
 *    can no longer be used for them;
 *  - carry-forward of unused allowance from the previous three years (which the
 *    MPAA disables).
 *
 * This is the trap behind worked example B: a retired partner drawing taxable
 * income triggers the MPAA, so if the still-working partner is paying more than
 * £10,000 into a DC pension they unexpectedly face a charge.
 */
final class AnnualAllowanceCalculator
{
    public function __construct(private readonly TaxYearConfig $config) {}

    public function assess(
        Money $moneyPurchaseContributions,
        bool $mpaaTriggered,
        Money $adjustedIncome,
        Money $thresholdIncome,
        Money $carryForwardAvailable,
    ): AnnualAllowanceResult {
        $params = $this->config->pension;

        $taperedAllowance = $this->taperedAllowance($adjustedIncome, $thresholdIncome);

        $warnings = [];

        if ($mpaaTriggered) {
            // The MPAA replaces the tapered allowance for money purchase and bars carry-forward.
            $available = $params->moneyPurchaseAnnualAllowance;
            $carryForwardUsed = Money::zero();
            $warnings[] = new Warning(
                WarningCode::MPAA_TRIGGERED,
                'Flexible pension access has triggered the Money Purchase Annual Allowance: '
                .'money-purchase contributions are now limited to '
                .$params->moneyPurchaseAnnualAllowance->format()
                .' a year, and carry-forward of unused allowance no longer applies to them.',
            );
        } else {
            $carryForwardUsed = $carryForwardAvailable;
            $available = $taperedAllowance->plus($carryForwardAvailable);
        }

        $excess = $moneyPurchaseContributions->minus($available)->minZero();

        if ($excess->isPositive()) {
            $warnings[] = new Warning(
                WarningCode::ANNUAL_ALLOWANCE_EXCEEDED,
                'Money-purchase contributions of '.$moneyPurchaseContributions->format()
                .' exceed the available allowance of '.$available->format()
                .' by '.$excess->format()
                .', which is subject to an annual allowance charge at your marginal rate.',
            );
        }

        return new AnnualAllowanceResult(
            availableAllowance: $available,
            taperedAllowance: $taperedAllowance,
            mpaaApplies: $mpaaTriggered,
            carryForwardUsed: $carryForwardUsed,
            excessContributions: $excess,
            warnings: $warnings,
        );
    }

    /**
     * The £60,000 allowance after the high-income taper. The taper only applies
     * when threshold income exceeds its limit; then adjusted income above its
     * threshold reduces the allowance by £1 per £2, down to the £10,000 floor.
     */
    private function taperedAllowance(Money $adjustedIncome, Money $thresholdIncome): Money
    {
        $params = $this->config->pension;

        if ($thresholdIncome->lessThanOrEqual($params->taperedAaThresholdIncomeLimit)) {
            return $params->annualAllowance;
        }

        $excess = $adjustedIncome->minus($params->taperedAaAdjustedIncomeThreshold)->minZero();
        if ($excess->isZero()) {
            return $params->annualAllowance;
        }

        $reduction = $excess->applyRate($params->taperRate, RoundingMode::Floor);
        $tapered = $params->annualAllowance->minus($reduction);

        return Money::max($tapered, $params->taperedAaMinimum);
    }
}
