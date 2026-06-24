<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tax;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The outcome of an income-tax calculation: the total due, the personal
 * allowance actually granted (after any taper), and a per-band breakdown so the
 * UI can show exactly where the tax landed (e.g. the lump-sum "tax shock" panel).
 */
final class IncomeTaxResult
{
    /**
     * @param  list<array{rate: Percent, amount: Money, tax: Money}>  $bands
     */
    public function __construct(
        public readonly Money $total,
        public readonly Money $personalAllowance,
        public readonly array $bands,
    ) {}
}
