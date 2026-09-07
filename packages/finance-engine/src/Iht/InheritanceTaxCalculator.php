<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Iht;

use RetireForecast\FinanceEngine\Dto\ResidenceDisposal;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Inheritance Tax on an estate, with the residence nil-rate band and spousal
 * transfer of unused bands.
 *
 * A home SOLD during the plan does not throw its band away: pass the disposal as
 * $formerResidenceDisposal and the lost part of the band comes back as the downsizing addition
 * ({@see downsizingAddition}). Without it, every plan this tool exists to compare against staying
 * put was penalised by tax the statute is written to prevent.
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
    /**
     * The downsizing addition is available only where the former residence was disposed of ON OR
     * AFTER 8 July 2015, the date the residence nil-rate band was announced. Held as a YEAR
     * because {@see ResidenceDisposal} carries no month: the engine models no disposal before its
     * own base year, so this can never be the deciding test and a year is enough to state the
     * rule. A disposal IN 2015 is excluded rather than admitted, which is the cautious reading of
     * a date the model cannot resolve.
     *
     * source: Inheritance Tax Act 1984 ss.8FA to 8FE (inserted by Finance Act 2016 s.93 and Sch.15),
     * and gov.uk "Inheritance Tax: residence nil rate band" downsizing guidance,
     * https://www.gov.uk/guidance/inheritance-tax-residence-nil-rate-band
     * verified_on: NOT VERIFIED. This build had no web access, so the rule and the date are
     * STATED from the statute, not checked against a live page. See docs/spec/ASSUMPTIONS.md §26
     * and board card 0125.
     */
    public const DOWNSIZING_DISPOSALS_AFTER_YEAR = 2015;

    public function __construct(private readonly TaxYearConfig $config) {}

    public function compute(
        Money $estateExcludingPensions,
        Money $unusedPensionValue,
        bool $includePensionsInEstate,
        Money $homePassingToDescendants,
        int $nilRateBandMultiplier = 1,
        ?ResidenceDisposal $formerResidenceDisposal = null,
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
        $downsizingAddition = $this->downsizingAddition(
            $formerResidenceDisposal,
            $homePassingToDescendants,
            $rnrbBase,
            $totalEstate->minus($homePassingToDescendants)->minZero(),
        );
        $rnrb = Money::min($rnrbAfterTaper, $homePassingToDescendants->plus($downsizingAddition));

        $taxableEstate = $totalEstate->minus($nrb)->minus($rnrb)->minZero();
        $tax = $taxableEstate->applyRate($params->rate);

        $warnings = [];
        if ($downsizingAddition->isPositive()) {
            $warnings[] = new Warning(
                WarningCode::IHT_DOWNSIZING_ADDITION,
                'A former home was sold during this plan, so '.$downsizingAddition->format().' of the '
                .'residence nil-rate band it would have sheltered is added back (the downsizing '
                .'addition). Without it, selling the home would throw the band away.',
            );
        }
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
            downsizingAddition: $downsizingAddition,
        );
    }

    /**
     * The downsizing addition: the part of the residence nil-rate band a DISPOSAL of a former home
     * cost the estate, given back.
     *
     * The band a home can use is capped at its own value, so a home worth less than the maximum
     * band only ever used part of it. The statute measures what a disposal lost by comparing the
     * band the former home would have used against the band the home left at death actually uses,
     * and restores the difference:
     *
     *   lost = min(disposal value, maximum band) - min(home at death, maximum band)
     *
     * The statute expresses that as PERCENTAGES of the maximum band at each of the two dates,
     * which matters when the band differs between them. Here it cannot: one {@see TaxYearConfig}
     * is in force for a whole run and the band is frozen in cash terms, so the percentage form and
     * this subtraction are the same number, and the subtraction is exact in pence where a
     * percentage would round twice.
     *
     * The addition is then capped at the value of everything OTHER than the home that passes to
     * direct descendants: the band shelters what is actually inherited, so an estate that sold the
     * house and spent the money has nothing left for the addition to work on.
     *
     * The TAPER is deliberately NOT applied here. The caller applies it to the home and the
     * addition TOGETHER, as a ceiling on the two, because the statute tapers the allowance and
     * then limits the band to what is inherited, not the other way round. Tapering the addition on
     * its own would hand a £2m-plus estate a band it is not entitled to.
     */
    private function downsizingAddition(
        ?ResidenceDisposal $disposal,
        Money $homePassingToDescendants,
        Money $maximumBand,
        Money $otherAssetsPassingToDescendants,
    ): Money {
        if ($disposal === null || $disposal->year <= self::DOWNSIZING_DISPOSALS_AFTER_YEAR) {
            return Money::zero();
        }

        $bandTheFormerHomeUsed = Money::min($disposal->netValue, $maximumBand);
        $bandTheCurrentHomeUses = Money::min($homePassingToDescendants, $maximumBand);
        $lost = $bandTheFormerHomeUsed->minus($bandTheCurrentHomeUses)->minZero();

        return Money::min($lost, $otherAssetsPassingToDescendants);
    }
}
