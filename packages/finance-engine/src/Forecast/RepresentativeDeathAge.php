<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityMode;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;

/**
 * The single representative age at death for a person or household, from the cohort life
 * table: the median, adjusted by any longevity what-if (a fixed age overrides; an offset
 * shifts the median; a multiplier re-derives it from scaled mortality). Clamped to
 * [currentAge, MAX_AGE]. One home for this rule so the deterministic forecast and the
 * historical backtest use exactly the same representative lifespan.
 *
 * WHEN THE MONEY HAS TO LAST TO is a household question, not a personal one (board card
 * 0061). Each person's own median answers "how long do I live"; the plan has to fund the
 * LAST of them, whose age at death is materially later and whose tail is far fatter. So
 * the person modelled to survive the others is carried out to the household's last-survivor
 * horizon at the chosen {@see PlanningHorizon} percentile, while the first death stays at
 * that person's own median (there is no reason to move it, and moving it later would be
 * the flattering direction — a household keeps two lots of income for longer).
 *
 * A reader who STATED a lifespan (a fixed age, or an offset from the peer average) is never
 * carried out past what they said: their answer is a certainty in this model, and it enters
 * the joint survival arithmetic as one.
 */
final class RepresentativeDeathAge
{
    /**
     * @param  PlanningHorizon  $horizon  which percentile of the last survivor's age at death
     *                                    the plan runs to
     * @return array<string, int> personId => representative age at death
     */
    public static function forHousehold(Household $household, CohortLifeTable $lifeTable, int $baseYear, PlanningHorizon $horizon = PlanningHorizon::DEFAULT): array
    {
        $deathAges = [];
        $deathYears = [];
        foreach ($household->persons as $person) {
            $birthYear = (int) $person->dob->format('Y');
            $deathAges[$person->id] = self::forPerson($person, $lifeTable, $baseYear - $birthYear, $baseYear);
            $deathYears[$person->id] = $birthYear + $deathAges[$person->id];
        }

        if ($deathAges === []) {
            return $deathAges;
        }

        $horizonYear = self::lastSurvivorYear($household, $lifeTable, $baseYear, $horizon, $deathYears);
        $lastToDie = max($deathYears);

        foreach ($household->persons as $person) {
            if ($deathYears[$person->id] !== $lastToDie || ! self::isTableDerived($person)) {
                continue;
            }
            $birthYear = (int) $person->dob->format('Y');
            $deathAges[$person->id] = min(
                CohortLifeTable::MAX_AGE,
                max($deathAges[$person->id], $horizonYear - $birthYear),
            );
        }

        return $deathAges;
    }

    /**
     * The calendar year in which the household's last survivor is modelled to die: the first
     * year the probability that ANYBODY is still alive falls below (1 - the horizon percentile).
     * Lives are treated as independent, which is the same assumption the Monte Carlo's joint
     * sampler makes.
     *
     * @param  array<string, int>  $deathYears
     */
    private static function lastSurvivorYear(Household $household, CohortLifeTable $lifeTable, int $baseYear, PlanningHorizon $horizon, array $deathYears): int
    {
        $threshold = 1.0 - $horizon->percentile();
        $curves = [];
        $endYear = $baseYear;
        foreach ($household->persons as $person) {
            $birthYear = (int) $person->dob->format('Y');
            $curves[] = self::survivalByYear($person, $lifeTable, $baseYear, $birthYear, $deathYears[$person->id]);
            $endYear = max($endYear, $birthYear + CohortLifeTable::MAX_AGE);
        }

        for ($year = $baseYear; $year <= $endYear; $year++) {
            $allGone = 1.0;
            foreach ($curves as $curve) {
                $allGone *= 1.0 - ($curve[$year] ?? 0.0);
            }
            if (1.0 - $allGone < $threshold) {
                return $year;
            }
        }

        return $endYear;
    }

    /**
     * One person's probability of being alive at the end of each calendar year. A stated
     * lifespan is a step: alive up to the year before the age they were given, then not.
     *
     * @return array<int, float> calendar year => probability alive
     */
    private static function survivalByYear(Person $person, CohortLifeTable $lifeTable, int $baseYear, int $birthYear, int $deathAge): array
    {
        $currentAge = $baseYear - $birthYear;
        $byYear = [];

        if (! self::isTableDerived($person)) {
            for ($age = $currentAge; $age <= CohortLifeTable::MAX_AGE; $age++) {
                $byYear[$birthYear + $age] = $age < $deathAge ? 1.0 : 0.0;
            }

            return $byYear;
        }

        $multiplier = $person->longevity?->mode === LongevityMode::MortalityMultiplier
            ? max(0.0, $person->longevity->value)
            : 1.0;

        foreach ($lifeTable->survivalCurve($person->sex, $currentAge, $baseYear, $multiplier) as $age => $survival) {
            $byYear[$birthYear + $age] = $survival;
        }

        return $byYear;
    }

    /** Is this person's death age read off the life table, rather than an age they stated? */
    private static function isTableDerived(Person $person): bool
    {
        return $person->longevity === null
            || $person->longevity->mode === LongevityMode::Peer
            || $person->longevity->mode === LongevityMode::MortalityMultiplier;
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
