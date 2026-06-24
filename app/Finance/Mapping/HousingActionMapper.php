<?php

declare(strict_types=1);

namespace App\Finance\Mapping;

use RetireForecast\FinanceEngine\Dto\HousingAction;

/**
 * Maps the engine's {@see HousingAction} DTO to and from the array stored as a
 * Scenario's encrypted payload. The sale/buy/rent figures are sensitive, so the
 * whole housing action lives in the encrypted blob; the scenario's variant, tax
 * year and assumption-set reference are the clear structural columns alongside it.
 */
final class HousingActionMapper
{
    public static function toArray(HousingAction $action): array
    {
        return [
            'salePrice' => Codec::pence($action->salePrice),
            'buyPrice' => Codec::penceOrNull($action->buyPrice),
            'annualRent' => Codec::penceOrNull($action->annualRent),
            'rentInflationReal' => Codec::bpsOrNull($action->rentInflationReal),
            'movingCosts' => Codec::penceOrNull($action->movingCosts),
            'sellingCostRate' => Codec::bpsOrNull($action->sellingCostRate),
        ];
    }

    public static function fromArray(array $data): HousingAction
    {
        return new HousingAction(
            salePrice: Codec::money($data['salePrice']),
            buyPrice: Codec::moneyOrNull($data['buyPrice']),
            annualRent: Codec::moneyOrNull($data['annualRent']),
            rentInflationReal: Codec::percentOrNull($data['rentInflationReal']),
            movingCosts: Codec::moneyOrNull($data['movingCosts']),
            sellingCostRate: Codec::percentOrNull($data['sellingCostRate']),
        );
    }
}
