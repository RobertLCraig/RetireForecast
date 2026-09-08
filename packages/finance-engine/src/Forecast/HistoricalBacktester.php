<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Runs the plan through every eligible historical starting year, replaying that year's real
 * return + inflation sequence ({@see HistoricalSequenceDraws}) over the current household. This
 * is the sequence-of-returns stress test: "how would this exact plan have fared had it begun in
 * each of the past ~140 years, including the worst real starts (1929, 1973-74, 2000, 2007)?"
 *
 * A start year is eligible only if at least $minHistoricalYears of real data follow it, so every
 * tested start gets a genuine historical *early* run (the window where sequence risk bites);
 * beyond the data the draws revert to the assumption's expected returns. Each start reuses the
 * same representative death ages as the deterministic forecast, so only the market path varies.
 *
 * $horizon overrides which planning horizon those death ages are taken at (board card 0064). A bad
 * early sequence and a long life are two risks that MULTIPLY, and holding the lifespan at the one
 * horizon the deterministic forecast runs to means the stress test never combines them: the plan is
 * asked to survive 1973 or 1929, but only for as long as the household is expected to live anyway.
 * Null keeps the settings' own horizon, which is the representative run. A lifespan the reader
 * STATED is still never extended: {@see RepresentativeDeathAge::forHousehold} treats it as a fact
 * rather than an estimate, so the long-life run and the representative one agree for that household.
 */
final class HistoricalBacktester
{
    public function __construct(
        private readonly TaxYearConfig $config,
        private readonly CohortLifeTable $lifeTable,
    ) {}

    public function backtest(Household $household, AssumptionSet $assumptions, ForecastSettings $settings, int $minHistoricalYears = 10, ?PlanningHorizon $horizon = null): HistoricalBacktestResult
    {
        $deathAges = RepresentativeDeathAge::forHousehold($household, $this->lifeTable, $settings->baseYear, $horizon ?? $settings->planningHorizon);
        $projector = new PathProjector($this->config);
        $allocation = $settings->allocation();
        $latestEligible = HistoricalReturns::lastYear() - $minHistoricalYears + 1;

        $outcomes = [];
        foreach (HistoricalReturns::years() as $startYear) {
            if ($startYear > $latestEligible) {
                break;
            }
            $draws = new HistoricalSequenceDraws($assumptions, $allocation, $startYear, $deathAges);
            $result = $projector->project($household, $settings, $draws);
            $outcomes[] = new HistoricalBacktestOutcome(
                startYear: $startYear,
                essentialsAlwaysMet: $result->essentialsAlwaysMet,
                depletionCalendarYear: $result->depletionCalendarYear,
                planYears: count($result->years),
                terminalUsableWealth: $result->terminalUsableWealth,
            );
        }

        return new HistoricalBacktestResult($outcomes);
    }
}
