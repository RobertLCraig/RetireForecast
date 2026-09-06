<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

use RetireForecast\FinanceEngine\Money\IntMath;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Assesses how a household's capital affects pension-age means-tested benefits.
 *
 * The first £10,000 is ignored; every £500 (or part) above it is treated as £1 a
 * week of income (the pensioner tariff). Pension Credit applies that tariff with no
 * upper limit, but Housing Benefit and Council Tax Support are lost once capital
 * reaches the £16,000 limit. This is the downsizing trap: selling the home turns an
 * exempt asset into assessable capital that can both create tariff income and end
 * housing support.
 *
 * The one exception is a household actually being paid Guarantee Credit: it is passported to
 * both, with no upper capital limit, so pass $onGuaranteeCredit and no cliff is reported.
 */
final class CapitalAssessment
{
    public function __construct(private readonly TaxYearConfig $config) {}

    public function assess(Money $assessableCapital, bool $onGuaranteeCredit = false): CapitalAssessmentResult
    {
        $params = $this->config->benefits;

        $tariffWeekly = $this->tariffIncomeWeekly($assessableCapital);

        // Housing support stops once capital exceeds the upper limit — EXCEPT for a household
        // being paid Guarantee Credit, which is passported to Housing Benefit and Council Tax
        // Reduction with no upper capital limit at all. Getting that edge wrong is the worse of
        // the two errors available here: it tells a household on the lowest income in the model
        // that its savings have ended help it is in fact still entitled to.
        $housingSupportEligible = $onGuaranteeCredit
            || $assessableCapital->lessThanOrEqual($params->housingSupportUpperCapitalLimit);

        $warnings = [];
        if (! $housingSupportEligible) {
            $warnings[] = new Warning(
                WarningCode::CAPITAL_CLIFF_HB_CTS,
                'Assessable capital of '.$assessableCapital->format().' is above the '
                .$params->housingSupportUpperCapitalLimit->format()
                .' limit, so Housing Benefit and Council Tax Support are not payable. '
                .'Selling a home converts an exempt asset into assessable capital, which '
                .'can cross this limit.',
            );
        }

        return new CapitalAssessmentResult(
            assessableCapital: $assessableCapital,
            tariffIncomeWeekly: $tariffWeekly,
            tariffIncomeAnnual: $tariffWeekly->times($this->config->statePension->weeksPerYear),
            housingSupportEligible: $housingSupportEligible,
            housingSupportUpperCapitalLimit: $params->housingSupportUpperCapitalLimit,
            warnings: $warnings,
        );
    }

    /**
     * £1 a week for every £500 (or part of £500) of capital above the disregard.
     */
    private function tariffIncomeWeekly(Money $capital): Money
    {
        $params = $this->config->benefits;

        $excess = $capital->minus($params->capitalDisregard)->minZero();
        if ($excess->isZero()) {
            return Money::zero();
        }

        // Round the number of £500 steps UP (a part-step still counts as one).
        $steps = IntMath::divRound($excess->pence, $params->tariffStep->pence, RoundingMode::Ceil);

        return $params->tariffIncomePerStepWeekly->times($steps);
    }
}
