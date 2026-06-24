<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

/**
 * Settings that shape a forecast run but are not part of the household or the
 * economic assumptions: the start year, how invested pots are allocated, the
 * drawdown strategy, and the tax-year basis.
 *
 * $freezeEndYear is the year UK income-tax thresholds stop being frozen and are
 * assumed to rise with inflation again (currently April 2031); before then, frozen
 * nominal thresholds against inflating incomes produce real fiscal drag, which the
 * projector models.
 */
final class ForecastSettings
{
    public function __construct(
        public readonly int $baseYear,
        public readonly string $baseTaxYear = '2026-27',
        public readonly DrawdownStrategy $drawdownStrategy = DrawdownStrategy::TaxEfficient,
        public readonly ?PortfolioAllocation $allocation = null,
        public readonly int $freezeEndYear = 2031,
    ) {}

    public function allocation(): PortfolioAllocation
    {
        return $this->allocation ?? PortfolioAllocation::cautious40_60();
    }
}
