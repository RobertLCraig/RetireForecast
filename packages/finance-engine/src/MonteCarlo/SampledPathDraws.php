<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Forecast\PathDraws;

/**
 * One Monte Carlo path's draws: pre-generated correlated return and inflation
 * sequences plus sampled death ages, fed to the same {@see PathProjector} the
 * deterministic forecast uses. House-price and salary growth use their expected
 * values in v1.
 */
final class SampledPathDraws implements PathDraws
{
    /**
     * @param  array{investment: list<float>, cash: list<float>, inflation: list<float>}  $path
     * @param  array<string, int>  $deathAges
     */
    public function __construct(
        private readonly array $path,
        AssumptionSet $set,
        private readonly array $deathAges,
    ) {
        $this->houseGrowth = $set->houseGrowth->asFraction();
        $this->salaryGrowth = $set->salaryGrowth->asFraction();
    }

    private readonly float $houseGrowth;

    private readonly float $salaryGrowth;

    public function investmentRealReturn(int $yearIndex): float
    {
        return $this->at($this->path['investment'], $yearIndex);
    }

    public function cashRealReturn(int $yearIndex): float
    {
        return $this->at($this->path['cash'], $yearIndex);
    }

    public function inflation(int $yearIndex): float
    {
        return $this->at($this->path['inflation'], $yearIndex);
    }

    public function houseGrowthReal(int $yearIndex): float
    {
        return $this->houseGrowth;
    }

    public function salaryGrowthReal(int $yearIndex): float
    {
        return $this->salaryGrowth;
    }

    public function deathAge(string $personId): int
    {
        return $this->deathAges[$personId] ?? 110;
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
