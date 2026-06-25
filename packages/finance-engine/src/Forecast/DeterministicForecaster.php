<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityMode;
use RetireForecast\FinanceEngine\Dto\Person;
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
        $deathAges = [];
        foreach ($household->persons as $person) {
            $currentAge = $settings->baseYear - (int) $person->dob->format('Y');
            $deathAges[$person->id] = $this->representativeDeathAge($person, $currentAge, $settings->baseYear);
        }

        $draws = new DeterministicPathDraws($assumptions, $settings->allocation(), $deathAges);

        return (new PathProjector($this->config))->project($household, $settings, $draws);
    }

    /**
     * The single representative death age for a person: the cohort median, adjusted
     * by any longevity what-if. A fixed age overrides; an offset shifts the median;
     * a multiplier re-derives the median from scaled mortality. Clamped to
     * [currentAge, MAX_AGE].
     */
    private function representativeDeathAge(Person $person, int $currentAge, int $baseYear): int
    {
        $median = fn (float $qxMultiplier = 1.0): int => $this->lifeTable->medianDeathAge($person->sex, $currentAge, $baseYear, $qxMultiplier);
        $clamp = fn (int $age): int => max($currentAge, min($age, CohortLifeTable::MAX_AGE));

        $adjustment = $person->longevity;
        if ($adjustment === null) {
            return $median();
        }

        return match ($adjustment->mode) {
            LongevityMode::Peer => $median(),
            LongevityMode::FixedAge => $clamp((int) round($adjustment->value)),
            LongevityMode::OffsetYears => $clamp($median() + (int) round($adjustment->value)),
            LongevityMode::MortalityMultiplier => $median(max(0.0, $adjustment->value)),
        };
    }
}
