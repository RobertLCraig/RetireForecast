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
 * Verified against gov.uk on 2026-06-27: NRB £325,000 (frozen to 5 April 2031), RNRB
 * £175,000 (frozen to 5 April 2030), £2,000,000 taper threshold and 40% rate. From
 * 6 April 2027 unused pension pots count towards the estate (now enacted by Finance
 * Act 2026, Royal Assent 18 March 2026); that change is modelled behind a toggle.
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
