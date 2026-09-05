<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Care\CareEpisode;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;

/**
 * The deterministic path: every year uses the AssumptionSet's expected (mean)
 * real returns and inflation, and each person dies at a single representative age
 * (their median age at death from the cohort life table). This is the central
 * "best estimate" projection; the Monte Carlo replaces these constants with sampled
 * sequences and sampled death ages.
 *
 * Care is normally absent from the central estimate (it is a Monte Carlo risk, so
 * {@see careAnnualCost} returns 0). The optional $careEpisodes let a caller inject an
 * explicit care spell to run the SAME deterministic path as a labelled "if significant
 * care is needed" stress scenario ({@see DeterministicForecaster::forecastWithCareStress}),
 * shown beside the care-free base — never averaged into it. Empty (the default) keeps the
 * path byte-identical to the care-free central estimate.
 */
final class DeterministicPathDraws implements PathDraws
{
    private readonly float $investmentReturn;

    private readonly float $cashReturn;

    private readonly float $inflationRate;

    private readonly float $houseGrowth;

    private readonly float $salaryGrowth;

    private readonly float $incomeYield;

    private readonly float $careCostRealGrowth;

    private readonly float $investmentCharge;

    /**
     * @param  array<string, int>  $deathAges  personId => age at death
     * @param  array<string, CareEpisode>  $careEpisodes  personId => injected care spell (empty = care-free)
     */
    public function __construct(
        AssumptionSet $set,
        PortfolioAllocation $allocation,
        private readonly array $deathAges,
        private readonly array $careEpisodes = [],
    ) {
        $this->investmentReturn = $allocation->blendedRealReturn($set);
        $this->cashReturn = $set->assetClasses[count($set->assetClasses) - 1]->expectedRealReturn->asFraction();
        $this->inflationRate = $set->inflationMean->asFraction();
        $this->houseGrowth = $set->houseGrowth->asFraction();
        $this->salaryGrowth = $set->salaryGrowth->asFraction();
        $this->incomeYield = $set->investmentIncomeYield->asFraction();
        $this->careCostRealGrowth = $set->careCostRealGrowth()->asFraction();
        $this->investmentCharge = $set->investmentCharge()->asFraction();
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

    public function investmentChargeRate(): float
    {
        return $this->investmentCharge;
    }

    public function inflation(int $yearIndex): float
    {
        return $this->inflationRate;
    }

    public function propertyGrowthReal(int $yearIndex, ?float $meanReal = null): float
    {
        // No shock on the central path, so a property override is simply the mean it names and
        // the single-property uplift has nothing to widen.
        return $meanReal ?? $this->houseGrowth;
    }

    public function salaryGrowthReal(int $yearIndex): float
    {
        return $this->salaryGrowth;
    }

    public function deathAge(string $personId): int
    {
        return $this->deathAges[$personId] ?? CohortLifeTable::MAX_AGE;
    }

    /**
     * Care cost this year: 0 for the care-free central estimate, or the injected spell's real fee
     * when this is run as a care-stress scenario (see the class docblock).
     */
    public function careAnnualCost(string $personId, int $age): int
    {
        return isset($this->careEpisodes[$personId]) ? $this->careEpisodes[$personId]->annualCostAt($age) : 0;
    }

    public function careCostRealGrowth(): float
    {
        return $this->careCostRealGrowth;
    }
}
