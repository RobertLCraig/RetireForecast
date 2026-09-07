<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Care;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A deferred payment agreement (England): the local authority pays a resident's care fees and
 * secures what it has paid on their home, instead of the resident having to sell it.
 *
 * This is what stops an assessable home producing a bill the forecast has no way to pay. The
 * funding waterfall draws on cash, investments, ISAs and pensions and NEVER on the home, so
 * before board card 0055 a self-funding homeowner in care ran a large unfundable care charge
 * every year, the year was marked as failing its essentials, and the plan was penalised for
 * keeping a property that in life would simply have carried the debt. A deferred payment is a
 * different outcome from a forced sale in every way that matters here: the household keeps the
 * home, the fees are met, and the balance — with interest — comes out of the estate at death.
 *
 * An authority MUST offer an agreement to a resident whose non-housing assets are below the
 * upper capital limit and whose home is not disregarded, and MAY offer one more widely. This
 * engine models the wider case, because a household that would rather owe against the home than
 * sell it is the whole subject of the tool: what is deferred is the part of the care charge the
 * year's liquid assets could not meet, and never more than the equity left in the home.
 *
 * What is deliberately NOT modelled (v1 limits, flagged): the administration and valuation fees
 * an authority may charge on top; the disposable income allowance, which lets a resident keep
 * part of their income and defer correspondingly more (the engine defers only the genuinely
 * unfundable part, so it defers LESS than the real scheme allows); the authority's discretion to
 * refuse, and the security/equity tests it applies before agreeing; and the twelve-week property
 * disregard that usually runs before an agreement starts.
 */
final class DeferredPaymentAgreement
{
    /**
     * The **maximum interest rate** an authority may charge on a deferred payment: **4.65% a
     * year**, compounded.
     *
     * The rate is set by regulation, not by the authority's own cost of money: the maximum is the
     * average of the Office for Budget Responsibility's forecast for the 15-year gilt rate, plus
     * a default component of up to 0.15 percentage points, and it is re-set twice a year, on
     * 1 January and on 1 July. An authority may charge less; most charge the maximum.
     *
     * **Adverse by rule** (Rob's standing default rule): the rule above has produced rates
     * roughly between 2% and 5% since the scheme began in 2015, and the shipped figure is the
     * HIGH end of that range, because a larger rolled-up debt is the cautious direction — it
     * never lets a plan bank an estate that turns out to be smaller. The consequence runs the
     * other way for the comparison against selling the home, so a reader deciding between the
     * two is being shown deferral at its least attractive, never at its best.
     *
     * **SOURCING GAP — this figure is STATED, not verified.** The unattended build loop that
     * added it has no web access, so the rate is not pinned to a current publication. The RULE
     * above is the published one; the VALUE is not. See docs/spec/ASSUMPTIONS.md §28 and the
     * board card raised beside this one.
     *
     * **PUBLIC so a presenter can DISCLOSE the figure without restating it**, the
     * no-invisible-figures rule.
     */
    public const MAXIMUM_INTEREST_RATE_BPS = 465; // 4.65% a year, compounded

    /** The statutory maximum interest rate, read from the constant that owns it. */
    public static function interestRate(): Percent
    {
        return Percent::fromBasisPoints(self::MAXIMUM_INTEREST_RATE_BPS);
    }

    /**
     * What can be deferred this year: the part of the care charge the year could not fund, capped
     * at the equity still left in the home.
     *
     * Both caps matter. Only the CARE charge is deferrable — an authority secures care fees on
     * the home, not the household's groceries — so a year that ran short on ordinary spending as
     * well still reports that part as unmet. And the agreement is only ever worth what the
     * security bears, so once the rolled-up balance and any other charge have eaten the equity
     * there is nothing left to defer against and the shortfall becomes real again.
     *
     * @param  Money  $unmetSpend  what the year's income and drawdown could not meet
     * @param  Money  $careCharged  the household-borne care charge for the year
     * @param  Money  $equityHeadroom  the home's value less everything already secured on it
     */
    public static function deferrableThisYear(Money $unmetSpend, Money $careCharged, Money $equityHeadroom): Money
    {
        return Money::fromPence(min(
            $unmetSpend->minZero()->pence,
            $careCharged->minZero()->pence,
            $equityHeadroom->minZero()->pence,
        ));
    }
}
