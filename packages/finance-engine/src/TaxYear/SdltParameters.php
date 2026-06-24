<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Stamp Duty Land Tax bands and the additional-property surcharge, for England and
 * Northern Ireland.
 *
 * ⚠️ Wales (Land Transaction Tax) and Scotland (Land and Buildings Transaction Tax)
 * are different taxes with different bands; this models SDLT only. The region
 * resolver should swap these for LTT/LBTT before a Welsh or Scottish purchase is
 * shown as real.
 */
final class SdltParameters
{
    /**
     * @param  list<SdltBand>  $bands  ordered by threshold ascending; the first band's
     *                                 threshold is zero
     */
    public function __construct(
        public readonly array $bands,
        public readonly Percent $additionalPropertySurchargeRate,
    ) {}
}
