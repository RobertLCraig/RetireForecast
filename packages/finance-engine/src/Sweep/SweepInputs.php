<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;

/**
 * The household + settings a {@see SweepLever} produces at a given lever value — the exact
 * inputs the sweep simulates for that grid point. A lever returns a new pair rather than
 * mutating, so the sweep's base inputs are never altered between points.
 */
final class SweepInputs
{
    public function __construct(
        public readonly Household $household,
        public readonly ForecastSettings $settings,
    ) {}
}
