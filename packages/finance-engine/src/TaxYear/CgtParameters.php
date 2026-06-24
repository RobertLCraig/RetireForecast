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
 * ⚠️ The residential rates (18% / 24%) and the £3,000 annual exempt amount need a
 * confirmatory gov.uk citation before being shown as real.
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
