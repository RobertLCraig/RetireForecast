<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;

/**
 * A {@see PathDraws} that replays a REAL historical sequence of asset returns and inflation
 * over the current plan: year index N uses the actual UK figures for calendar year
 * (startYear + N) from {@see HistoricalReturns}. This is the sequence-of-returns stress test,
 * "what if you had started this plan in 1929 / 1973 / 2000 / 2007", using measured total
 * returns rather than expected values (the deterministic path) or random draws (the Monte
 * Carlo). Only the market/inflation sequence is historical; the household, tax year, ages and
 * spend stay the plan's own (settings->baseYear), so it overlays the past onto the present.
 *
 * The invested-pot return blends the historical equity, bond and cash real returns by the
 * portfolio allocation. Beyond the data (a plan that outruns the historical series, or before
 * it) the draws fall back to the AssumptionSet's expected values, so the *early* years, where
 * sequence risk bites, are historical and only the deep tail reverts to the best estimate.
 * House-price and salary growth stay at the assumption's expected real rates in v1 (the stress
 * is on market returns and inflation, not house/wage paths); flagged for a later refinement.
 */
final class HistoricalSequenceDraws implements PathDraws
{
    private readonly float $fallbackInvestment;

    private readonly float $fallbackCash;

    private readonly float $fallbackInflation;

    private readonly float $houseGrowth;

    private readonly float $salaryGrowth;

    private readonly float $incomeYield;

    private readonly float $weightEquity;

    private readonly float $weightBond;

    private readonly float $weightCash;

    /**
     * @param  array<string, int>  $deathAges  personId => age at death
     */
    public function __construct(
        AssumptionSet $set,
        PortfolioAllocation $allocation,
        private readonly int $startYear,
        private readonly array $deathAges,
    ) {
        $this->fallbackInvestment = $allocation->blendedRealReturn($set);
        $this->fallbackCash = $set->assetClasses[count($set->assetClasses) - 1]->expectedRealReturn->asFraction();
        $this->fallbackInflation = $set->inflationMean->asFraction();
        $this->houseGrowth = $set->houseGrowth->asFraction();
        $this->salaryGrowth = $set->salaryGrowth->asFraction();
        $this->incomeYield = $set->investmentIncomeYield->asFraction();
        $this->weightEquity = $allocation->weights[0] ?? 0.0;
        $this->weightBond = $allocation->weights[1] ?? 0.0;
        $this->weightCash = $allocation->weights[2] ?? 0.0;
    }

    public function investmentRealReturn(int $yearIndex): float
    {
        $year = $this->startYear + $yearIndex;
        if (! HistoricalReturns::has($year)) {
            return $this->fallbackInvestment;
        }

        return $this->weightEquity * HistoricalReturns::equityReal($year)
            + $this->weightBond * HistoricalReturns::bondReal($year)
            + $this->weightCash * HistoricalReturns::cashReal($year);
    }

    public function cashRealReturn(int $yearIndex): float
    {
        $year = $this->startYear + $yearIndex;

        return HistoricalReturns::has($year) ? HistoricalReturns::cashReal($year) : $this->fallbackCash;
    }

    public function investmentIncomeYield(): float
    {
        return $this->incomeYield;
    }

    public function inflation(int $yearIndex): float
    {
        $year = $this->startYear + $yearIndex;

        return HistoricalReturns::has($year) ? HistoricalReturns::inflation($year) : $this->fallbackInflation;
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
}
