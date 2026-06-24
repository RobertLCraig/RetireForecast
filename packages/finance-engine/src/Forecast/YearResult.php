<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\Warning;

/**
 * One year of a projection, with money figures expressed in REAL terms (today's
 * money) so a 30-year series is directly comparable. The projector works nominally
 * internally (to capture fiscal drag against frozen tax thresholds) and deflates to
 * these real figures.
 *
 * $unmetSpend is the part of the target spend that could not be funded because
 * assets were exhausted — the first year it is positive is when the money runs out.
 */
final class YearResult
{
    /**
     * @param  array<string, int>  $ages  personId => age this year
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public readonly int $yearIndex,
        public readonly int $calendarYear,
        public readonly array $ages,
        public readonly int $aliveCount,
        public readonly Money $grossIncome,
        public readonly Money $totalTax,
        public readonly Money $netIncome,
        public readonly Money $spendTarget,
        public readonly Money $shortfallFunded,
        public readonly Money $unmetSpend,
        public readonly bool $essentialsMet,
        public readonly Money $liquidWealth,
        public readonly Money $pensionWealth,
        public readonly Money $propertyWealth,
        public readonly Money $totalWealth,
        public readonly array $warnings = [],
    ) {}

    /** The full target spend was met in this year (nothing went unfunded). */
    public function fullSpendMet(): bool
    {
        return $this->unmetSpend->isZero();
    }
}
