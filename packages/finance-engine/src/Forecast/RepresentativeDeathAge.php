<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityMode;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;

/**
 * The single representative age at death for a person or household, from the cohort life
 * table: the median, adjusted by any longevity what-if (a fixed age overrides; an offset
 * shifts the median; a multiplier re-derives it from scaled mortality). Clamped to
 * [currentAge, MAX_AGE]. One home for this rule so the deterministic forecast and the
 * historical backtest use exactly the same representative lifespan.
 */
final class RepresentativeDeathAge
{
    /**
     * @return array<string, int> personId => representative age at death
     */
    public static function forHousehold(Household $household, CohortLifeTable $lifeTable, int $baseYear): array
    {
        $deathAges = [];
        foreach ($household->persons as $person) {
            $currentAge = $baseYear - (int) $person->dob->format('Y');
            $deathAges[$person->id] = self::forPerson($person, $lifeTable, $currentAge, $baseYear);
        }

        return $deathAges;
    }

    public static function forPerson(Person $person, CohortLifeTable $lifeTable, int $currentAge, int $baseYear): int
    {
        $median = fn (float $qxMultiplier = 1.0): int => $lifeTable->medianDeathAge($person->sex, $currentAge, $baseYear, $qxMultiplier);
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
