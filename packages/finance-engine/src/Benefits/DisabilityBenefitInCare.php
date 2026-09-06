<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

/**
 * What happens to a disability award once the local authority is paying for the placement.
 *
 * Attendance Allowance and the CARE component of Disability Living Allowance (and the daily
 * living component of PIP) stop after 28 days in a care home whose fees the local authority
 * meets. The MOBILITY component does not stop: it keeps running for the whole placement, which
 * is why board card 0050 required the award to be recorded as two components rather than one
 * figure. Until then the engine ran BOTH components right through a modelled care spell, so a
 * funded resident kept the household's largest tax-free income when in life it would have gone.
 *
 * The stop is the mirror of the other half of the same split: while the care component IS in
 * payment it counts as income in the local-authority financial assessment (only the mobility
 * component is disregarded there), so a self-funder contributes it. One rule, two directions.
 *
 * The engine's year is an annual grid, so the statutory period is expressed as the fraction of
 * a year the care component is still paid in the FIRST local-authority-funded care year, and
 * nothing in any later one. The severe-disability addition to Pension Credit goes with it: it is
 * carried by the care component, so a resident whose award has stopped no longer qualifies.
 *
 * **SOURCING GAP — the 28 days below is STATED, not verified in this session.** The rule is the
 * statutory one (regulation 8 of the Social Security (Attendance Allowance) Regulations 1991 and
 * regulation 9 of the Social Security (Disability Living Allowance) Regulations 1991), but the
 * unattended build loop that added it had no web access, so no citation was fetched and it
 * carries no verified_on date. Board card 0118 carries pinning it to a primary source.
 * See docs/spec/ASSUMPTIONS.md §25.
 */
final class DisabilityBenefitInCare
{
    /** Days of a local-authority-funded placement before the care component stops. */
    public const PAYMENT_STOP_DAYS = 28;

    /** The grid the days are expressed against; the engine's year is a whole calendar year. */
    public const DAYS_PER_YEAR = 365;

    /**
     * The fraction of this year's care component still paid, given how many local-authority-funded
     * care years this person has already had. The first such year pays the statutory period; every
     * later year of the same spell pays nothing.
     */
    public static function payableFraction(int $priorFundedCareYears): float
    {
        return $priorFundedCareYears === 0
            ? self::PAYMENT_STOP_DAYS / self::DAYS_PER_YEAR
            : 0.0;
    }
}
