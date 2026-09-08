<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Support;

use RetireForecast\FinanceEngine\Benefits\Deprivation;
use RetireForecast\FinanceEngine\Dto\SpendingGuardrail;

/**
 * Stable machine-readable codes for {@see Warning}s, so the UI can group, style
 * and link to signposting for each pitfall without string-matching prose.
 */
final class WarningCode
{
    /** A first flexible withdrawal was taxed on the emergency (Month-1) basis, over-deducting tax. */
    public const EMERGENCY_TAX = 'emergency_tax';

    /** Flexible access has triggered the Money Purchase Annual Allowance. */
    public const MPAA_TRIGGERED = 'mpaa_triggered';

    /** A tax-free lump sum was restricted by the Lump Sum Allowance. */
    public const LSA_EXCEEDED = 'lsa_exceeded';

    /** Pension contributions in the year exceeded the available annual allowance. */
    public const ANNUAL_ALLOWANCE_EXCEEDED = 'annual_allowance_exceeded';

    /** Assessable capital has crossed the £16,000 Housing Benefit / Council Tax Support cut-off. */
    public const CAPITAL_CLIFF_HB_CTS = 'capital_cliff_hb_cts';

    /**
     * The household is awarded no Guarantee Credit, but its assessable income is only just above
     * the appropriate minimum guarantee. The engine models Guarantee Credit alone and applies no
     * income disregards, so at this distance its own simplifications can be the whole of the
     * difference — which is exactly when a nil claim belongs on file.
     */
    public const PENSION_CREDIT_NEAR_MISS = 'pension_credit_near_miss';

    /** Unused pension pots have been included in the estate for Inheritance Tax (April 2027 rule). */
    public const IHT_PENSIONS_IN_ESTATE = 'iht_pensions_in_estate';

    /** The first death passed to a spouse / civil partner, so no IHT was due then (spouse exemption). */
    public const IHT_SPOUSE_EXEMPTION = 'iht_spouse_exemption';

    /**
     * The person who died first left NO will, so their estate passed under the intestacy rules
     * rather than wholly to the survivor: the spouse takes the chattels, the statutory legacy and
     * half the residue, and the children take the rest. The children's half is a chargeable
     * transfer, so it is taxed and it eats the nil-rate band that would otherwise have transferred
     * to the second death. Raised because "no will" is the DEFAULT answer here, and a reader has to
     * be able to see that the model took it.
     */
    public const IHT_INTESTACY = 'iht_intestacy';

    /**
     * The spouse exemption was CAPPED because the surviving spouse is not a UK long-term resident.
     * An unlimited exemption is only available where the recipient is, or where the couple make the
     * election that treats them as if they were; without it the exemption stops at the nil-rate
     * band and the rest of the estate is chargeable on the first death.
     */
    public const IHT_SPOUSE_EXEMPTION_CAPPED = 'iht_spouse_exemption_capped';

    /**
     * Part of the residence nil-rate band was restored as a DOWNSIZING ADDITION: the plan sold a
     * home during its life, so the band that home would have sheltered is added back rather than
     * lost. Raised so a reader shown a residence band beside a smaller home, or no home at all,
     * can see where the figure came from.
     */
    public const IHT_DOWNSIZING_ADDITION = 'iht_downsizing_addition';

    /**
     * An unused pension pot passing on a death at or after 75 is taxed TWICE: Inheritance Tax on
     * the estate, then the beneficiary's own income tax on what they draw from what is left.
     * Raised so the second charge is on the screen beside the first, because showing only one of
     * them makes preserving a pot look about twice as attractive as it is.
     */
    public const IHT_INHERITED_PENSION_INCOME_TAX = 'iht_inherited_pension_income_tax';

    /**
     * A one-off CAPITAL cost (an unfunded home purchase, a mortgage redeemed from capital) could
     * not be funded in the year it falls. Raised on that year so the lump is named and findable,
     * rather than only depressing a spending probability that reads as a whole-plan failure.
     */
    public const UNFUNDED_ONE_OFF_COST = 'unfunded_one_off_cost';

    /**
     * The household's gross income in this year falls below what a standard tenant reference
     * asks for at the rent being paid. It is an INCOME test, so capital does not answer it: the
     * plan may be perfectly affordable and still not be a tenancy anyone would grant.
     */
    public const RENT_REFERENCING_FAILED = 'rent_referencing_failed';

    /** What starting a tenancy costs on day one — the deposit and the first month's rent. */
    public const TENANCY_UP_FRONT_COST = 'tenancy_up_front_cost';

    /**
     * The plan moves a large sum out of the household's capital (a pension lump sum or
     * withdrawal, a capital receipt spent, a one-off cost, a gift, a home sale), which both the
     * benefits notional-capital rule and the care deliberate-deprivation test can treat as still
     * held. {@see Deprivation}.
     */
    public const CAPITAL_DEPRIVATION = 'capital_deprivation';

    /**
     * One member of the couple is under State Pension age and the other is over it, so the
     * household cannot claim Pension Credit at all until the younger one reaches State Pension
     * age. The nil award is correct; saying nothing about it is not, because the working-age
     * support that replaces it is a different benefit on different rules and can be worth
     * thousands a year less.
     */
    public const MIXED_AGE_COUPLE = 'mixed_age_couple';

    /**
     * The spending guardrail was asked to protect this year and had nothing to work with: the
     * household's whole spend is its essential floor, so there is no discretionary spend to trim.
     * That is itself the finding — the cheapest mitigation a plan has is unavailable to this one —
     * and a guardrail that quietly did nothing would leave the reader believing it was there.
     * {@see SpendingGuardrail}, board card 0063.
     */
    public const GUARDRAIL_NO_FLEXIBILITY = 'guardrail_no_flexibility';
}
