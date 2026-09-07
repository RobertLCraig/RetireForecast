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
        /**
         * The part of $residenceNilRateBandUsed that comes from the downsizing addition rather
         * than from a home still owned at death. Reported apart because it is the whole reason a
         * plan with no home, or a cheaper one, still gets a band: a reader shown a residence
         * nil-rate band beside no house has to be able to see where it came from.
         */
        public readonly Money $downsizingAddition,
        /**
         * The unused pension pot passing on this death, whether or not it is inside the estate for
         * Inheritance Tax. Reported so the reader can see WHAT the second charge below is charged
         * on: it is the same money the estate line already contains, not an extra asset.
         */
        public readonly Money $unusedPensionPassing,
        /**
         * The BENEFICIARY's own income tax on drawing that pot, where the member died at or after
         * 75 ({@see InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE}). It is not a tax on the
         * estate and is deliberately NOT added into $tax or the outcome's total Inheritance Tax:
         * it falls on somebody else, in later years, and merging the two would misstate both.
         */
        public readonly Money $beneficiaryIncomeTax,
        /** The rate that charge was computed at; null where there is no such charge to show. */
        public readonly ?Percent $beneficiaryMarginalRate = null,
    ) {}
}
