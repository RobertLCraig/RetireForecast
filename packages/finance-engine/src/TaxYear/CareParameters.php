<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * Adult social care means-test capital thresholds (England), for one tax year.
 *
 *  - above the upper limit (£23,250) a person is a self-funder and pays full fees;
 *  - below the lower limit (£14,250) capital is ignored (income is still assessed);
 *  - between the two, every £250 of capital is treated as £1 a week of income.
 *
 * ⚠️ The promised £86,000 lifetime care cap appears scrapped or indefinitely
 * delayed, so it is deliberately NOT modelled. Confirm these thresholds and the cap
 * position against gov.uk before showing them as real.
 */
final class CareParameters
{
    public function __construct(
        public readonly Money $upperCapitalLimit,
        public readonly Money $lowerCapitalLimit,
        public readonly Money $tariffStep,
        public readonly Money $tariffIncomePerStepWeekly,
    ) {}
}
