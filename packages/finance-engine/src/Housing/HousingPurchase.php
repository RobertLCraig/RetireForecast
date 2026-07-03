<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The buy-cheaper leg of a downsizing decision, decomposed into the surplus that ends
 * up invested. Starting from the net sale proceeds (see {@see HousingProceeds}), buying
 * a cheaper home nets off its price, the SDLT due on it and the moving costs:
 *
 *   surplus = max(0, netProceeds − buyPrice − stampDuty − movingCosts)
 *
 * Holding every part beside the surplus is what makes the invested figure reconcilable:
 * whenever the proceeds cover the purchase, netProceeds == buyPrice + stampDuty +
 * movingCosts + surplus exactly. When the cheaper home costs more than the proceeds and a
 * buy mortgage is available, $mortgage funds the gap instead of flooring the surplus, so the
 * general invariant is netProceeds + mortgage == buyPrice + stampDuty + movingCosts + surplus.
 * This is the single source for the buy-side figures: {@see HousingComparison::buyVariant} and
 * any UI breakdown read it, so the parts can never drift from the total they sum to.
 */
final class HousingPurchase
{
    public function __construct(
        public readonly Money $netProceeds,
        public readonly Money $buyPrice,
        public readonly Money $stampDuty,
        public readonly Money $movingCosts,
        public readonly Money $surplus,
        public readonly Money $mortgage,
    ) {}

    /** True when the proceeds cover the purchase and its costs from cash alone (no mortgage). */
    public function coversPurchase(): bool
    {
        return $this->netProceeds->pence >= $this->buyPrice->pence
            + $this->stampDuty->pence
            + $this->movingCosts->pence;
    }
}
