<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Class 1 employee National Insurance parameters for one tax year.
 *
 * NI is charged only on employment earnings and stops at State Pension age, so it
 * applies to the working partner alone and never to pension income.
 */
final class NationalInsuranceParameters
{
    public function __construct(
        public readonly Money $primaryThreshold,
        public readonly Money $upperEarningsLimit,
        public readonly Percent $mainRate,
        public readonly Percent $upperRate,
    ) {}
}
