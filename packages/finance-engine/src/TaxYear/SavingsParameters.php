<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * Savings-income tax parameters for one tax year: the Personal Savings Allowance
 * (which varies by the saver's highest tax band), the starting rate for savings, and the rates
 * savings above those are taxed at. The rates were the main rates until 5 April 2027 and are two
 * points higher from 6 April 2027 (Budget 2025), so they are carried here, not borrowed.
 */
final class SavingsParameters
{
    public function __construct(
        public readonly Money $psaBasicRate,
        public readonly Money $psaHigherRate,
        public readonly Money $psaAdditionalRate,
        public readonly Money $startingRateBand,
        public readonly Percent $startingRate,
        public readonly Percent $basicRate,
        public readonly Percent $higherRate,
        public readonly Percent $additionalRate,
    ) {}

    public function withRates(Percent $basicRate, Percent $higherRate, Percent $additionalRate): self
    {
        return new self($this->psaBasicRate, $this->psaHigherRate, $this->psaAdditionalRate,
            $this->startingRateBand, $this->startingRate, $basicRate, $higherRate, $additionalRate);
    }
}
