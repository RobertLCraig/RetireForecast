<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Capital Gains Tax parameters for residential property, for one tax year.
 *
 * A main home is normally fully relieved by Private Residence Relief, so the couple
 * pay no CGT on selling it; these figures matter for the edges (a property that was
 * let or was not the main home throughout) and for gains on a General Investment
 * Account.
 *
 * Verified against gov.uk/capital-gains-tax/rates on 2026-06-27: residential gains are
 * 18% within the basic-rate band and 24% above it, the annual exempt amount is £3,000,
 * and the final 9 months of ownership always qualify for Private Residence Relief (HS283).
 */
final class CgtParameters
{
    public function __construct(
        public readonly Money $annualExemptAmount,
        public readonly Percent $residentialBasicRate,
        public readonly Percent $residentialHigherRate,
        public readonly int $privateResidenceFinalExemptionMonths,
    ) {}
}
