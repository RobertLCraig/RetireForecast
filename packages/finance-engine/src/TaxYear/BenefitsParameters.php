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
 * The Guarantee Credit figures (the appropriate minimum guarantee the means test tops
 * income up to, plus the severe-disability and carer additions) are uprated each year —
 * unlike the static capital rules — so they differ between tax years.
 *
 * Verified against gov.uk on 2026-06-27 (capital rules) and 2026-06-30 (Guarantee Credit
 * figures): £10,000 disregard, £1 a week of tariff income per £500 above it, and the
 * £16,000 upper limit for Housing Benefit / Council Tax Support (Pension Credit itself
 * has no upper capital limit). Standard Minimum Guarantee 2025/26 single £227.10 / couple
 * £346.60, 2026/27 single £238.00 / couple £363.25; severe-disability addition £82.90 →
 * £86.05; carer addition £46.40 → £48.15 (gov.uk Benefit and pension rates 2025-26 /
 * 2026-27; gov.uk/pension-credit).
 */
final class BenefitsParameters
{
    public function __construct(
        public readonly Money $capitalDisregard,
        public readonly Money $tariffStep,
        public readonly Money $tariffIncomePerStepWeekly,
        public readonly Money $housingSupportUpperCapitalLimit,
        public readonly Money $standardMinimumGuaranteeSingleWeekly,
        public readonly Money $standardMinimumGuaranteeCoupleWeekly,
        public readonly Money $severeDisabilityAdditionWeekly,
        public readonly Money $carerAdditionWeekly,
    ) {}
}
