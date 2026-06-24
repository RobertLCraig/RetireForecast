<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Iht;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Inheritance Tax on an estate, with the residence nil-rate band and spousal
 * transfer of unused bands.
 *
 * Pass $nilRateBandMultiplier = 2 for the second death of a couple, where both
 * partners' unused nil-rate bands are available. The residence band applies only to
 * the value of a home passing to direct descendants, tapers away above the £2m
 * estate threshold, and is capped at that home value.
 *
 * The $includePensionsInEstate flag models the April 2027 change: with it on,
 * unused pension pots are added to the estate, which is the crux of the spend-vs-
 * preserve decision the IHT toggle surfaces.
 */
final class InheritanceTaxCalculator
{
    public function __construct(private readonly TaxYearConfig $config) {}

    public function compute(
        Money $estateExcludingPensions,
        Money $unusedPensionValue,
        bool $includePensionsInEstate,
        Money $homePassingToDescendants,
        int $nilRateBandMultiplier = 1,
    ): IhtResult {
        $params = $this->config->iht;

        $pensionsInEstate = $includePensionsInEstate ? $unusedPensionValue : Money::zero();
        $totalEstate = $estateExcludingPensions->plus($pensionsInEstate);

        $nrb = $params->nilRateBand->times($nilRateBandMultiplier);

        // Residence band: tapered above the £2m threshold, then capped at the value
        // of the home actually passing to direct descendants.
        $rnrbBase = $params->residenceNilRateBand->times($nilRateBandMultiplier);
        $taperReduction = $totalEstate
            ->minus($params->residenceNilRateBandTaperThreshold)
            ->minZero()
            ->applyRate($params->taperRate, RoundingMode::Floor);
        $rnrbAfterTaper = $rnrbBase->minus($taperReduction)->minZero();
        $rnrb = Money::min($rnrbAfterTaper, $homePassingToDescendants);

        $taxableEstate = $totalEstate->minus($nrb)->minus($rnrb)->minZero();
        $tax = $taxableEstate->applyRate($params->rate);

        $warnings = [];
        if ($pensionsInEstate->isPositive()) {
            $warnings[] = new Warning(
                WarningCode::IHT_PENSIONS_IN_ESTATE,
                'Unused pension funds of '.$pensionsInEstate->format().' are included in the '
                .'estate for Inheritance Tax (the rule due from April 2027), increasing the '
                .'taxable estate.',
            );
        }

        return new IhtResult(
            totalEstate: $totalEstate,
            nilRateBandUsed: $nrb,
            residenceNilRateBandUsed: $rnrb,
            taxableEstate: $taxableEstate,
            rate: $params->rate,
            tax: $tax,
            pensionsIncluded: $includePensionsInEstate,
            warnings: $warnings,
        );
    }
}
