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
 *
 * $deemedOccupationMonths is how much of the owner's time AWAY from the home still counted
 * as living there, after the statutory caps. It is reported rather than left implicit so a
 * screen can show what the absence actually bought; a reader who cannot see it has no way to
 * tell a capped allowance from an uncapped one.
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
        public readonly int $deemedOccupationMonths = 0,
    ) {}
}
