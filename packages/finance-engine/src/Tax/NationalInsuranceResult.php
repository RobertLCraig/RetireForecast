<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tax;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The outcome of a Class 1 employee National Insurance calculation: the total due
 * and the per-band breakdown, so the working partner's take-home can be shown and
 * so it is obvious that NI stops entirely once State Pension age is reached.
 */
final class NationalInsuranceResult
{
    /**
     * @param list<array{rate: Percent, amount: Money, contribution: Money}> $bands
     */
    public function __construct(
        public readonly Money $total,
        public readonly array $bands,
    ) {
    }
}
