<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;

/**
 * The deterministic path: every year uses the AssumptionSet's expected (mean)
 * real returns and inflation, and each person dies at a single representative age
 * (their median age at death from the cohort life table). This is the central
 * "best estimate" projection; the Monte Carlo replaces these constants with sampled
 * sequences and sampled death ages.
 */
final class DeterministicPathDraws implements PathDraws
{
    private readonly float $investmentReturn;

    private readonly float $cashReturn;

    private readonly float $inflationRate;

    private readonly float $houseGrowth;

    private readonly float $salaryGrowth;

    private readonly float $incomeYield;

    /**
     * @param  array<string, int>  $deathAges  personId => age at death
     */
    public function __construct(
        AssumptionSet $set,
        PortfolioAllocation $allocation,
        private readonly array $deathAges,
    ) {
        $this->investmentReturn = $allocation->blendedRealReturn($set);
        $this->cashReturn = $set->assetClasses[count($set->assetClasses) - 1]->expectedRealReturn->asFraction();
        $this->inflationRate = $set->inflationMean->asFraction();
        $this->houseGrowth = $set->houseGrowth->asFraction();
        $this->salaryGrowth = $set->salaryGrowth->asFraction();
        $this->incomeYield = $set->investmentIncomeYield->asFraction();
    }

    public function investmentRealReturn(int $yearIndex): float
    {
        return $this->investmentReturn;
    }

    public function cashRealReturn(int $yearIndex): float
    {
        return $this->cashReturn;
    }

    public function investmentIncomeYield(): float
    {
        return $this->incomeYield;
    }

    public function inflation(int $yearIndex): float
    {
        return $this->inflationRate;
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
        return $this->deathAges[$personId] ?? CohortLifeTable::MAX_AGE;
    }

    /** No care in the central best-estimate projection (care is a Monte Carlo risk). */
    public function careAnnualCost(string $personId, int $age): int
    {
        return 0;
    }
}
