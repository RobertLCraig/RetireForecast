<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tax;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The outcome of a full income-tax calculation across non-savings, savings and
 * dividend income.
 *
 * {@see $lines} is the ordered, itemised breakdown the UI needs to explain exactly
 * where each slice of income was taxed (and where a 0% allowance, such as the
 * Personal Savings Allowance or dividend allowance, absorbed it). Each line has
 * the shape:
 *   ['type' => 'non_savings'|'savings'|'dividends',
 *    'band' => 'basic'|'higher'|'additional'|'starting_rate'|'allowance',
 *    'rate' => Percent, 'amount' => Money, 'tax' => Money]
 */
final class ComprehensiveIncomeTaxResult
{
    /**
     * @param  list<array{type: string, band: string, rate: Percent, amount: Money, tax: Money}>  $lines
     */
    public function __construct(
        public readonly Money $total,
        public readonly Money $personalAllowance,
        public readonly Money $nonSavingsTax,
        public readonly Money $savingsTax,
        public readonly Money $dividendsTax,
        public readonly array $lines,
    ) {}
}
