<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The outcome of projecting one path: the year-by-year series plus the headline
 * summary the UI leads with — whether essentials and the full spend were met every
 * year, when (if ever) the money ran out, and the terminal wealth left over.
 */
final class ForecastResult
{
    /**
     * @param  list<YearResult>  $years
     */
    public function __construct(
        public readonly array $years,
        public readonly bool $essentialsAlwaysMet,
        public readonly bool $fullSpendAlwaysMet,
        public readonly ?int $depletionCalendarYear,
        public readonly Money $terminalTotalWealth,
        public readonly int $finalCalendarYear,
    ) {}
}
