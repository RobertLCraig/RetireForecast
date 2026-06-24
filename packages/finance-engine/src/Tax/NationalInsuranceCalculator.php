<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tax;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Class 1 employee (primary) National Insurance on employment earnings.
 *
 * Earnings between the primary threshold and the upper earnings limit are charged
 * at the main rate; earnings above the upper earnings limit at the reduced upper
 * rate; earnings below the primary threshold bear no NI.
 *
 * Two rules the caller must honour and which this engine makes hard to get wrong:
 *  - NI applies ONLY to employment (and self-employment) earnings, never to
 *    pension income, drawdown, UFPLS or the State Pension.
 *  - NI stops at State Pension age. Once the worker reaches SPA, do not call this
 *    at all (or pass {@see forEarner} with $hasReachedStatePensionAge = true,
 *    which returns zero), so a retired person is never charged NI on earnings.
 */
final class NationalInsuranceCalculator
{
    public function __construct(private readonly TaxYearConfig $config)
    {
    }

    /**
     * @param bool $hasReachedStatePensionAge when true, no NI is due regardless of
     *                                         earnings (NI ends at State Pension age)
     */
    public function onEmploymentEarnings(Money $earnings, bool $hasReachedStatePensionAge = false): NationalInsuranceResult
    {
        if ($hasReachedStatePensionAge) {
            return new NationalInsuranceResult(total: Money::zero(), bands: []);
        }

        $params = $this->config->nationalInsurance;

        $mainBandLower = $params->primaryThreshold;
        $mainBandUpper = $params->upperEarningsLimit;

        $mainAmount = Money::min($earnings, $mainBandUpper)->minus($mainBandLower)->minZero();
        $upperAmount = $earnings->minus($mainBandUpper)->minZero();

        $mainContribution = $mainAmount->applyRate($params->mainRate);
        $upperContribution = $upperAmount->applyRate($params->upperRate);

        return new NationalInsuranceResult(
            total: $mainContribution->plus($upperContribution),
            bands: [
                ['rate' => $params->mainRate, 'amount' => $mainAmount, 'contribution' => $mainContribution],
                ['rate' => $params->upperRate, 'amount' => $upperAmount, 'contribution' => $upperContribution],
            ],
        );
    }
}
