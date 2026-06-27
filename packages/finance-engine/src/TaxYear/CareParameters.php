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
 * Verified on 2026-06-27 (gov.uk + DHSC charging-for-care guidance 2025-26): the
 * £23,250 and £14,250 limits and the £1-a-week-per-£250 tariff are frozen (15th year
 * running). The £86,000 lifetime care cap was cancelled in July 2024, so it is
 * deliberately NOT modelled.
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
