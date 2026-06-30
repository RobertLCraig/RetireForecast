<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The decomposition of a home sale into the cash the household actually keeps. Net
 * proceeds are what is left after the costs of selling:
 *
 *   netProceeds = max(0, salePrice − outstandingMortgage − sellingCosts − capitalGainsTax)
 *
 * Holding every part beside the total is what makes the headline figure reconcilable:
 * whenever the sale clears its costs, salePrice == netProceeds + outstandingMortgage +
 * sellingCosts + capitalGainsTax exactly (the floor only bites in negative equity, where
 * there is simply nothing to keep). capitalGainsTax is £0 in v1 — the main home is fully
 * relieved by Private Residence Relief. This is the single source for the proceeds figure:
 * the buy/rent variants and any UI breakdown read it, so the parts can never drift from
 * the total they sum to.
 *
 * $sellingCostBreakdown decomposes $sellingCosts into its named components (estate agent,
 * legal/conveyancing, ...), each already resolved to £; their amounts sum to $sellingCosts
 * exactly, so a UI can show the breakdown and it reconciles to the total by construction.
 */
final class HousingProceeds
{
    /**
     * @param  list<array{label: string, amount: Money}>  $sellingCostBreakdown
     */
    public function __construct(
        public readonly Money $salePrice,
        public readonly Money $outstandingMortgage,
        public readonly Money $sellingCosts,
        public readonly Money $capitalGainsTax,
        public readonly Money $netProceeds,
        public readonly array $sellingCostBreakdown = [],
    ) {}

    /** True when the sale cleared its costs, so the parts sum exactly to the sale price. */
    public function clearsCosts(): bool
    {
        return $this->salePrice->pence >= $this->outstandingMortgage->pence
            + $this->sellingCosts->pence
            + $this->capitalGainsTax->pence;
    }
}
