<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

use RetireForecast\FinanceEngine\Money\IntMath;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
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
    /**
     * The **notional costs of sale**: 10% of a property's market value comes off before it is
     * assessed as capital, because capital is valued at what the claimant could actually realise
     * and selling a house is not free. The engine used to assess value less mortgage and nothing
     * else, overstating the capital of every household holding property it does not live in —
     * which both inflates its tariff income and pushes it towards the £16,000 cliff sooner than
     * the rules do (board card 0048).
     *
     * **PUBLIC so a presenter can DISCLOSE the figure without restating it**, the
     * no-invisible-figures rule.
     */
    public const NOTIONAL_SALE_COSTS_BPS = 1_000; // 10% of the market value

    public function __construct(private readonly TaxYearConfig $config) {}

    /** The notional costs of sale, read from the constant that owns them. */
    public static function notionalSaleCosts(): Percent
    {
        return Percent::fromBasisPoints(self::NOTIONAL_SALE_COSTS_BPS);
    }

    /**
     * What a property is worth as CAPITAL for the pension-age means test: its market value, less
     * the notional costs of sale, less anything secured on it, floored at zero.
     *
     * The order is the one the rules set and it is not interchangeable — the 10% is taken off the
     * VALUE, not off the equity — so a heavily mortgaged property can be worth nothing assessable
     * while still having equity in it.
     */
    public static function propertyCapital(Money $marketValue, Money $secured): Money
    {
        $value = $marketValue->minZero();

        return $value->minus($value->applyRate(self::notionalSaleCosts()))->minus($secured->minZero())->minZero();
    }

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
