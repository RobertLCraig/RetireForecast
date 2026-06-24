<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

/**
 * Immutable snapshot of the statutory figures for one UK tax year and region.
 *
 * This is the spine of the engine: every calculator reads its rates and
 * thresholds from here and nowhere else. Each figure group carries a source URL
 * and the date it was verified, so any output is auditable back to gov.uk and
 * nothing is a magic number.
 */
final class TaxYearConfig
{
    /**
     * @param  array<string, string>  $sources  figure group => gov.uk (or official) source URL
     */
    public function __construct(
        public readonly string $taxYear,
        public readonly RegionProfile $region,
        public readonly IncomeTaxParameters $incomeTax,
        public readonly DividendParameters $dividends,
        public readonly SavingsParameters $savings,
        public readonly NationalInsuranceParameters $nationalInsurance,
        public readonly PensionParameters $pension,
        public readonly StatePensionParameters $statePension,
        public readonly SdltParameters $sdlt,
        public readonly CgtParameters $cgt,
        public readonly BenefitsParameters $benefits,
        public readonly IhtParameters $iht,
        public readonly CareParameters $care,
        public readonly array $sources,
        public readonly string $verifiedOn,
    ) {}
}
