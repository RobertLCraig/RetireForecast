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
 * The figures: £10,000 disregard, £1 a week of tariff income per £500 above it, and the
 * £16,000 upper limit for Housing Benefit / Council Tax Support (Pension Credit itself
 * has no upper capital limit). Standard Minimum Guarantee 2025/26 single £227.10 / couple
 * £346.60, 2026/27 single £238.00 / couple £363.25; severe-disability addition £82.90 →
 * £86.05; carer addition £46.40 → £48.15 (gov.uk Benefit and pension rates 2025-26 /
 * 2026-27; gov.uk/pension-credit). The date each was read lives in one place only,
 * TaxYearRegistry::verifiedOnBySource() (card 0122).
 *
 * Attendance Allowance and the Carer's Allowance earnings limit came in with card 0106
 * (sources per year in TaxYearRegistry and ASSUMPTIONS §20). Neither reaches a projection
 * on its own: they seed a what-if's editable income stream and a warning.
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
        /**
         * Attendance Allowance, the disability benefit claimed at State Pension age and over.
         * The LOWER rate is help by day or by night; the HIGHER rate is both. Either qualifies
         * the claimant for the severe-disability addition above, so the lower rate is what a
         * cautious projection assumes.
         */
        public readonly Money $attendanceAllowanceLowerWeekly,
        public readonly Money $attendanceAllowanceHigherWeekly,
        /**
         * The weekly net earnings above which Carer's Allowance cannot be awarded. It matters here
         * for what it BLOCKS: earning over it means no underlying entitlement to Carer's Allowance,
         * and so no Pension Credit carer addition, which is why working longer postpones the
         * addition rather than leaving it untouched.
         */
        public readonly Money $carersAllowanceEarningsLimitWeekly,
    ) {}
}
