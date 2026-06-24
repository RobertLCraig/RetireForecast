<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The aggregated outcome of a Monte Carlo run: the headline success probabilities,
 * the spread of terminal wealth, how often and when the money ran out, and the
 * per-year percentile bands for the fan chart. All money figures are REAL (today's
 * money). $seed is recorded so any run is reproducible.
 */
final class SimulationResult
{
    /**
     * @param  array{p10: Money, p25: Money, p50: Money, p75: Money, p90: Money}  $terminalWealthPercentiles
     * @param  list<array{calendarYear: int, paths: int, p10: Money, p25: Money, p50: Money, p75: Money, p90: Money}>  $fanChart
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
    ) {}
}
