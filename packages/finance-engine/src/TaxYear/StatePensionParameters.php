<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * State Pension weekly rates and rules for one tax year.
 *
 * Unlike the frozen private-pension allowances, these rise each year under the
 * triple lock, so they genuinely differ between 2025/26 and 2026/27. The annual
 * figure is the weekly rate times {@see $weeksPerYear} (DWP annualises at 52).
 *
 * The State Pension is taxable income: it is non-savings income and is fed through
 * the income-tax calculator like any other pension. With the full new rate now
 * close to the frozen personal allowance, that interaction is a modelled feature.
 */
final class StatePensionParameters
{
    public function __construct(
        public readonly Money $newStatePensionWeekly,
        public readonly Money $basicStatePensionWeekly,
        public readonly int $fullQualifyingYears,
        public readonly int $minimumQualifyingYears,
        public readonly int $weeksPerYear,
        public readonly int $deferralWeeksPerUpliftStep,
        public readonly Percent $deferralUpliftPerStep,
    ) {}
}
