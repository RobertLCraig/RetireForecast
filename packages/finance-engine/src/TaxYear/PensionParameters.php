<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Statutory pension allowances and limits for one tax year.
 *
 * These replaced the Lifetime Allowance regime from 6 April 2024 and are currently
 * frozen, so 2025/26 and 2026/27 share the same figures. They still live per tax
 * year so a future change is a config edit, never a code change.
 *
 *  - lumpSumAllowance: the cap on tax-free pension lump sums across all pensions
 *    (25% tax-free cash counts against it).
 *  - lumpSumAndDeathBenefitAllowance: the wider cap including serious-ill-health
 *    and death benefit lump sums.
 *  - annualAllowance / moneyPurchaseAnnualAllowance: the normal yearly contribution
 *    limit, and the reduced limit that applies to money-purchase contributions once
 *    pension savings have been flexibly accessed.
 *  - tapered AA: the annual allowance tapers by £1 for every £2 of adjusted income
 *    above the adjusted-income threshold, but only if threshold income also exceeds
 *    its limit, down to the tapered minimum.
 *  - pclsRate: the proportion of a crystallisation that may be taken tax-free (25%).
 *  - normalMinimumPensionAge: the earliest age flexible access is allowed (55,
 *    rising to 57 from 6 April 2028).
 */
final class PensionParameters
{
    public function __construct(
        public readonly Money $lumpSumAllowance,
        public readonly Money $lumpSumAndDeathBenefitAllowance,
        public readonly Money $annualAllowance,
        public readonly Money $moneyPurchaseAnnualAllowance,
        public readonly Money $taperedAaAdjustedIncomeThreshold,
        public readonly Money $taperedAaThresholdIncomeLimit,
        public readonly Money $taperedAaMinimum,
        public readonly Percent $taperRate,
        public readonly Percent $pclsRate,
        public readonly int $normalMinimumPensionAge,
    ) {}
}
