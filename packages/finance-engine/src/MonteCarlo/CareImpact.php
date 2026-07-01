<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The modelled late-life care-cost risk across a Monte Carlo run: the share of paths in which
 * someone needed residential/nursing care, and — among those paths — the median and p90 total
 * care bill (real, today's money). Present only when care was modelled ({@see
 * \RetireForecast\FinanceEngine\Forecast\ForecastSettings::$modelCareCost}); null otherwise, so
 * the risk is shown explicitly rather than buried inside the headline success rate.
 */
final class CareImpact
{
    public function __construct(
        public readonly float $shareOfPathsWithCare,
        public readonly Money $medianCareCost,
        public readonly Money $p90CareCost,
    ) {}
}
