<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Splits a pension crystallisation (or UFPLS) into its tax-free and taxable parts.
 *
 * Up to 25% of the amount is tax-free, but only to the extent the person's Lump Sum
 * Allowance (£268,275, tracked across all pensions) remains. Anything above the
 * remaining allowance falls into the taxable part instead of being tax-free.
 *
 * This computes the split only; what happens to the taxable part differs by kind:
 * for a UFPLS it is paid out and taxed now; for a PCLS the 75% is moved into
 * drawdown and taxed later as income.
 */
final class TaxFreeCashCalculator
{
    public function __construct(private readonly TaxYearConfig $config) {}

    public function split(Money $gross, Money $lsaRemaining): TaxFreeCashSplit
    {
        // Tax-free rounded in the taxpayer's favour (down), so tax is never understated.
        $entitlement = $gross->applyRate($this->config->pension->pclsRate, RoundingMode::Floor);

        $taxFree = Money::min($entitlement, $lsaRemaining);
        $taxable = $gross->minus($taxFree);

        return new TaxFreeCashSplit(
            gross: $gross,
            taxFree: $taxFree,
            taxable: $taxable,
            lsaUsed: $taxFree,
            lsaRestricted: $taxFree->lessThan($entitlement),
        );
    }
}
