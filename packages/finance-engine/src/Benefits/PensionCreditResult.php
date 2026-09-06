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
    /**
     * How far above the guarantee still counts as "only just above it", in basis points of the
     * guarantee itself — 10%, which is about £24 a week for a single pensioner and £36 for a
     * couple on the 2026/27 rates.
     *
     * It is a JUDGEMENT, not a published threshold: there is no DWP figure for "close", because
     * the real test is entitlement and only the DWP can settle it. The number is sized to what
     * this engine knowingly leaves out — Savings Credit, every income disregard, and the housing
     * elements — any of which can be the whole of a small gap. It decides only whether a factual
     * claim prompt is SHOWN; no projected figure moves with it, so widening it costs the reader a
     * paragraph and narrowing it can cost them a benefit they were entitled to.
     */
    public const NEAR_MISS_MARGIN_BPS = 1_000;

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

    /**
     * Nothing is awarded, but assessable income is within {@see NEAR_MISS_MARGIN_BPS} of the
     * guarantee — the household a claim prompt is most worth showing, and the one the app used to
     * show nothing at all because it keyed the prompt off a positive award.
     */
    public function isNearMiss(): bool
    {
        if ($this->guaranteeCreditWeekly->isPositive()) {
            return false;
        }

        $ceiling = $this->applicableAmountWeekly->pence
            + (int) round($this->applicableAmountWeekly->pence * self::NEAR_MISS_MARGIN_BPS / 10_000);

        return $this->assessableIncomeWeekly->pence <= $ceiling;
    }

    /**
     * The margin in the reader's words, READ from the constant that owns it so a disclosure and
     * the rule behind it cannot drift (the no-invisible-figures rule).
     */
    public static function nearMissMarginDescription(): string
    {
        return rtrim(rtrim(number_format(self::NEAR_MISS_MARGIN_BPS / 100, 2), '0'), '.').'%';
    }
}
