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
 * Lettings relief was effectively withdrawn from April 2020 except where the owner
 * shared occupancy, so a period of letting (other than the final 9 months) is
 * treated here as chargeable rather than relieved. ⚠️ Confirm the lettings-relief
 * position for any shared-occupancy case before relying on it.
 */
final class CgtPrivateResidenceCalculator
{
    public function __construct(private readonly TaxYearConfig $config) {}

    public function compute(
        Money $gain,
        int $totalOwnershipMonths,
        int $mainResidenceMonths,
        bool $higherRate,
    ): CgtResult {
        $params = $this->config->cgt;

        $relievedMonths = $mainResidenceMonths > 0
            ? min($mainResidenceMonths + $params->privateResidenceFinalExemptionMonths, $totalOwnershipMonths)
            : 0;

        $reliefGain = $totalOwnershipMonths > 0
            ? $gain->times($relievedMonths)->dividedBy($totalOwnershipMonths)
            : Money::zero();

        $chargeableGain = $gain->minus($reliefGain)->minZero();
        $aeaUsed = Money::min($chargeableGain, $params->annualExemptAmount);
        $taxableGain = $chargeableGain->minus($aeaUsed)->minZero();

        $rate = $higherRate ? $params->residentialHigherRate : $params->residentialBasicRate;
        $tax = $taxableGain->applyRate($rate);

        return new CgtResult(
            gain: $gain,
            privateResidenceReliefGain: $reliefGain,
            chargeableGain: $chargeableGain,
            annualExemptAmountUsed: $aeaUsed,
            taxableGain: $taxableGain,
            rate: $rate,
            tax: $tax,
        );
    }
}
