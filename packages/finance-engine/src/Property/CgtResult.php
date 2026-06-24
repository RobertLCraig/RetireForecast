<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Property;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Capital Gains Tax on a property disposal after Private Residence Relief: how much
 * of the gain was relieved, what remained chargeable, the annual exempt amount
 * used, and the tax due.
 *
 * For a main home owned and lived in throughout, the relieved gain equals the whole
 * gain and the tax is zero — the common, reassuring case the couple will usually see.
 */
final class CgtResult
{
    public function __construct(
        public readonly Money $gain,
        public readonly Money $privateResidenceReliefGain,
        public readonly Money $chargeableGain,
        public readonly Money $annualExemptAmountUsed,
        public readonly Money $taxableGain,
        public readonly Percent $rate,
        public readonly Money $tax,
    ) {}
}
