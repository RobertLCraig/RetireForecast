<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * A recurring income stream outside employment and pensions, active between
 * $startAge and $endAge (null = for life). $taxable says whether it feeds the
 * income-tax calculation; $inflationLinked whether the forecast grows it with
 * inflation.
 */
final class IncomeStream
{
    public function __construct(
        public readonly string $ownerId,
        public readonly IncomeStreamType $type,
        public readonly Money $grossAnnual,
        public readonly bool $taxable,
        public readonly bool $inflationLinked,
        public readonly int $startAge,
        public readonly ?int $endAge = null,
    ) {}
}
