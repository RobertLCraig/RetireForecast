<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\StatePension;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * A person's State Pension entitlement: the weekly amount (after any deferral
 * uplift) and the annualised figure that flows into the income-tax calculation as
 * taxable non-savings income.
 */
final class StatePensionResult
{
    public function __construct(
        public readonly Money $baseWeekly,
        public readonly Money $deferralUpliftWeekly,
        public readonly Money $weekly,
        public readonly Money $annual,
    ) {}
}
