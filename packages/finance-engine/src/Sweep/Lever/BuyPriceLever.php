<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep\Lever;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * Buying cheaper: sweep the price of the home the household buys after selling the current one.
 * At each price the buy-cheaper variant is built through the SAME {@see HousingComparison} the
 * results page and Monte Carlo use (so a swept threshold reconciles with what the reader sees) —
 * sell the current home, buy at this price, invest the surplus (or borrow the shortfall on the
 * configured RIO rate). A higher buy price leaves less invested surplus, so success is monotone
 * decreasing. Mortality and the return path are unchanged across prices, so common random numbers
 * stay valid.
 *
 * The flagship lever, and the one whose threshold stacks the optimism caveats the plan flags
 * (dropped current-home costs, price-scaled new-home running costs, deterministic house growth,
 * no SDLT surcharge) — carried into the readout by the app layer, not this engine lever.
 */
final class BuyPriceLever implements SweepLever
{
    public function __construct(
        private readonly HousingComparison $comparison,
        private readonly AssumptionSet $assumptions,
        private readonly HousingAction $baseAction,
    ) {}

    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $action = new HousingAction(
            salePrice: $this->baseAction->salePrice,
            buyPrice: Money::fromPence((int) round($value * 100)),
            annualRent: $this->baseAction->annualRent,
            rentInflationReal: $this->baseAction->rentInflationReal,
            movingCosts: $this->baseAction->movingCosts,
            sellingCosts: $this->baseAction->sellingCosts,
            buyMortgageRate: $this->baseAction->buyMortgageRate,
        );

        $variant = $this->comparison->variantInputs($household, $settings, $this->assumptions, $action)['buy_outright'];

        return new SweepInputs($variant['household'], $variant['settings']);
    }

    public function name(): string
    {
        return 'buy price';
    }

    public function unit(): string
    {
        return '£';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Decreasing;
    }
}
