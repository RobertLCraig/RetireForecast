<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * How a pension crystallisation or UFPLS divides into its tax-free and taxable
 * parts, and how much Lump Sum Allowance the tax-free part used up.
 *
 * $lsaRestricted is true when the 25% tax-free entitlement was capped by the
 * remaining Lump Sum Allowance, so some of what would have been tax-free has
 * instead fallen into the taxable part.
 */
final class TaxFreeCashSplit
{
    public function __construct(
        public readonly Money $gross,
        public readonly Money $taxFree,
        public readonly Money $taxable,
        public readonly Money $lsaUsed,
        public readonly bool $lsaRestricted,
    ) {}
}
