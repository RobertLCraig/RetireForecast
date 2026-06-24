<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\StatePension;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Works out the new State Pension entitlement, either from a person's own forecast
 * weekly figure or pro-rata from their National Insurance qualifying years, and
 * applies any deferral uplift.
 *
 * The new State Pension needs 35 qualifying years for the full rate and at least 10
 * to receive anything; between those it is pro-rata. Deferring adds 1% for every 9
 * weeks deferred (about 5.8% a year). The result is taxable income — the caller
 * feeds {@see StatePensionResult::$annual} into the income-tax calculator as
 * non-savings income.
 */
final class StatePensionCalculator
{
    public function __construct(private readonly TaxYearConfig $config) {}

    /**
     * Use a person's own forecast weekly amount (e.g. from a State Pension statement).
     */
    public function fromWeeklyForecast(Money $weeklyForecast, int $deferralWeeks = 0): StatePensionResult
    {
        return $this->finalise($weeklyForecast, $deferralWeeks);
    }

    /**
     * Estimate the weekly amount pro-rata from NI qualifying years: the full rate
     * scaled by years/35, but nothing below the 10-year minimum.
     */
    public function fromQualifyingYears(int $qualifyingYears, int $deferralWeeks = 0): StatePensionResult
    {
        $params = $this->config->statePension;

        if ($qualifyingYears < $params->minimumQualifyingYears) {
            return $this->finalise(Money::zero(), $deferralWeeks);
        }

        $cappedYears = min($qualifyingYears, $params->fullQualifyingYears);
        $weekly = $params->newStatePensionWeekly->times($cappedYears)->dividedBy($params->fullQualifyingYears);

        return $this->finalise($weekly, $deferralWeeks);
    }

    private function finalise(Money $baseWeekly, int $deferralWeeks): StatePensionResult
    {
        $params = $this->config->statePension;

        // 1% uplift for every 9 weeks deferred: base x weeks x 1% / 9.
        $upliftWeekly = $baseWeekly
            ->times($deferralWeeks)
            ->applyRate($params->deferralUpliftPerStep)
            ->dividedBy($params->deferralWeeksPerUpliftStep);

        $weekly = $baseWeekly->plus($upliftWeekly);

        return new StatePensionResult(
            baseWeekly: $baseWeekly,
            deferralUpliftWeekly: $upliftWeekly,
            weekly: $weekly,
            annual: $weekly->times($params->weeksPerYear),
        );
    }
}
