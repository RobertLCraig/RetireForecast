<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Care;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The capital side of an adult social care means test: whether the person is a
 * self-funder (capital above the upper limit), and the tariff income their capital
 * generates while it sits between the lower and upper limits.
 *
 * Income is assessed separately; this models the capital test only.
 */
final class CareMeansTestResult
{
    public function __construct(
        public readonly Money $capital,
        public readonly bool $selfFunder,
        public readonly Money $tariffIncomeWeekly,
        public readonly Money $upperCapitalLimit,
        public readonly Money $lowerCapitalLimit,
    ) {}
}
