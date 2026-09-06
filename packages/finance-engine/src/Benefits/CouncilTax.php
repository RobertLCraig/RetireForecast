<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

use RetireForecast\FinanceEngine\Dto\CouncilTaxBand;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * What a household actually pays in council tax, which is rarely the bill on the doormat.
 *
 * Three things cut it, and they apply in this order, because each acts on what the one before
 * it left:
 *
 *  1. The **disabled band reduction** ({@see CouncilTaxBand}) lowers the LIABILITY: a qualifying
 *     dwelling is charged as if it were a band lower. It is **not means-tested** — it needs only
 *     a qualifying feature (an extra bathroom, a room used for the disabled person's needs, or
 *     space to use a wheelchair indoors) — so the richest household in the model can hold it.
 *  2. The **single-person discount** takes {@see SINGLE_PERSON_DISCOUNT_BPS} off what is left,
 *     automatically, once only one adult lives in the dwelling. In a forecast that is what the
 *     first death does, and it is the reduction a bundled running-costs figure could never make:
 *     a survivor was charged a couple's council tax for the rest of their life.
 *  3. **Council Tax Reduction** meets some or all of the remaining bill for a household on a low
 *     income. At pension age the scheme is PRESCRIBED by regulation, so a council cannot cut it
 *     the way it can cut its own working-age scheme — which is why it is safe to model at all.
 *
 * The pension-age reduction, in full:
 *  - a household actually being paid Guarantee Credit is passported to the MAXIMUM reduction,
 *    with income and capital ignored entirely, so the whole bill is met;
 *  - otherwise capital above the {@see CapitalAssessment} upper limit ends it outright, and
 *    capital below that limit is assessed through the same tariff Pension Credit uses;
 *  - income at or below the applicable amount gets the maximum reduction too;
 *  - income above it tapers the reduction away at {@see REDUCTION_TAPER_BPS} of the excess.
 *
 * The applicable amount used is the Pension Credit one the engine already computes
 * ({@see PensionCreditCalculator::applicableAmountWeekly}, uprated by the caller). The two are
 * not identical in law — the CTR scheme has its own personal allowances and its own premiums —
 * but they are built to the same shape and from the same figures, and a second hand-entered
 * table would be a second definition of one quantity for the sake of a few pence.
 *
 * v1 limits, flagged: non-dependant deductions (another adult living there who is expected to
 * contribute) are not modelled, which OVERSTATES the reduction for such a household; the
 * second-adult rebate is not modelled, which understates it for a few; and whether anybody
 * claims is not modelled — this awards it wherever the arithmetic qualifies, the same
 * simplification Pension Credit carries.
 *
 * **SOURCING GAP — the two figures below are STATED, not verified in this session.** The rules
 * are the statutory ones (Local Government Finance Act 1992 s.11 for the discount; the Council
 * Tax Reduction Schemes (Prescribed Requirements) (England) Regulations 2012 for the pension-age
 * scheme and its taper), but the unattended build loop that added them had no web access, so
 * neither citation was fetched and neither carries a verified_on date. Board card 0111 carries
 * pinning them to a primary source. See docs/spec/ASSUMPTIONS.md §23.
 */
final class CouncilTax
{
    /**
     * The **single-person discount**: 25% off, automatically, once only one adult lives in the
     * dwelling. It is a discount and not a benefit — nothing is means-tested and nothing is
     * claimed each year — which is why a forecast can apply it the moment the household shrinks
     * to one.
     *
     * **PUBLIC so a presenter can DISCLOSE the figure without restating it**, the
     * no-invisible-figures rule.
     */
    public const SINGLE_PERSON_DISCOUNT_BPS = 2_500; // 25% of the liability

    /**
     * The **taper**: 20p of reduction is withdrawn for every £1 a week of income above the
     * applicable amount. It is the same 20% taper the pension-age Housing Benefit rules use, and
     * it is what makes the reduction reach well beyond the households that get Pension Credit —
     * on a typical bill it is still worth something several thousand pounds a year above the
     * guarantee.
     *
     * **PUBLIC so a presenter can DISCLOSE the figure without restating it.**
     */
    public const REDUCTION_TAPER_BPS = 2_000; // 20% of the excess income

    /** The single-person discount, read from the constant that owns it. */
    public static function singlePersonDiscount(): Percent
    {
        return Percent::fromBasisPoints(self::SINGLE_PERSON_DISCOUNT_BPS);
    }

    /** The Council Tax Reduction taper, read from the constant that owns it. */
    public static function reductionTaper(): Percent
    {
        return Percent::fromBasisPoints(self::REDUCTION_TAPER_BPS);
    }

    /**
     * What the household is LIABLE for before any means-tested reduction: the bill, charged a
     * band lower where the disabled band reduction applies, then 25% off for a single occupant.
     *
     * $bill is the bill the household actually receives for the band it is actually in, so the
     * band reduction is applied as the RATIO of the two bands' charges rather than by looking up
     * a rate this engine does not hold (every council sets its own).
     */
    public static function liabilityAnnual(Money $bill, ?CouncilTaxBand $disabledBandReduction, bool $singleOccupant): Money
    {
        $liability = $bill->minZero();

        if ($disabledBandReduction !== null) {
            $liability = Money::fromPence((int) round(
                $liability->pence * $disabledBandReduction->reducedNinths() / $disabledBandReduction->ninths(),
            ));
        }

        if ($singleOccupant) {
            $liability = $liability->minus($liability->applyRate(self::singlePersonDiscount()));
        }

        return $liability;
    }

    /**
     * The Council Tax Reduction met on that liability, on the pension-age prescribed basis.
     *
     * $award carries both halves of the test the engine already ran for Pension Credit: whether
     * Guarantee Credit is in payment (the passport), and the household's assessable income
     * INCLUDING the capital tariff against its appropriate minimum guarantee. Reading them off
     * the award rather than recomputing them is what keeps the two answers from disagreeing.
     */
    public static function reductionAnnual(
        Money $annualLiability,
        PensionCreditResult $award,
        Money $assessableCapital,
        Money $upperCapitalLimit,
        int $weeksPerYear,
    ): Money {
        $liability = $annualLiability->minZero();

        // Passported: on Guarantee Credit the whole bill is met, and no capital limit applies.
        if ($award->guaranteeCreditWeekly->isPositive()) {
            return $liability;
        }

        if ($assessableCapital->greaterThan($upperCapitalLimit)) {
            return Money::zero();
        }

        $excessWeekly = $award->assessableIncomeWeekly->minus($award->applicableAmountWeekly)->minZero();
        $taperAnnual = $excessWeekly->applyRate(self::reductionTaper())->times($weeksPerYear);

        return $liability->minus($taperAnnual)->minZero();
    }
}
