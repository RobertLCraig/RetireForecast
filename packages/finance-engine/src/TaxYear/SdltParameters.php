<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Stamp Duty Land Tax bands and the additional-property surcharge, for England and
 * Northern Ireland.
 *
 * Bands verified against gov.uk/stamp-duty-land-tax/residential-property-rates on
 * 2026-06-27: 0% to £125,000, 2% to £250,000, 5% to £925,000, 10% to £1,500,000,
 * 12% above, plus a 5% additional-property surcharge (in force from 31 October 2024).
 *
 * Scope caveat (not a figure gap): Wales (Land Transaction Tax) and Scotland (Land and
 * Buildings Transaction Tax) are different taxes with different bands; this models SDLT
 * only, and the region resolver must swap to LTT/LBTT before a Welsh or Scottish
 * purchase is shown as real.
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
