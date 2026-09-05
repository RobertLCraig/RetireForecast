<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * What a LANDLORD requires of a tenant, as distinct from what the tenant can afford. The
 * forecast asks only whether the money lasts; this is the other question, and for a retired
 * household it is the one that decides whether the plan exists at all (board card 0031).
 *
 * Standard tenant referencing is an INCOME test. An agent's referencing provider asks for gross
 * annual income of at least {@see REFERENCING_INCOME_MULTIPLE} times the MONTHLY rent, and it
 * does not care how much capital the applicant holds. A household that has just sold a home and
 * is sitting on the proceeds, living on a small pension, fails it — a wall no amount of capital
 * gets them over, and one the money-lasts projection cannot see.
 *
 * The two normal ways round a failed reference are both costly, and both are stated to the
 * reader rather than modelled away:
 *  - a UK homeowner GUARANTOR, referenced at the higher {@see GUARANTOR_INCOME_MULTIPLE} times
 *    the monthly rent against their own income. A household that has just sold no longer has a
 *    homeowner in it, and an elderly applicant's usual guarantor (a working adult child) must
 *    clear that bar on top of their own housing costs.
 *  - RENT IN ADVANCE, {@see ADVANCE_MONTHS_MIN} to {@see ADVANCE_MONTHS_MAX} months of it, which
 *    ties up capital that the plan is otherwise relying on being invested — and is asked for
 *    again at every renewal, so it is a standing lock-up rather than a one-off.
 *
 * Starting a tenancy also costs money on day one: the deposit (capped by the Tenant Fees Act
 * 2019 at {@see DEPOSIT_WEEKS} weeks' rent, or {@see DEPOSIT_WEEKS_HIGH_RENT} weeks where the
 * annual rent is {@see HIGH_RENT_THRESHOLD_PENCE} or more) plus the first month's rent in
 * advance. Only the DEPOSIT is charged on top of the projection's rent line: a year of a
 * monthly-in-advance tenancy is twelve payments, and the year's rent already charges twelve, so
 * charging a thirteenth would be money nobody pays. The deposit is not refunded inside the
 * horizon of a for-life tenancy — it is re-lodged at every move — so it is charged as a lasting
 * cost, and the disclosure names the full day-one figure the household must actually produce.
 *
 * Every figure is a constant here so the sentence a reader is shown READS it rather than
 * restating it. **SOURCING:** the deposit cap is statute (Tenant Fees Act 2019 c.4, Schedule 1
 * paragraph 2). The referencing and guarantor multiples and the advance months are the
 * judgement of the property reviewer in the 2026-08-19 expert panel, matching common UK
 * referencing practice; they are a flagged sourcing gap (docs/spec/ASSUMPTIONS.md §15).
 */
final class Tenancy
{
    /** Gross ANNUAL income a standard reference asks for, as a multiple of the MONTHLY rent. */
    public const REFERENCING_INCOME_MULTIPLE = 30;

    /** The higher multiple a homeowner guarantor is referenced at, on their own income. */
    public const GUARANTOR_INCOME_MULTIPLE = 36;

    /** Months of rent in advance a landlord asks of an applicant who fails referencing. */
    public const ADVANCE_MONTHS_MIN = 6;

    public const ADVANCE_MONTHS_MAX = 12;

    /** Deposit cap in weeks' rent — Tenant Fees Act 2019, Sch 1 para 2. verified_on 2026-09-05. */
    public const DEPOSIT_WEEKS = 5;

    /** The cap rises to six weeks once the annual rent reaches {@see HIGH_RENT_THRESHOLD_PENCE}. */
    public const DEPOSIT_WEEKS_HIGH_RENT = 6;

    /** £50,000 a year, the Tenant Fees Act threshold between the five- and six-week caps. */
    public const HIGH_RENT_THRESHOLD_PENCE = 5_000_000;

    /**
     * The label the up-front tenancy charge is filed under. One home for it, because the charge
     * is written by {@see HousingComparison} and recognised again by the projector.
     */
    public const UP_FRONT_LABEL = 'Tenancy deposit';

    /** The rent a month — the basis every referencing multiple is quoted against. */
    public static function monthlyRent(Money $annualRent): Money
    {
        return $annualRent->dividedBy(12);
    }

    /** The gross annual income a standard reference asks for at this rent. */
    public static function referencingIncomeRequired(Money $annualRent): Money
    {
        return self::monthlyRent($annualRent)->times(self::REFERENCING_INCOME_MULTIPLE);
    }

    /** The gross annual income a homeowner guarantor is referenced at, at this rent. */
    public static function guarantorIncomeRequired(Money $annualRent): Money
    {
        return self::monthlyRent($annualRent)->times(self::GUARANTOR_INCOME_MULTIPLE);
    }

    /** Capital tied up by paying $months of rent in advance. */
    public static function rentInAdvance(Money $annualRent, int $months): Money
    {
        return self::monthlyRent($annualRent)->times($months);
    }

    /** The deposit a landlord may hold, at the Tenant Fees Act cap for this rent. */
    public static function deposit(Money $annualRent): Money
    {
        $weeks = $annualRent->pence >= self::HIGH_RENT_THRESHOLD_PENCE
            ? self::DEPOSIT_WEEKS_HIGH_RENT
            : self::DEPOSIT_WEEKS;

        return $annualRent->times($weeks)->dividedBy(52);
    }

    /** The cash a household must produce before it gets the keys: deposit + first month. */
    public static function upFrontCash(Money $annualRent): Money
    {
        return self::deposit($annualRent)->plus(self::monthlyRent($annualRent));
    }

    /** Does this household's gross income clear a standard reference at this rent? */
    public static function referencePasses(Money $annualRent, Money $grossAnnualIncome): bool
    {
        return $grossAnnualIncome->greaterThanOrEqual(self::referencingIncomeRequired($annualRent));
    }
}
