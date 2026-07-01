<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The outcome of running the plan against one historical starting year: whether the
 * essential floor was met every year (the plan "survived" that start), the plan-time
 * calendar year essentials first went unmet (null = never), how many years were projected,
 * and the usable wealth left at the end (real / today's money). $startYear is the HISTORICAL
 * year whose return + inflation sequence was replayed, not a plan-time year.
 */
final class HistoricalBacktestOutcome
{
    public function __construct(
        public readonly int $startYear,
        public readonly bool $essentialsAlwaysMet,
        public readonly ?int $depletionCalendarYear,
        public readonly int $planYears,
        public readonly Money $terminalUsableWealth,
    ) {}
}
