<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * Which part of a disability award a person holds, and at what rate — the fact that decides
 * whether it is a QUALIFYING benefit for the Pension Credit severe-disability addition and for
 * (underlying entitlement to) Carer's Allowance, which rest on the same list.
 *
 * {@see Person::$receivesDisabilityBenefit} says only that a disability benefit is in payment,
 * which is what the tax-free income stream and the passports note need. It is not enough for the
 * additions: a mobility component, or the lowest rate care component, is a real award that pays
 * real money and confers NEITHER. Testing the additions on the bare flag awarded them to people
 * who are not entitled (board card 0051).
 *
 * The engine deliberately models the QUESTION rather than the benefit: three cases, because three
 * is what the rule distinguishes. Naming every award and rate separately would be a longer list
 * that the calculation would immediately collapse back to this.
 */
enum DisabilityAwardRate: string
{
    /**
     * The middle or highest rate DLA care component, Attendance Allowance at either rate, or the
     * PIP daily living component at either rate. The default, because it is what
     * {@see Person::$receivesDisabilityBenefit} has always meant — its docblock named DLA, AA and
     * PIP as qualifying benefits — so a scenario saved before this field existed keeps the answer
     * it was given, and a reader who holds a non-qualifying award now has a way to say so.
     */
    case QualifyingCare = 'qualifying_care';

    /** The LOWEST rate DLA care component only. Pays money; qualifies for neither addition. */
    case LowestRateCare = 'lowest_rate_care';

    /** A mobility component only, with no care or daily living component at all. */
    case MobilityOnly = 'mobility_only';

    /**
     * Whether this award is a qualifying benefit for the Pension Credit severe-disability
     * addition and for underlying entitlement to Carer's Allowance.
     */
    public function qualifiesForSevereDisabilityAddition(): bool
    {
        return $this === self::QualifyingCare;
    }

    /** How the award reads on a screen, for a disclosure that must name what it assumed. */
    public function label(): string
    {
        return match ($this) {
            self::QualifyingCare => 'DLA middle or highest rate care, Attendance Allowance, or PIP daily living',
            self::LowestRateCare => 'DLA lowest rate care component only',
            self::MobilityOnly => 'mobility component only',
        };
    }
}
