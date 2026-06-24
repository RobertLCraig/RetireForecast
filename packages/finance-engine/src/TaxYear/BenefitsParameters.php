<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * Means-tested benefit capital rules for pension-age claimants, for one tax year.
 *
 * These are the pensioner rules (not the working-age £6,000 / £250 version):
 *  - the first £10,000 of capital is disregarded;
 *  - above that, every £500 (or part) is treated as £1 a week of "tariff" income;
 *  - Pension Credit has no upper capital limit, but Housing Benefit and Council Tax
 *    Support stop entirely once capital reaches the £16,000 limit.
 *
 * This is the heart of the downsizing trap: selling the home converts an exempt
 * asset into assessable capital, which can create tariff income and tip the
 * household over the £16,000 cliff.
 *
 * ⚠️ Confirm the £16,000 limit's exact boundary treatment and any uprating of these
 * figures against gov.uk before showing them as real.
 */
final class BenefitsParameters
{
    public function __construct(
        public readonly Money $capitalDisregard,
        public readonly Money $tariffStep,
        public readonly Money $tariffIncomePerStepWeekly,
        public readonly Money $housingSupportUpperCapitalLimit,
    ) {}
}
