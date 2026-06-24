<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The return assumption for one asset class: its expected real (above-inflation)
 * return and its volatility (annual standard deviation of returns). Both are
 * expressed as percentages; the Monte Carlo converts them to fractions when
 * generating paths.
 */
final class AssetClassAssumption
{
    public function __construct(
        public readonly string $name,
        public readonly Percent $expectedRealReturn,
        public readonly Percent $volatility,
    ) {}
}
