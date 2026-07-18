<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use RetireForecast\FinanceEngine\Care\CareEpisode;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Forecast\PathDraws;

/**
 * One Monte Carlo path's draws: pre-generated correlated return, inflation, house-price
 * and salary-growth sequences plus sampled death ages (and any sampled late-life care
 * spells), fed to the same {@see PathProjector} the deterministic forecast uses.
 * House-price and salary growth each follow their sampled per-year path (a constant equal
 * to the mean when the set carries no volatility for that factor).
 */
final class SampledPathDraws implements PathDraws
{
    /**
     * @param  array{investment: list<float>, cash: list<float>, inflation: list<float>, house: list<float>, salary: list<float>}  $path
     * @param  array<string, int>  $deathAges
     * @param  array<string, CareEpisode>  $careEpisodes  person id => sampled care spell (empty = no care modelled)
     */
    public function __construct(
        private readonly array $path,
        AssumptionSet $set,
        private readonly array $deathAges,
        private readonly array $careEpisodes = [],
    ) {
        $this->salaryGrowth = $set->salaryGrowth->asFraction();
        $this->incomeYield = $set->investmentIncomeYield->asFraction();
        $this->careCostRealGrowth = $set->careCostRealGrowth()->asFraction();
    }

    /** Fallback salary growth (the set mean) for a path generated without a sampled salary series. */
    private readonly float $salaryGrowth;

    private readonly float $incomeYield;

    private readonly float $careCostRealGrowth;

    public function investmentRealReturn(int $yearIndex): float
    {
        return $this->at($this->path['investment'], $yearIndex);
    }

    public function cashRealReturn(int $yearIndex): float
    {
        return $this->at($this->path['cash'], $yearIndex);
    }

    public function investmentIncomeYield(): float
    {
        return $this->incomeYield;
    }

    public function inflation(int $yearIndex): float
    {
        return $this->at($this->path['inflation'], $yearIndex);
    }

    public function houseGrowthReal(int $yearIndex): float
    {
        return $this->at($this->path['house'], $yearIndex);
    }

    public function salaryGrowthReal(int $yearIndex): float
    {
        // The sampled salary path (a flat mean when the set has no salary volatility). Falls back to
        // the set mean only for a legacy path array generated without a 'salary' series.
        return isset($this->path['salary']) ? $this->at($this->path['salary'], $yearIndex) : $this->salaryGrowth;
    }

    public function deathAge(string $personId): int
    {
        return $this->deathAges[$personId] ?? 110;
    }

    public function careAnnualCost(string $personId, int $age): int
    {
        return isset($this->careEpisodes[$personId]) ? $this->careEpisodes[$personId]->annualCostAt($age) : 0;
    }

    public function careCostRealGrowth(): float
    {
        return $this->careCostRealGrowth;
    }

    /**
     * @param  list<float>  $series
     */
    private function at(array $series, int $yearIndex): float
    {
        if ($yearIndex < count($series)) {
            return $series[$yearIndex];
        }

        return $series === [] ? 0.0 : $series[count($series) - 1];
    }
}
