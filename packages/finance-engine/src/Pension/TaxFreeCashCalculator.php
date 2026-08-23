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
 * drawdown and taxed later as income. Money that has already been moved there gets no
 * second quarter — see the $crystallised parameter below.
 */
final class TaxFreeCashCalculator
{
    public function __construct(private readonly TaxYearConfig $config) {}

    /**
     * @param  Money|null  $crystallised  how much of the payment comes out of money already
     *                                    designated to drawdown. That money has had its tax-free
     *                                    quarter and gets no second one, so only what is left of
     *                                    the payment earns an entitlement. Null (the default) is
     *                                    the ordinary wholly-uncrystallised pot. Same rule as
     *                                    {@see \RetireForecast\FinanceEngine\Forecast\PathProjector::ufplsSplit},
     *                                    which is the projector's integer-pence twin of this
     *                                    method; `TaxFreeCashCrystallisationParityTest` holds the
     *                                    two to the same answer, because the panel used to show a
     *                                    quarter tax-free where the forecast charged full tax.
     */
    public function split(Money $gross, Money $lsaRemaining, ?Money $crystallised = null): TaxFreeCashSplit
    {
        $uncrystallised = $gross->minus($crystallised ?? Money::zero())->minZero();

        // Tax-free rounded in the taxpayer's favour (down), so tax is never understated.
        $entitlement = $uncrystallised->applyRate($this->config->pension->pclsRate, RoundingMode::Floor);

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
