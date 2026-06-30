<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Property;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Capital Gains Tax on selling a home, after Private Residence Relief.
 *
 * The gain is relieved for the months the property was the owner's main residence,
 * plus the final 9 months of ownership (always relieved once it has been the main
 * home at some point). The rest of the gain is chargeable. A property that was never
 * the main residence gets no relief or final-period exemption.
 *
 * Lettings relief was restricted from 6 April 2020 to cases where the owner shared
 * occupancy with the tenant (verified 2026-06-27 against gov.uk HS283 — Private
 * Residence Relief), so a period of letting (other than the final 9 months) is treated
 * here as chargeable rather than relieved. Scope caveat: the narrow shared-occupancy
 * lettings-relief case is not modelled.
 *
 * CGT is a per-individual tax, so a jointly-owned home ($owners = 2) splits the chargeable
 * gain across the owners — each with their own annual exempt amount and rate — and sums the
 * tax (gov.uk: each co-owner is taxed separately on their share). Scope caveat: one rate is
 * applied per owner ($higherRate), not a split of a single owner's gain across the 18%/24%
 * band boundary from their exact other income.
 */
final class CgtPrivateResidenceCalculator
{
    public function __construct(private readonly TaxYearConfig $config) {}

    public function compute(
        Money $gain,
        int $totalOwnershipMonths,
        int $mainResidenceMonths,
        bool $higherRate,
        int $owners = 1,
    ): CgtResult {
        $params = $this->config->cgt;
        $owners = max(1, $owners);

        $relievedMonths = $mainResidenceMonths > 0
            ? min($mainResidenceMonths + $params->privateResidenceFinalExemptionMonths, $totalOwnershipMonths)
            : 0;

        $reliefGain = $totalOwnershipMonths > 0
            ? $gain->times($relievedMonths)->dividedBy($totalOwnershipMonths)
            : Money::zero();

        $chargeableGain = $gain->minus($reliefGain)->minZero();

        // Per owner: their share of the chargeable gain, less their own annual exempt amount,
        // at their own rate. Summing gives a couple two allowances (and, often, the basic rate).
        $rate = $higherRate ? $params->residentialHigherRate : $params->residentialBasicRate;
        $perOwnerChargeable = $chargeableGain->dividedBy($owners);
        $perOwnerAea = Money::min($perOwnerChargeable, $params->annualExemptAmount);
        $perOwnerTaxable = $perOwnerChargeable->minus($perOwnerAea)->minZero();
        $perOwnerTax = $perOwnerTaxable->applyRate($rate);

        return new CgtResult(
            gain: $gain,
            privateResidenceReliefGain: $reliefGain,
            chargeableGain: $chargeableGain,
            annualExemptAmountUsed: $perOwnerAea->times($owners),
            taxableGain: $perOwnerTaxable->times($owners),
            rate: $rate,
            tax: $perOwnerTax->times($owners),
        );
    }
}
