<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

/**
 * The spread of how long the household lasts, read off the Monte Carlo's joint-life
 * mortality sampler (so it reflects the same sampled deaths the wealth paths ran on, not
 * a separate guess). Framed around the LAST survivor — the person who lives longest, since
 * that is how long the money must last for a couple.
 *
 *  - lastSurvivorAge p10/p50/p90: the age the last survivor reaches (a low/typical/high life).
 *  - planYears p50/p90: years from the base year the money may need to last (p90 = a prudent
 *    planning horizon, the "plan to roughly here" figure).
 *  - reaches95 / reaches100: the probability that at least one of the household is still alive
 *    at 95 / 100 (a tail-risk the median hides).
 *
 * Purely descriptive — a distribution of outcomes, never a recommendation.
 */
final class LongevityDistribution
{
    public function __construct(
        public readonly int $lastSurvivorAgeP10,
        public readonly int $lastSurvivorAgeP50,
        public readonly int $lastSurvivorAgeP90,
        public readonly int $planYearsP50,
        public readonly int $planYearsP90,
        public readonly float $reaches95,
        public readonly float $reaches100,
    ) {}
}
