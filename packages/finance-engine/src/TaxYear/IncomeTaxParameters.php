<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Income-tax band parameters for one tax year and region (non-savings income).
 *
 * Bands are expressed as the personal allowance plus the WIDTH of the basic-rate
 * band, with a fixed absolute threshold where the additional rate begins. This
 * lets the calculator handle the personal-allowance taper cleanly: as the
 * allowance tapers away, the basic-rate band simply starts lower while the
 * additional-rate threshold stays put.
 */
final class IncomeTaxParameters
{
    public function __construct(
        public readonly Money $personalAllowance,
        public readonly Money $taperThreshold,
        public readonly Percent $taperRate,
        public readonly Money $basicRateBand,
        public readonly Money $additionalRateThreshold,
        public readonly Percent $basicRate,
        public readonly Percent $higherRate,
        public readonly Percent $additionalRate,
    ) {}
}
