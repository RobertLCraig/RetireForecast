<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Savings-income tax parameters for one tax year: the Personal Savings Allowance
 * (which varies by the saver's highest tax band) and the starting rate for savings.
 */
final class SavingsParameters
{
    public function __construct(
        public readonly Money $psaBasicRate,
        public readonly Money $psaHigherRate,
        public readonly Money $psaAdditionalRate,
        public readonly Money $startingRateBand,
        public readonly Percent $startingRate,
    ) {
    }
}
