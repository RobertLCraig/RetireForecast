<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The parameters of the housing decision being compared: the assumed sale price of
 * the current home, the price of a cheaper home to buy, and the rent (with its own
 * inflation) if selling and renting instead. Selling and moving costs are netted
 * off the proceeds.
 *
 * $rentInflationReal null falls back to the AssumptionSet's rent inflation.
 */
final class HousingAction
{
    public function __construct(
        public readonly Money $salePrice,
        public readonly ?Money $buyPrice = null,
        public readonly ?Money $annualRent = null,
        public readonly ?Percent $rentInflationReal = null,
        public readonly ?Money $movingCosts = null,
        public readonly ?Percent $sellingCostRate = null,
    ) {}
}
