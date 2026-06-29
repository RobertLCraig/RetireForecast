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
 *
 * $deathCalendarYears is the modelled calendar year of each person's death
 * (personId => birthYear + death age), so the "when does each life event happen"
 * layer can read it without re-deriving the death age. A person is modelled alive
 * through that year (their income is still counted) and gone the following year.
 */
final class ForecastResult
{
    /**
     * @param  list<YearResult>  $years
     * @param  array<string, int>  $deathCalendarYears  personId => modelled year of death
     */
    public function __construct(
        public readonly array $years,
        public readonly bool $essentialsAlwaysMet,
        public readonly bool $fullSpendAlwaysMet,
        public readonly ?int $depletionCalendarYear,
        public readonly Money $terminalTotalWealth,
        public readonly Money $terminalUsableWealth,
        public readonly int $finalCalendarYear,
        public readonly array $deathCalendarYears = [],
    ) {}
}
