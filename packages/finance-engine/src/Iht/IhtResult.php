<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Iht;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Support\Warning;

/**
 * The Inheritance Tax position on an estate: its value, the nil-rate bands applied,
 * the taxable remainder and the tax due.
 *
 * Running this twice with $pensionsIncluded false then true shows the April 2027
 * tension behind the IHT toggle: whether to spend a pension pot down or preserve it
 * when unused pots start counting towards the estate.
 */
final class IhtResult
{
    /**
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public readonly Money $totalEstate,
        public readonly Money $nilRateBandUsed,
        public readonly Money $residenceNilRateBandUsed,
        public readonly Money $taxableEstate,
        public readonly Percent $rate,
        public readonly Money $tax,
        public readonly bool $pensionsIncluded,
        public readonly array $warnings,
    ) {}
}
