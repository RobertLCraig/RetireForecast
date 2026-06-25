<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The outcome of projecting one path: the year-by-year series plus the headline
 * summary the UI leads with — whether essentials and the full spend were met every
 * year, when (if ever) the money ran out, and the terminal wealth left over.
 *
 * Terminal wealth is reported two ways so the asset-rich / cash-poor case reads
 * honestly: $terminalUsableWealth is the spendable part (cash, investments, ISAs
 * and pension pots) and $terminalTotalWealth adds the illiquid primary residence.
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
        public readonly Money $terminalUsableWealth,
        public readonly int $finalCalendarYear,
    ) {}
}
