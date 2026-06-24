<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Care;

use RetireForecast\FinanceEngine\Money\IntMath;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * The capital test for adult social care funding (England).
 *
 * Above the upper limit (£23,250) the person pays full fees as a self-funder; below
 * the lower limit (£14,250) capital is ignored; in between, every £250 of capital is
 * treated as £1 a week of income (a "tariff"). The local authority then assesses
 * income separately, which this does not model.
 */
final class CareMeansTest
{
    public function __construct(private readonly TaxYearConfig $config) {}

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
