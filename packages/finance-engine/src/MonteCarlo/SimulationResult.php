<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The aggregated outcome of a Monte Carlo run: the headline success probabilities,
 * the spread of terminal wealth, how often and when the money ran out, and the
 * per-year percentile bands for the fan chart. All money figures are REAL (today's
 * money). $seed is recorded so any run is reproducible.
 *
 * $terminalWealthPercentiles is total wealth (incl. the primary residence);
 * $usableWealthPercentiles is the spendable part (excl. the home), so an asset-rich
 * household whose money runs out does not read as the "wealthiest" outcome.
 *
 * $fanChart is the per-year band of TOTAL wealth (incl. home); $usableFanChart is the
 * same per-year band of USABLE wealth (excl. home = liquid + pension, the spendable
 * money that actually burns down). The two share calendar years and satisfy usable <=
 * total in every year. Usable is the honest "will it last" series — the home is an
 * illiquid floor that props total wealth up without paying any bills.
 */
final class SimulationResult
{
    /**
     * @param  array{p10: Money, p25: Money, p50: Money, p75: Money, p90: Money}  $terminalWealthPercentiles
     * @param  list<array{calendarYear: int, paths: int, p10: Money, p25: Money, p50: Money, p75: Money, p90: Money}>  $fanChart
     * @param  array{p10: Money, p25: Money, p50: Money, p75: Money, p90: Money}|array{}  $usableWealthPercentiles
     * @param  list<array{calendarYear: int, paths: int, p10: Money, p25: Money, p50: Money, p75: Money, p90: Money}>  $usableFanChart
     */
    public function __construct(
        public readonly int $nPaths,
        public readonly int $seed,
        public readonly float $successProbabilityEssentials,
        public readonly float $successProbabilityFullSpend,
        public readonly float $depletionRate,
        public readonly ?int $medianDepletionYear,
        public readonly array $terminalWealthPercentiles,
        public readonly array $fanChart,
        public readonly array $usableWealthPercentiles = [],
        public readonly array $usableFanChart = [],
    ) {}
}
