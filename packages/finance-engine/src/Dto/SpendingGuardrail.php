<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A state-dependent spending rule: the household cuts its DISCRETIONARY spend when the plan is
 * no longer funded, and puts it back when it is (board card 0063).
 *
 * Without one, every path is scored against a fixed real spending target, and no real household
 * carries on spending into insolvency. That overstates the chance of running out. It also hides
 * the cheapest mitigation there is — trimming discretionary spend after a bad run costs nothing
 * and buys real survival probability — UNLESS there is no discretionary spend left to trim, in
 * which case that is itself the finding, and a model with no guardrail cannot state it.
 *
 * **The rule is a funded ratio**, the cheapest honest version: usable wealth (liquid + pension, so
 * the home a household lives in is never counted as spendable) divided by the essential spend still
 * to be funded
 * (this year's essential floor times the years the plan has left to run). Below the trigger the
 * discretionary spend is cut by {@see discretionaryCut}; the test is re-run every year off that
 * year's own wealth, so recovery restores the spend by itself.
 *
 * Both figures are the reader's, and both are OPTIONAL: null takes the constant beside it, which
 * the presenter discloses (the no-invisible-figures rule). The guardrail as a whole is opt-in —
 * a household with no {@see ExpenseProfile::$spendingGuardrail} spends the same in real terms
 * whatever happens, exactly as before — because a guardrail only ever makes a plan look better,
 * and the adverse default is the one that does not.
 */
final class SpendingGuardrail
{
    /**
     * The funded ratio at which the guardrail starts to bite: **1.00**, i.e. usable wealth below
     * the essential spend the plan still has to fund.
     *
     * A funded ratio of 1 is the standard actuarial definition of a fully funded plan: assets
     * equal to the liability they have to meet. Triggering there rather than above it is the
     * adverse of the defensible choices — a guardrail that bites earlier protects the plan
     * sooner, so it flatters the result, and this one waits until the plan is genuinely short.
     *
     * **STATED, not verified** (no web in the session that built this): see
     * docs/spec/ASSUMPTIONS.md §34, board card 0138.
     */
    public const DEFAULT_TRIGGER_FUNDED_RATIO_BPS = 10_000; // 1.00x

    /**
     * How much of the discretionary spend comes off while the guardrail bites: **10%**.
     *
     * That is the size of the cut in the published capital-preservation rule of Guyton and
     * Klinger's decision-rules work, which is the citable ancestor of the guardrails in the
     * retail tools. It is deliberately small: a 10% trim of discretionary spend is a change a
     * household could really make and keep, and a bigger one would buy survival probability the
     * plan has not earned.
     *
     * **STATED, not verified** (no web in the session that built this): see
     * docs/spec/ASSUMPTIONS.md §34, board card 0138.
     */
    public const DEFAULT_DISCRETIONARY_CUT_BPS = 1_000; // 10%

    public function __construct(
        /** The funded ratio below which the cut applies; null = {@see DEFAULT_TRIGGER_FUNDED_RATIO_BPS}. */
        public readonly ?Percent $triggerFundedRatio = null,
        /** The proportion of discretionary spend cut while it bites; null = {@see DEFAULT_DISCRETIONARY_CUT_BPS}. */
        public readonly ?Percent $discretionaryCut = null,
    ) {}

    /** The trigger actually in force: the reader's, or the constant that owns the default. */
    public function triggerFundedRatio(): Percent
    {
        return $this->triggerFundedRatio ?? Percent::fromBasisPoints(self::DEFAULT_TRIGGER_FUNDED_RATIO_BPS);
    }

    /** The cut actually in force: the reader's, or the constant that owns the default. */
    public function discretionaryCut(): Percent
    {
        return $this->discretionaryCut ?? Percent::fromBasisPoints(self::DEFAULT_DISCRETIONARY_CUT_BPS);
    }

    /** Is the trigger the ENGINE's own figure, so a presenter must disclose it? */
    public function triggerIsAssumed(): bool
    {
        return $this->triggerFundedRatio === null;
    }

    /** Is the cut the ENGINE's own figure, so a presenter must disclose it? */
    public function cutIsAssumed(): bool
    {
        return $this->discretionaryCut === null;
    }

    /**
     * Does the guardrail bite this year? True when usable wealth is below the trigger multiple of
     * the essential spend still to be funded. A plan with nothing left to fund (no remaining
     * essential spend) never bites: there is no shortfall for a cut to answer.
     */
    public function bites(Money $usableWealth, Money $remainingEssentialSpend): bool
    {
        if (! $remainingEssentialSpend->isPositive()) {
            return false;
        }

        return $usableWealth->pence < (int) round($remainingEssentialSpend->pence * $this->triggerFundedRatio()->asFraction());
    }

    /** What comes off a year's discretionary spend while the guardrail bites (zero if there is none). */
    public function cutFrom(Money $discretionarySpend): Money
    {
        return $discretionarySpend->minZero()->applyRate($this->discretionaryCut());
    }
}
