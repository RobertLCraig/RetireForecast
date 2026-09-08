<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tax;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The chargeable gain on selling a CHATTEL: tangible movable property, which for a household
 * planning a retirement means the art, the jewellery, the antiques and the collection.
 *
 * TCGA 1992 s262 is a two-part rule and the second part is the one everybody forgets:
 *
 *   1. proceeds inside the exempt amount are not chargeable at all, however big the real gain;
 *   2. just above it, the chargeable gain is capped at FIVE THIRDS of the excess over the exempt
 *      amount, so the charge phases in instead of jumping off a cliff at the threshold.
 *
 * The cap stops binding once the gain is small relative to the proceeds, after which the ordinary
 * computation applies. The exempt amount is per item, or per SET where a set is broken up and sold
 * piecemeal, which is why it is passed in per disposal rather than being a household allowance.
 *
 * Board card 0065. The model charged nothing on any of this, so a plan that turns on selling
 * possessions was optimistic by the whole bill.
 *
 * NOT MODELLED, deliberately: a chattels LOSS. A disposal below the exempt amount is treated as
 * made for exactly the exempt amount when computing a loss, which restricts the allowable loss;
 * the engine relieves no capital losses at all yet ({@see PathProjector::cgtOnGain}), so there is
 * nothing here for that rule to restrict. Nor is the WASTING-asset exemption (s45, an asset with
 * an expected life of 50 years or less, which covers a car and a clock): applying it would make
 * this table LESS chargeable, so leaving it out is the cautious direction and a reader selling one
 * simply does not state a cost.
 *
 * SOURCE: TCGA 1992 s262 (chattel exemption and the five-thirds marginal relief),
 * https://www.legislation.gov.uk/ukpga/1992/12/section/262 ; gov.uk CG76573 and the
 * "Capital Gains Tax on personal possessions" guidance, https://www.gov.uk/capital-gains-tax-personal-possessions
 * The exempt amount itself is a statutory figure and lives on {@see CgtParameters}.
 */
final class ChattelsGain
{
    /**
     * The numerator and denominator of the marginal-relief fraction: the chargeable gain just above
     * the threshold is limited to five thirds of the excess over the exempt amount. Statute, so it
     * is not uprated and does not belong in the per-tax-year config beside the exempt amount.
     */
    public const MARGINAL_RELIEF_NUMERATOR = 5;

    public const MARGINAL_RELIEF_DENOMINATOR = 3;

    /**
     * The chargeable gain on one disposal: nil inside the exempt amount, otherwise the lower of the
     * actual gain and five thirds of the excess. Never negative: the engine relieves no losses.
     */
    public static function chargeableGain(Money $proceeds, Money $acquisitionCost, Money $exemptAmount): Money
    {
        if ($proceeds->pence <= $exemptAmount->pence) {
            return Money::zero();
        }

        $gain = max(0, $proceeds->pence - $acquisitionCost->pence);
        $cap = (int) round(
            ($proceeds->pence - $exemptAmount->pence) * self::MARGINAL_RELIEF_NUMERATOR / self::MARGINAL_RELIEF_DENOMINATOR
        );

        return Money::fromPence(min($gain, $cap));
    }
}
