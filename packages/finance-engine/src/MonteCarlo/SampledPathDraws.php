<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use OutOfRangeException;
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
        $this->investmentCharge = $set->investmentCharge()->asFraction();
        $this->houseGrowth = $set->houseGrowth->asFraction();
        $this->singlePropertyMultiple = $set->singlePropertyVolatilityMultiple();
    }

    /** Fallback salary growth (the set mean) for a path generated without a sampled salary series. */
    private readonly float $salaryGrowth;

    private readonly float $incomeYield;

    private readonly float $careCostRealGrowth;

    /** The ongoing charge on invested balances: a price, not a risk, so it is not sampled. */
    private readonly float $investmentCharge;

    /** The set's house-growth mean: the centre the sampled index path was drawn around. */
    private readonly float $houseGrowth;

    /** How far one home's spread exceeds the index's ({@see AssumptionSet::singlePropertyVolatilityMultiple}). */
    private readonly float $singlePropertyMultiple;

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

    public function investmentChargeRate(): float
    {
        return $this->investmentCharge;
    }

    public function inflation(int $yearIndex): float
    {
        return $this->at($this->path['inflation'], $yearIndex);
    }

    public function propertyGrowthReal(int $yearIndex, ?float $meanReal = null): float
    {
        // Split the sampled INDEX draw into its centre and its shock, then re-centre the shock on
        // whatever mean this home actually grows at and widen it to single-property scale. Doing
        // it here rather than in {@see ReturnModel} leaves the sampled path, and so the RNG
        // stream, exactly as it was, so a seed still lines up draw for draw.
        $shock = $this->at($this->path['house'], $yearIndex) - $this->houseGrowth;

        return ($meanReal ?? $this->houseGrowth) + $shock * $this->singlePropertyMultiple;
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
     * One year's draw from a sampled series.
     *
     * A year past the end of the series is a broken invariant, not a data shortage: the series is
     * generated for the horizon the projector then walks, so the two disagreeing means one of them
     * is wrong. It used to repeat the final draw for ever (and return a flat 0.0 for an empty
     * series), which answered every extra year with a number nobody sampled and left the run
     * looking complete.
     *
     * @param  list<float>  $series
     */
    private function at(array $series, int $yearIndex): float
    {
        if ($yearIndex < 0 || $yearIndex >= count($series)) {
            throw new OutOfRangeException(
                "Sampled path has no draw for year {$yearIndex}: the series holds ".count($series)
                .' years, so the projection is running past the path generated for it.'
            );
        }

        return $series[$yearIndex];
    }
}
