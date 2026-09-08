<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Care\CareStressScenario;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Convenience entry point for the central "best estimate" forecast: the plan runs to the
 * household's last-survivor horizon at the settings' chosen percentile
 * ({@see RepresentativeDeathAge}), and every year uses the AssumptionSet's expected returns.
 * The Monte Carlo uses {@see PathProjector} directly with sampled draws instead.
 */
final class DeterministicForecaster
{
    public function __construct(
        private readonly TaxYearConfig $config,
        private readonly CohortLifeTable $lifeTable,
    ) {}

    public function forecast(Household $household, AssumptionSet $assumptions, ForecastSettings $settings): ForecastResult
    {
        $deathAges = RepresentativeDeathAge::forHousehold($household, $this->lifeTable, $settings->baseYear, $settings->planningHorizon);

        $draws = new DeterministicPathDraws($assumptions, $settings->allocation(), $deathAges);

        return (new PathProjector($this->config))->project($household, $settings, $draws);
    }

    /**
     * The SAME central projection with one adverse care spell injected — the "if significant care
     * is needed" stress, shown beside the care-free {@see forecast} rather than averaged into it.
     * Care is otherwise a Monte Carlo risk absent from the central path, which is why a care-free
     * "lasts for life" would be falsely reassuring for the least-numerate reader. The spell is
     * placed on the last-surviving partner at end of life ({@see CareStressScenario}); the projector
     * means-tests and CPI+2%-escalates it exactly as it does a sampled care spell.
     */
    public function forecastWithCareStress(Household $household, AssumptionSet $assumptions, ForecastSettings $settings, CareStressScenario $stress): ForecastResult
    {
        $deathAges = RepresentativeDeathAge::forHousehold($household, $this->lifeTable, $settings->baseYear, $settings->planningHorizon);

        $people = [];
        foreach ($household->persons as $person) {
            $people[] = [
                'id' => $person->id,
                'currentAge' => $settings->baseYear - (int) $person->dob->format('Y'),
                'deathAge' => $deathAges[$person->id] ?? CohortLifeTable::MAX_AGE,
            ];
        }

        $draws = new DeterministicPathDraws(
            $assumptions,
            $settings->allocation(),
            $deathAges,
            $stress->episodesForLastSurvivor($people),
        );

        return (new PathProjector($this->config))->project($household, $settings, $draws);
    }
}
