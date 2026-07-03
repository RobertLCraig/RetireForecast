<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Care\CareCostSampler;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Housing\SellingCostComponent;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Settings that shape a forecast run but are not part of the household or the
 * economic assumptions: the start year, how invested pots are allocated, the
 * drawdown strategy, and the tax-year basis.
 *
 * $freezeEndYear is the year UK income-tax thresholds stop being frozen and are
 * assumed to rise with inflation again (currently April 2031); before then, frozen
 * nominal thresholds against inflating incomes produce real fiscal drag, which the
 * projector models.
 *
 * $annualRent (with $rentInflationReal) models the rent leg of a "sell and rent"
 * scenario: an essential expense on top of the household's spend that grows at its
 * own real rate rather than CPI. Null means the household is not renting.
 *
 * $modelCareCost, when true, makes the Monte Carlo sample a late-life care spell per
 * person (see {@see CareCostSampler}), so the
 * distribution reflects the fat-tail risk of care fees. Default false, so existing
 * runs are unchanged; the deterministic and historical views never model care.
 *
 * $sellingCosts is the cost basis for an in-projection forced sale (a home whose mortgage
 * is called for redemption with {@see MortgageMaturityAction::ForcedSale}):
 * the projector has no {@see HousingAction}, so the entered
 * components ride here. Null falls back to the engine default rate. Only consumed at the
 * forced sale; irrelevant to every other run.
 */
final class ForecastSettings
{
    /**
     * @param  list<SellingCostComponent>|null  $sellingCosts
     */
    public function __construct(
        public readonly int $baseYear,
        public readonly string $baseTaxYear = '2026-27',
        public readonly DrawdownStrategy $drawdownStrategy = DrawdownStrategy::TaxEfficient,
        public readonly ?PortfolioAllocation $allocation = null,
        public readonly int $freezeEndYear = 2031,
        public readonly ?Money $annualRent = null,
        public readonly ?Percent $rentInflationReal = null,
        public readonly bool $modelCareCost = false,
        public readonly ?array $sellingCosts = null,
    ) {}

    public function allocation(): PortfolioAllocation
    {
        return $this->allocation ?? PortfolioAllocation::cautious40_60();
    }
}
