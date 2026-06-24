<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Dividend allowance and rates for one tax year.
 *
 * The rates rise in 2026/27 (ordinary 8.75% -> 10.75%, upper 33.75% -> 35.75%),
 * which is exactly why these are keyed per tax year rather than shared.
 */
final class DividendParameters
{
    public function __construct(
        public readonly Money $allowance,
        public readonly Percent $ordinaryRate,
        public readonly Percent $upperRate,
        public readonly Percent $additionalRate,
    ) {
    }
}
