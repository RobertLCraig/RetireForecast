<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Dto\CapitalReceipt;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * The buy-cheaper leg of a downsizing decision, decomposed into how the purchase is
 * funded. Starting from the net sale proceeds (see {@see HousingProceeds}), buying a
 * home costs its price plus the SDLT due on it and the moving costs. Whatever the
 * proceeds cover leaves a surplus invested; a purchase above the proceeds is funded
 * from documented sources only, in order:
 *
 *   1. the net sale proceeds;
 *   2. a documented capital receipt landing in the SAME year as the purchase
 *      ($fundedFromReceipts — a gift, an inheritance, an outside-asset sale; see
 *      {@see CapitalReceipt}). It comes before the
 *      savings because it is money arriving anyway and spending it realises no gain,
 *      where drawing a GIA to the same value pays CGT nobody owes. The spent part is
 *      consumed, so the projection does not also bank it as that year's income;
 *   3. the household's liquid savings ($fundedFromSavings — drawn cash → GIA → ISA,
 *      never pensions; see {@see SavingsFunding});
 *   4. an interest-only (RIO) mortgage on the new home ($mortgage), when one is
 *      configured;
 *   5. anything left is $unfundedGap — money the plan does NOT have. It is never
 *      conjured: the forecast charges it as a year-0 one-off cost, so an unfunded
 *      buy visibly fails instead of being handed the home for free.
 *
 * Holding every part is what makes the figures reconcilable. The invariant, asserted
 * at construction so a non-reconciling decomposition can never exist:
 *
 *   netProceeds + fundedFromReceipts + fundedFromSavings + mortgage + unfundedGap
 *     == buyPrice + stampDuty + movingCosts + surplus
 *
 * This is the single source for the buy-side figures: {@see HousingComparison::buyVariant}
 * and any UI breakdown read it, so the parts can never drift from the total they sum to.
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
        public readonly Money $fundedFromReceipts,
        public readonly Money $fundedFromSavings,
        public readonly Money $unfundedGap,
    ) {
        $in = $netProceeds->pence + $fundedFromReceipts->pence + $fundedFromSavings->pence + $mortgage->pence + $unfundedGap->pence;
        $out = $buyPrice->pence + $stampDuty->pence + $movingCosts->pence + $surplus->pence;
        if ($in !== $out) {
            throw new \InvalidArgumentException(
                "HousingPurchase does not reconcile: sources {$in}p != uses {$out}p "
                .'(netProceeds + fundedFromReceipts + fundedFromSavings + mortgage + unfundedGap '
                .'must equal buyPrice + stampDuty + movingCosts + surplus).'
            );
        }
    }

    /** True when the proceeds cover the purchase and its costs from cash alone (no savings draw, no mortgage). */
    public function coversPurchase(): bool
    {
        return $this->netProceeds->pence >= $this->totalCost()->pence;
    }

    /** True when every pound of the purchase traces to a documented source (no unfunded gap). */
    public function isFullyFunded(): bool
    {
        return $this->unfundedGap->isZero();
    }

    /** The full cost of buying: price + SDLT + moving costs. */
    public function totalCost(): Money
    {
        return $this->buyPrice->plus($this->stampDuty)->plus($this->movingCosts);
    }
}
