<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Support;

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

    /** Unused pension pots have been included in the estate for Inheritance Tax (April 2027 rule). */
    public const IHT_PENSIONS_IN_ESTATE = 'iht_pensions_in_estate';
}
