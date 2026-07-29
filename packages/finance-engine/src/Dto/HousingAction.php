<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Housing\SellingCostComponent;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The parameters of the housing decision being compared: the assumed sale price of
 * the current home, the price of a cheaper home to buy, and the rent (with its own
 * inflation) if selling and renting instead. Selling and moving costs are netted
 * off the proceeds.
 *
 * $rentInflationReal null falls back to the AssumptionSet's rent inflation.
 *
 * $sellingCosts is the cost of selling, broken into named components each entered as a
 * % of the sale price or a flat £ ({@see SellingCostComponent}); null/empty falls back
 * to the engine's default selling-cost rate. The sum is netted off the proceeds.
 *
 * $buyMortgageRate, when set, lets the buy-cheaper leg borrow the shortfall when the new
 * home costs more than the cash the sale frees: the gap is funded by an interest-only
 * (retirement interest-only / RIO) mortgage on the new home, charged at this annual rate.
 * Null = an outright (cash-only) purchase, so a buy above the proceeds is not funded (the
 * old behaviour, flagged as unaffordable).
 *
 * $buyRunningCosts is the annual running cost of the home being BOUGHT, when it is known rather
 * than derivable — a park home's pitch fee, or a known service charge. Null keeps the existing
 * derivation (scale the current home's running costs by price, else 1% of value as a maintenance
 * proxy). Needed because some homes' costs bear no relation to their value: a pitch fee is a flat
 * annual charge, and the 1%-of-value proxy can understate it by thousands.
 *
 * $buyGrowthOverride is the REAL annual growth of the home being bought, overriding the
 * assumption set's house-price growth. **It may be NEGATIVE** — a park home DEPRECIATES (they are
 * built to a standard revised every 8-10 years, which makes older homes hard to resell, and the
 * site owner takes up to 10% commission on sale). Without this, the engine can only model a bought
 * home appreciating like bricks, so a depreciating home looks strictly better than it is. Null =
 * the assumption set's rate, as before.
 */
final class HousingAction
{
    /**
     * @param  list<SellingCostComponent>|null  $sellingCosts
     */
    public function __construct(
        public readonly Money $salePrice,
        public readonly ?Money $buyPrice = null,
        public readonly ?Money $annualRent = null,
        public readonly ?Percent $rentInflationReal = null,
        public readonly ?Money $movingCosts = null,
        public readonly ?array $sellingCosts = null,
        public readonly ?Percent $buyMortgageRate = null,
        public readonly ?Money $buyRunningCosts = null,
        public readonly ?Percent $buyGrowthOverride = null,
    ) {}
}
