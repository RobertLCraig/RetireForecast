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
    ) {}
}
