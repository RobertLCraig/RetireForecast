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
    public function __construct(private readonly TaxYearConfig $config) {}

    /** Categories whose employee (primary) contribution is nil (C = over SPA, K/S/X = no liability). */
    private const NO_PRIMARY_NI = ['C', 'K', 'S', 'X'];

    /** Married-women's / widow's reduced-rate categories. */
    private const REDUCED_RATE = ['B', 'E', 'I'];

    /** Deferred categories (the earner pays maximum NI in another job): the reduced main-band rate. */
    private const DEFERRED_RATE = ['D', 'J', 'L', 'Z'];

    /**
     * @param  bool  $hasReachedStatePensionAge  when true, no NI is due regardless of
     *                                           earnings (NI ends at State Pension age)
     * @param  string|null  $category  the NI category letter; null/unrecognised = the standard
     *                                 rate (category A). Selects the main-band rate.
     */
    public function onEmploymentEarnings(Money $earnings, bool $hasReachedStatePensionAge = false, ?string $category = null): NationalInsuranceResult
    {
        $category = strtoupper(trim($category ?? ''));

        if ($hasReachedStatePensionAge || in_array($category, self::NO_PRIMARY_NI, true)) {
            return new NationalInsuranceResult(total: Money::zero(), bands: []);
        }

        $params = $this->config->nationalInsurance;

        $mainBandLower = $params->primaryThreshold;
        $mainBandUpper = $params->upperEarningsLimit;

        // The main-band rate depends on the category; the upper-band rate is common to all of them.
        $mainRate = match (true) {
            in_array($category, self::REDUCED_RATE, true) => $params->reducedMainRate,
            in_array($category, self::DEFERRED_RATE, true) => $params->deferredMainRate,
            default => $params->mainRate, // A, F, H, M, N, V and any unrecognised letter
        };

        $mainAmount = Money::min($earnings, $mainBandUpper)->minus($mainBandLower)->minZero();
        $upperAmount = $earnings->minus($mainBandUpper)->minZero();

        $mainContribution = $mainAmount->applyRate($mainRate);
        $upperContribution = $upperAmount->applyRate($params->upperRate);

        return new NationalInsuranceResult(
            total: $mainContribution->plus($upperContribution),
            bands: [
                ['rate' => $mainRate, 'amount' => $mainAmount, 'contribution' => $mainContribution],
                ['rate' => $params->upperRate, 'amount' => $upperAmount, 'contribution' => $upperContribution],
            ],
        );
    }
}
