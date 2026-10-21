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
     * The tax year's stamp: the OLDEST of its per-source dates, derived and never stored,
     * so it cannot disagree with them (card 0122). Stored runs keep it as their config version.
     */
    public readonly string $verifiedOn;

    /**
     * @param  array<string, string>  $sources  figure group => gov.uk (or official) source URL
     * @param  array<string, string>  $verifiedOnBySource  figure group => Y-m-d its source was last read; the one home for that date
     */
    public function __construct(
        public readonly string $taxYear,
        public readonly RegionProfile $region,
        public readonly IncomeTaxParameters $incomeTax,
        public readonly DividendParameters $dividends,
        public readonly SavingsParameters $savings,
        public readonly PropertyIncomeParameters $property,
        public readonly NationalInsuranceParameters $nationalInsurance,
        public readonly PensionParameters $pension,
        public readonly IsaParameters $isa,
        public readonly StatePensionParameters $statePension,
        public readonly SdltParameters $sdlt,
        public readonly CgtParameters $cgt,
        public readonly BenefitsParameters $benefits,
        public readonly IhtParameters $iht,
        public readonly CareParameters $care,
        public readonly array $sources,
        public readonly array $verifiedOnBySource,
    ) {
        $missing = array_diff_key($sources, $verifiedOnBySource) + array_diff_key($verifiedOnBySource, $sources);
        if ($missing !== []) {
            throw new \InvalidArgumentException("{$taxYear}: sources and verified-on dates must share keys; mismatched: ".implode(', ', array_keys($missing)));
        }
        $this->verifiedOn = min($verifiedOnBySource);
    }

    /** A copy with only the income rates changed: what a scheduled rate change does to a year. */
    public function withIncomeRates(DividendParameters $dividends, SavingsParameters $savings, PropertyIncomeParameters $property): self
    {
        return new self($this->taxYear, $this->region, $this->incomeTax, $dividends, $savings, $property,
            $this->nationalInsurance, $this->pension, $this->isa, $this->statePension, $this->sdlt,
            $this->cgt, $this->benefits, $this->iht, $this->care, $this->sources, $this->verifiedOnBySource);
    }
}
