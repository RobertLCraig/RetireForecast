<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Convenience entry point for the central "best estimate" forecast: each person
 * dies at their median age from the cohort life table, and every year uses the
 * AssumptionSet's expected returns. The Monte Carlo uses {@see PathProjector}
 * directly with sampled draws instead.
 */
final class DeterministicForecaster
{
    public function __construct(
        private readonly TaxYearConfig $config,
        private readonly CohortLifeTable $lifeTable,
    ) {}

    public function forecast(Household $household, AssumptionSet $assumptions, ForecastSettings $settings): ForecastResult
    {
        $deathAges = RepresentativeDeathAge::forHousehold($household, $this->lifeTable, $settings->baseYear);

        $draws = new DeterministicPathDraws($assumptions, $settings->allocation(), $deathAges);

        return (new PathProjector($this->config))->project($household, $settings, $draws);
    }
}
