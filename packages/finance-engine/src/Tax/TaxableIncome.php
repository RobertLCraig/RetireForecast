<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tax;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * A person's taxable income split into the three UK tax categories, which are
 * taxed in a fixed stacking order: non-savings (earnings, pensions, State Pension,
 * rental) first, then savings (interest), then dividends on top.
 *
 * The order matters because each category fills the rate bands left by the one
 * below it, and the savings starting-rate band, Personal Savings Allowance and
 * dividend allowance all consume band space even though they are charged at 0%.
 * That is why a single combined calculation is required rather than three
 * independent ones.
 */
final class TaxableIncome
{
    /**
     * The part of $nonSavings that is property income (rent less its costs). It is INSIDE
     * $nonSavings, not added to it, so every caller that only knows the non-savings total is still
     * right; the calculator stacks this part last within non-savings and taxes it at the property
     * rates, which differ from the main rates from 6 April 2027 (board card 0213).
     */
    public readonly Money $propertyWithin;

    public function __construct(
        public readonly Money $nonSavings,
        public readonly Money $savings,
        public readonly Money $dividends,
        ?Money $propertyWithin = null,
    ) {
        $this->propertyWithin = Money::fromPence(max(0, min($propertyWithin?->pence ?? 0, $nonSavings->pence)));
    }

    public static function ofNonSavings(Money $nonSavings): self
    {
        return new self($nonSavings, Money::zero(), Money::zero());
    }

    public function total(): Money
    {
        return $this->nonSavings->plus($this->savings)->plus($this->dividends);
    }
}
