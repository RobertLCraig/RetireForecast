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
 *
 * $mainRate is the standard employee rate on the main band (categories A, F, H, M, N, V);
 * $reducedMainRate is the married-women's/widow's reduced rate (categories B, E, I) and
 * $deferredMainRate the deferred rate for someone paying maximum NI elsewhere (categories
 * D, J, L, Z). The upper-band rate ($upperRate) is the same across all of these, and a few
 * categories (C, K, S, X) carry no employee NI at all. {@see NationalInsuranceCalculator}
 * selects the band rate from the category letter.
 */
final class NationalInsuranceParameters
{
    public function __construct(
        public readonly Money $primaryThreshold,
        public readonly Money $upperEarningsLimit,
        public readonly Percent $mainRate,
        public readonly Percent $upperRate,
        public readonly Percent $reducedMainRate,
        public readonly Percent $deferredMainRate,
    ) {}
}
