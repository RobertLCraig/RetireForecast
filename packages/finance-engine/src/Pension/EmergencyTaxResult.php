<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The tax a pension provider deducts at source on a first flexible withdrawal
 * under the emergency (Month-1, non-cumulative) basis, with the per-band breakdown
 * so the over-deduction can be shown clearly.
 */
final class EmergencyTaxResult
{
    /**
     * @param  list<array{rate: Percent, amount: Money, tax: Money}>  $bands
     */
    public function __construct(
        public readonly Money $taxablePayment,
        public readonly Money $taxDeducted,
        public readonly array $bands,
    ) {}
}
