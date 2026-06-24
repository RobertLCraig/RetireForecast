<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Inheritance Tax parameters for one tax year.
 *
 *  - nilRateBand (£325,000) and residenceNilRateBand (£175,000, when the home passes
 *    to direct descendants) are the tax-free thresholds; both are transferable
 *    between spouses, so a couple can have up to double on the second death.
 *  - the residence band tapers away by £1 for every £2 of estate above
 *    residenceNilRateBandTaperThreshold (£2,000,000).
 *  - rate (40%) applies to the estate above the available bands.
 *
 * ⚠️ All four figures are frozen but need a confirmatory gov.uk citation. From April
 * 2027 unused pension pots are due to count towards the estate, which is modelled
 * behind a toggle and must be re-verified before go-live.
 */
final class IhtParameters
{
    public function __construct(
        public readonly Money $nilRateBand,
        public readonly Money $residenceNilRateBand,
        public readonly Money $residenceNilRateBandTaperThreshold,
        public readonly Percent $taperRate,
        public readonly Percent $rate,
    ) {}
}
