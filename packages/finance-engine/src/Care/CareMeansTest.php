<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Care;

use RetireForecast\FinanceEngine\Money\IntMath;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * The means test for adult social care funding (England).
 *
 * Above the upper capital limit (£23,250) the person pays full fees as a self-funder;
 * below the lower limit (£14,250) capital is ignored; in between, every £250 of capital
 * is treated as £1 a week of income (a "tariff"). A local-authority-funded resident
 * also contributes their assessable income, but must be left at least the Personal
 * Expenses Allowance a week ({@see CareParameters}). {@see annualCharge()} combines
 * both sides into the household-borne cost of a care year.
 */
final class CareMeansTest
{
    public function __construct(private readonly TaxYearConfig $config) {}

    /**
     * The household-borne cost of one care year under the means test, given the resident's
     * gross (self-funder) annual fee, their own assessable capital and their assessable
     * annual income. One monotone formula covers the three regimes:
     *
     *   charge = min(fee, max(0, capital - upper limit) + tariff income + max(0, income - PEA))
     *
     *  - a comfortable self-funder (capital well above the limit) pays the full fee;
     *  - a funded resident (capital at/below the limit) contributes income minus the PEA,
     *    plus the tariff income on capital between the limits — the LA pays the balance;
     *  - the crossing year pays capital down to the upper limit, then contributes from
     *    income (an annual-grid approximation of the intra-year switch to LA funding).
     *
     * All figures are NOMINAL for the same year. The capital limits and tariff are frozen
     * (as in life, 15 years running), so the caller passes nominal capital against the
     * frozen limits — the same fiscal-drag treatment as the Pension Credit capital test.
     * The PEA, unlike the limits, is uprated with inflation each April, so the caller
     * passes its cumulative inflation factor as $peaUprating.
     *
     * Assessable income is the resident's own taxable income (State Pension, DB/annuity,
     * drawdown, rental) PLUS their share of any Pension Credit Guarantee Credit, which the
     * charging regulations take into account like any other undisregarded income (the caller
     * adds it, because the award is a household figure — see the care leg of PathProjector). Income
     * derived from capital is treated as capital (the tariff covers it), and disability
     * benefits are excluded here, mirroring the payment stop once a resident is LA-funded.
     * v1 flag: the LA-vs-self-funder fee-rate gap is not modelled (the local authority is
     * assumed to buy the same place at the same price the self-funder pays, so no
     * third-party top-up is charged once the resident is funded).
     */
    public function annualCharge(Money $grossAnnualFee, Money $capital, Money $assessableAnnualIncome, float $peaUprating = 1.0): Money
    {
        $params = $this->config->care;
        $weeks = $this->config->statePension->weeksPerYear;

        $capitalAboveLimit = max(0, $capital->pence - $params->upperCapitalLimit->pence);
        $tariffAnnual = $this->assess($capital)->tariffIncomeWeekly->pence * $weeks;
        $peaAnnual = (int) round($params->personalExpensesAllowanceWeekly->pence * $weeks * $peaUprating);
        $incomeContribution = max(0, $assessableAnnualIncome->pence - $peaAnnual);

        return Money::fromPence(min($grossAnnualFee->pence, $capitalAboveLimit + $tariffAnnual + $incomeContribution));
    }

    public function assess(Money $capital): CareMeansTestResult
    {
        $params = $this->config->care;

        $selfFunder = $capital->greaterThan($params->upperCapitalLimit);

        $tariffWeekly = Money::zero();
        if (! $selfFunder && $capital->greaterThan($params->lowerCapitalLimit)) {
            $excess = $capital->minus($params->lowerCapitalLimit);
            $steps = IntMath::divRound($excess->pence, $params->tariffStep->pence, RoundingMode::Ceil);
            $tariffWeekly = $params->tariffIncomePerStepWeekly->times($steps);
        }

        return new CareMeansTestResult(
            capital: $capital,
            selfFunder: $selfFunder,
            tariffIncomeWeekly: $tariffWeekly,
            upperCapitalLimit: $params->upperCapitalLimit,
            lowerCapitalLimit: $params->lowerCapitalLimit,
        );
    }
}
