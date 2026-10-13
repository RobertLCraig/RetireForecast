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
 * annual income of at least {@see REFERENCING_INCOME_MULTIPLE} times the MONTHLY rent. A
 * household that has just sold a home and is sitting on the proceeds, living on a small pension,
 * fails the standard income reference however well the money-lasts projection reads. (Some
 * providers will instead accept proof of funds from a completed sale; that route is not modelled.)
 *
 * The usual way round a failed reference is stated to the reader rather than modelled away:
 *  - a GUARANTOR, referenced at the higher {@see GUARANTOR_INCOME_MULTIPLE} times the monthly
 *    rent against their own income. An elderly applicant's usual guarantor (a working adult
 *    child) must clear that bar on top of their own housing costs.
 * Rent in advance is NOT a way round in England: since 1 May 2026 a landlord may ask for at most
 * {@see RENT_IN_ADVANCE_CAP_MONTHS} month of it, and only before the tenancy starts.
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
 * restating it. **SOURCING** (docs/spec/ASSUMPTIONS.md §15, card 0091): the deposit cap is
 * statute (Tenant Fees Act 2019 c.4, Schedule 1 paragraph 2); the multiples are the referencing
 * providers' published criteria; the rent-in-advance cap is statute (Renters' Rights Act 2025).
 */
final class Tenancy
{
    /**
     * Gross ANNUAL income a standard reference asks for, as a multiple of the MONTHLY rent:
     * 2.5x the annual rent. HomeLet Referencing Service Guidelines HL202407 p.8,
     * https://connect.homelet.co.uk/assets/connect/pdf/Referencing_Service_Guidelines.pdf ;
     * Goodlord ratio 2.5, https://portal.goodlord.co/blog/2024/8/20/getting-started-with-goodlord-referencing-api .
     * verified_on 2026-10-11.
     */
    public const REFERENCING_INCOME_MULTIPLE = 30;

    /**
     * The higher multiple a guarantor is referenced at, on their own income: 3x the annual rent.
     * HomeLet HL202407 p.8-9 and Goodlord ratio 3 (URLs above). Neither requires a homeowner.
     * verified_on 2026-10-11.
     */
    public const GUARANTOR_INCOME_MULTIPLE = 36;

    /**
     * The most rent a landlord in England may ask for in advance, for assured tenancies from
     * 1 May 2026: one month, and only in the pre-tenancy period. Renters' Rights Act 2025 c.26
     * s.8 (Housing Act 1988 s.4B), https://www.legislation.gov.uk/ukpga/2025/26/section/8 ;
     * https://www.gov.uk/guidance/renting-out-your-property-guidance-for-landlords-and-letting-agents/rent-payments-and-deposits .
     * verified_on 2026-10-11.
     */
    public const RENT_IN_ADVANCE_CAP_MONTHS = 1;

    /** Deposit cap in weeks' rent — Tenant Fees Act 2019, Sch 1 para 2. verified_on 2026-09-05. */
    public const DEPOSIT_WEEKS = 5;

    /** The cap rises to six weeks once the annual rent reaches {@see HIGH_RENT_THRESHOLD_PENCE}. */
    public const DEPOSIT_WEEKS_HIGH_RENT = 6;

    /** £50,000 a year, the Tenant Fees Act threshold between the five- and six-week caps. */
    public const HIGH_RENT_THRESHOLD_PENCE = 5_000_000;

    /**
     * The label the up-front tenancy charge is filed under. One home for it, because the charge
     * is written by {@see HousingComparison} (a year-0 move) and by the projector (a forced sale,
     * board card 0090), and recognised again by the projector's disclosure.
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

    /** The gross annual income a guarantor is referenced at, at this rent. */
    public static function guarantorIncomeRequired(Money $annualRent): Money
    {
        return self::monthlyRent($annualRent)->times(self::GUARANTOR_INCOME_MULTIPLE);
    }

    /** The deposit a landlord may hold, at the Tenant Fees Act cap for this rent. */
    public static function deposit(Money $annualRent): Money
    {
        return $annualRent->times(self::depositWeeks($annualRent))->dividedBy(52);
    }

    /** The weeks' rent the deposit cap allows at this rent: the one rule the charge and its sentence share. */
    public static function depositWeeks(Money $annualRent): int
    {
        return $annualRent->pence >= self::HIGH_RENT_THRESHOLD_PENCE
            ? self::DEPOSIT_WEEKS_HIGH_RENT
            : self::DEPOSIT_WEEKS;
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
