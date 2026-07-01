<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Care;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * A sampled care spell for one person on one Monte Carlo path: residential/nursing care from
 * $fromAge to $toAge (the year of death) at $annualCost a year, in today's money (the projector
 * inflates it). Ages are inclusive, so a spell of $toAge - $fromAge + 1 years.
 */
final class CareEpisode
{
    public function __construct(
        public readonly int $fromAge,
        public readonly int $toAge,
        public readonly Money $annualCost,
    ) {}

    /** The real annual care cost (pence) if the person is in care at $age this year, else 0. */
    public function annualCostAt(int $age): int
    {
        return ($age >= $this->fromAge && $age <= $this->toAge) ? $this->annualCost->pence : 0;
    }
}
