<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Support for Mortgage Interest (SMI): the cheapest secured borrowing available to a pensioner,
 * and the first instrument a benefits caseworker reaches for when the problem is an unaffordable
 * secured debt in later life.
 *
 * A household receiving Pension Credit Guarantee Credit qualifies with **no waiting period** (the
 * working-age route through Universal Credit has one; the pension-age route does not). What is
 * paid is a LOAN, not a benefit: DWP meets the interest on eligible loan capital, up to a cap, at
 * its own standard rate, and takes a charge on the home for what it has paid. The charge is
 * repaid when the home is sold or on death, and any shortfall against the available equity is
 * written off — which is why it is modelled as a second rolled-up balance beside the mortgage
 * rather than as income.
 *
 * For a **pension-age** claimant the eligible housing costs are wider than the mortgage: service
 * charges and ground rent count too, which is the difference between covering nothing and
 * covering most of the bill on a leasehold flat. Those are met in full — there is no standard
 * rate to apply to them, they are simply a cost of the home.
 *
 * What is deliberately NOT modelled here (v1 limits, flagged): whether the household would in
 * fact claim (the charge has to be accepted, and some households will not take a debt against
 * the home); the exclusions on particular service charges (only the utilities part of the bucket
 * is carved out, {@see ExpenseProfile::propertyCostsUtilities});
 * and the separate interest rate DWP charges on the loan itself, which is set from gilt yields
 * rather than from the standard rate. The balance here rolls up at the standard rate instead —
 * one sourced figure doing both jobs rather than a second invented one.
 *
 * Whether the household qualifies for Guarantee Credit at all is the caller's question
 * ({@see PensionCreditCalculator} and the projector's qualifying-age gate); this class only
 * says what is met once it does.
 */
final class SupportForMortgageInterest
{
    /**
     * The DWP **standard interest rate** at which the interest on eligible capital is met:
     * **2.09% a year**.
     *
     * The rate is not a rate the household pays. It is set by DWP at the Bank of England's
     * published monthly average interest rate for loans secured on dwellings, and it moves only
     * when that average has differed from it by 0.5 percentage points or more — so it steps
     * infrequently and by a lot, and it can sit well below or above what a particular borrower is
     * actually charged. A household paying more than the standard rate meets the difference
     * itself, which is why {@see annualAmountMet} never pays out more than the interest actually
     * charged.
     *
     * **Adverse by rule** (Rob's standing default rule): this rule has produced rates roughly
     * between 2% and 3.5% since the 2018 loan scheme began, and the shipped figure is the LOW end
     * of that range, because understating help is the cautious direction — it never lets a plan
     * bank support that turns out not to be there. The consequence is the other way round for the
     * comparison against equity release: SMI is modelled as *less* attractive than it probably is,
     * never more.
     *
     * **SOURCING GAP — this figure is STATED, not verified.** The unattended build loop that added
     * it has no web access, so the rate is not pinned to the current DWP publication. The RULE
     * above is the published one; the VALUE is not. Board card 0109 carries pinning both this and
     * {@see ELIGIBLE_CAPITAL_LIMIT_PENCE} to a primary source. See docs/spec/ASSUMPTIONS.md §21.
     *
     * **PUBLIC so a presenter can DISCLOSE the figure without restating it**, the
     * no-invisible-figures rule.
     */
    public const STANDARD_RATE_BPS = 209; // 2.09% a year

    /**
     * The **eligible capital limit** for a pension-age claimant: **£100,000**. Interest is met on
     * the loan balance up to this figure and on nothing above it, so a larger mortgage is only
     * partly supported.
     *
     * It is half the £200,000 limit that applies on the working-age route, and it is a cash figure
     * that has not been uprated since the loan scheme began — so, exactly like the £10,000 capital
     * disregard beside it, it covers less of a real mortgage every year. It is therefore NOT
     * inflated by the projection, which is what happens in life.
     *
     * **SOURCING GAP:** stated, not verified — see {@see STANDARD_RATE_BPS} and board card 0109.
     *
     * **PUBLIC so a presenter can DISCLOSE the figure without restating it.**
     */
    public const ELIGIBLE_CAPITAL_LIMIT_PENCE = 100_000_00;

    /** The DWP standard rate, read from the constant that owns it. */
    public static function standardRate(): Percent
    {
        return Percent::fromBasisPoints(self::STANDARD_RATE_BPS);
    }

    /** The pension-age eligible capital limit, read from the constant that owns it. */
    public static function eligibleCapitalLimit(): Money
    {
        return Money::fromPence(self::ELIGIBLE_CAPITAL_LIMIT_PENCE);
    }

    /**
     * The part of a mortgage balance interest is met on: the balance, or the limit, whichever is
     * smaller.
     */
    public static function eligibleCapital(Money $mortgageBalance): Money
    {
        $limit = self::eligibleCapitalLimit();

        return $mortgageBalance->greaterThan($limit) ? $limit : $mortgageBalance->minZero();
    }

    /**
     * The interest met this year: the standard rate on the eligible capital, and never more than
     * the interest the household is actually charged. The second cap is what keeps the model
     * honest about the products it holds — a rolled-up lifetime mortgage charges the household no
     * cash interest at all, so there is no liability for SMI to meet, and a household paying a
     * below-standard rate is not handed the difference.
     */
    public static function interestMetAnnual(Money $mortgageBalance, Money $interestCharged): Money
    {
        $met = self::eligibleCapital($mortgageBalance)->applyRate(self::standardRate());

        return $met->greaterThan($interestCharged->minZero()) ? $interestCharged->minZero() : $met;
    }

    /**
     * Everything met this year: the eligible mortgage interest plus the pension-age housing costs
     * (service charge and ground rent), which are met in full. This is the figure that comes off
     * the household's spending AND is added to the charge on the home — one definition for both,
     * so what the household is spared and what it owes can never disagree.
     */
    public static function annualAmountMet(Money $mortgageBalance, Money $interestCharged, Money $eligibleHousingCosts): Money
    {
        return self::interestMetAnnual($mortgageBalance, $interestCharged)->plus($eligibleHousingCosts->minZero());
    }
}
