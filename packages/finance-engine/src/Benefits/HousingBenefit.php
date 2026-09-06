<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * What a pension-age RENTER gets towards their rent.
 *
 * Until board card 0048 the engine awarded Guarantee Credit and nothing else, so a household that
 * sold its home and rented paid every penny of the rent out of its own pocket for the whole plan.
 * That is not a neutral simplification: in a sell-and-rent plan the proceeds are spent down over
 * ten to fifteen years, capital falls back under the limit, and the household is then squarely in
 * Housing Benefit territory — in exactly the tail where a plan is judged to run short. The
 * buy-outright leg has no equivalent omission, so the comparison the whole tool exists to run had
 * a thumb on the scale against renting.
 *
 * The pension-age scheme, in full, and deliberately the same shape as {@see CouncilTax}, whose
 * reduction is the sibling of this one:
 *  - a household actually being paid Guarantee Credit is passported to the MAXIMUM award, with
 *    income and capital ignored entirely, so the whole eligible rent is met;
 *  - otherwise capital above the {@see CapitalAssessment} upper limit ends it outright, and
 *    capital below that limit is assessed through the same tariff Pension Credit uses;
 *  - income at or below the applicable amount gets the maximum award too;
 *  - income above it tapers the award away at {@see TAPER_BPS} of the excess — a much steeper
 *    withdrawal than the council tax one, which is why Housing Benefit runs out sooner than a
 *    reader expects.
 *
 * The applicable amount used is the Pension Credit one the engine already computes
 * ({@see PensionCreditCalculator::applicableAmountWeekly}, uprated by the caller), for the reason
 * {@see CouncilTax} gives: the HB applicable amount is built to the same shape from the same
 * figures, and a second hand-entered table would be a second definition of one quantity.
 *
 * v1 limits, flagged, and the first of them is the one that matters:
 *  - the LOCAL HOUSING ALLOWANCE cap is not modelled. A private tenant's eligible rent is capped
 *    at the LHA rate for their broad rental market area and household size, and this engine holds
 *    no LHA table (there is one per area, re-set every April). So the whole rent is treated as
 *    eligible, which OVERSTATES the award wherever the rent is above the local cap — the
 *    optimistic direction, against the house rule of defaulting adverse. Board card 0114.
 *  - ineligible service charges (fuel, water, meals inside a rent) are not stripped out, which
 *    overstates it slightly again;
 *  - non-dependant deductions are not modelled, which overstates it for a household with another
 *    adult living there;
 *  - whether anybody claims is not modelled — this awards it wherever the arithmetic qualifies,
 *    the same simplification Pension Credit and Council Tax Reduction carry.
 *
 * Working-age Housing Benefit is deliberately NOT modelled: it is closed to new claims and the
 * housing element sits inside Universal Credit, which the card that built this put out of scope
 * for a pension-age tool. A renter short of State Pension age is awarded nothing and the result
 * note says the plan is understated for it.
 *
 * **SOURCING GAP — the taper below is STATED, not verified in this session.** The rule is the
 * statutory one (the Housing Benefit (Persons who have attained the qualifying age for state
 * pension credit) Regulations 2006), but the unattended build loop that added it had no web
 * access, so no citation was fetched and it carries no verified_on date. Board card 0113 carries
 * pinning it to a primary source. See docs/spec/ASSUMPTIONS.md §24.
 */
final class HousingBenefit
{
    /**
     * The **taper**: 65p of Housing Benefit is withdrawn for every £1 a week of income above the
     * applicable amount. It is more than three times the Council Tax Reduction taper of 20%, so a
     * household only a little above the guarantee keeps very little of it — which is why a plan
     * cannot be read as "renting is covered once the money runs low".
     *
     * **PUBLIC so a presenter can DISCLOSE the figure without restating it**, the
     * no-invisible-figures rule.
     */
    public const TAPER_BPS = 6_500; // 65% of the excess income

    /** The Housing Benefit taper, read from the constant that owns it. */
    public static function taper(): Percent
    {
        return Percent::fromBasisPoints(self::TAPER_BPS);
    }

    /**
     * The Housing Benefit met on this year's rent, on the pension-age basis.
     *
     * $award carries both halves of the test the engine already ran for Pension Credit: whether
     * Guarantee Credit is in payment (the passport), and the household's assessable income
     * INCLUDING the capital tariff against its appropriate minimum guarantee. Reading them off
     * the award rather than recomputing them is what keeps the two answers from disagreeing.
     */
    public static function annualAward(
        Money $annualEligibleRent,
        PensionCreditResult $award,
        Money $assessableCapital,
        Money $upperCapitalLimit,
        int $weeksPerYear,
    ): Money {
        $rent = $annualEligibleRent->minZero();

        // Passported: on Guarantee Credit the whole eligible rent is met, and no capital limit
        // applies. Getting this edge wrong tells the poorest household in the model that its
        // savings have ended help it is in fact still entitled to.
        if ($award->guaranteeCreditWeekly->isPositive()) {
            return $rent;
        }

        if ($assessableCapital->greaterThan($upperCapitalLimit)) {
            return Money::zero();
        }

        $excessWeekly = $award->assessableIncomeWeekly->minus($award->applicableAmountWeekly)->minZero();
        $taperAnnual = $excessWeekly->applyRate(self::taper())->times($weeksPerYear);

        return $rent->minus($taperAnnual)->minZero();
    }
}
