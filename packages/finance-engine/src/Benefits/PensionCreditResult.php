<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The Pension Credit Guarantee Credit award for one assessment, all weekly.
 *
 * Guarantee Credit tops a pensioner household's assessable income up to the
 * "appropriate minimum guarantee" ({@see $applicableAmountWeekly} — the Standard
 * Minimum Guarantee plus any severe-disability / carer additions). The award is the
 * shortfall of {@see $assessableIncomeWeekly} (the household's income plus the deemed
 * tariff income from its capital) below that guarantee, floored at zero — so a household
 * whose income already meets the guarantee receives nothing.
 *
 * v1 models Guarantee Credit only (not the legacy Savings Credit). Council Tax Reduction
 * and Housing Benefit are not awarded here; their loss above the £16,000 capital limit is
 * surfaced as the {@see CapitalAssessment} cliff warning instead.
 */
final class PensionCreditResult
{
    public function __construct(
        public readonly Money $guaranteeCreditWeekly,
        public readonly Money $applicableAmountWeekly,
        public readonly Money $assessableIncomeWeekly,
        public readonly Money $tariffIncomeWeekly,
    ) {}

    /** The annual Guarantee Credit (weekly × the tax year's weeks). */
    public function guaranteeCreditAnnual(int $weeksPerYear): Money
    {
        return $this->guaranteeCreditWeekly->times($weeksPerYear);
    }
}
