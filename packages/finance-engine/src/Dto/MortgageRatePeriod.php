<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Percent;

/**
 * One rate tier of a repayment mortgage: a NOMINAL annual interest rate that applies for
 * $months monthly payments, after which the next tier takes over.
 *
 * A lender quotes a mortgage as an initial deal rate for a fixed number of months followed by
 * a reversion rate (typically the Standard Variable Rate) for the rest of the term — e.g. the
 * ESIS "6.23% fixed for 60 months, then 7.24% for the remaining 132". Each tier recomputes the
 * monthly payment as the annuity that clears the THEN-outstanding balance over the REMAINING
 * term at the tier's rate, which is exactly how the payment step in a lender's illustration is
 * derived.
 *
 * $months null means "to the end of the term" — the reversion tier, which must be last. The rate
 * is nominal annual, divided by 12 for the monthly rate (the UK lender convention), NOT an
 * effective annual rate compounded to a monthly equivalent.
 */
final class MortgageRatePeriod
{
    public function __construct(
        public readonly Percent $annualRate,
        public readonly ?int $months = null,
    ) {
        if ($months !== null && $months < 1) {
            throw new \InvalidArgumentException('A mortgage rate period must run for at least one month.');
        }
    }
}
