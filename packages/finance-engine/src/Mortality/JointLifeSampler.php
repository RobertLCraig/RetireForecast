<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Mortality;

use Random\Randomizer;
use RetireForecast\FinanceEngine\Dto\Sex;

/**
 * Samples ages at death for the household from the cohort life table, one Monte
 * Carlo path at a time.
 *
 * Each person is walked year by year: at each age a uniform draw is compared with
 * that age's q(x); the first year the draw falls below q(x) is the age at death.
 * Partners are sampled independently (a "broken-heart" correlation is a documented
 * future option). The household runs until the last survivor dies.
 *
 * The Randomizer is supplied by the caller so a fixed seed gives identical death
 * ages, which is what makes the Monte Carlo reproducible for golden-master tests.
 */
final class JointLifeSampler
{
    public function __construct(private readonly CohortLifeTable $table) {}

    public function sampleDeathAge(Sex $sex, int $currentAge, int $baseYear, Randomizer $rng): int
    {
        foreach ($this->table->cohortCurve($sex, $currentAge, $baseYear) as $age => $qx) {
            if ($rng->nextFloat() < $qx) {
                return $age;
            }
        }

        return CohortLifeTable::MAX_AGE;
    }

    /**
     * Sample a death age for each person. $people is a list of
     * ['id' => string, 'sex' => Sex, 'currentAge' => int]; returns [id => deathAge].
     *
     * @param  list<array{id: string, sex: Sex, currentAge: int}>  $people
     * @return array<string, int>
     */
    public function sampleHousehold(array $people, int $baseYear, Randomizer $rng): array
    {
        $deathAges = [];
        foreach ($people as $person) {
            $deathAges[$person['id']] = $this->sampleDeathAge($person['sex'], $person['currentAge'], $baseYear, $rng);
        }

        return $deathAges;
    }
}
