<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * One Stamp Duty Land Tax band: the rate that applies to the slice of the purchase
 * price above {@see $threshold} (and up to the next band's threshold). SDLT is
 * progressive, so each slice is charged at its own rate.
 */
final class SdltBand
{
    public function __construct(
        public readonly Money $threshold,
        public readonly Percent $rate,
    ) {}
}
