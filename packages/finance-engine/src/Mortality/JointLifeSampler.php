<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Mortality;

use Random\Randomizer;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\LongevityMode;
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

    public function sampleDeathAge(Sex $sex, int $currentAge, int $baseYear, Randomizer $rng, float $qxMultiplier = 1.0): int
    {
        foreach ($this->table->cohortCurve($sex, $currentAge, $baseYear, $qxMultiplier) as $age => $qx) {
            if ($rng->nextFloat() < $qx) {
                return $age;
            }
        }

        return CohortLifeTable::MAX_AGE;
    }

    /**
     * Sample a death age for each person. $people is a list of
     * ['id' => string, 'sex' => Sex, 'currentAge' => int, 'longevity' => ?LongevityAdjustment];
     * returns [id => deathAge]. The optional longevity adjustment is a lifespan what-if.
     *
     * @param  list<array{id: string, sex: Sex, currentAge: int, longevity?: ?LongevityAdjustment}>  $people
     * @return array<string, int>
     */
    public function sampleHousehold(array $people, int $baseYear, Randomizer $rng): array
    {
        $deathAges = [];
        foreach ($people as $person) {
            $deathAges[$person['id']] = $this->sampleAdjusted(
                $person['longevity'] ?? null,
                $person['sex'],
                $person['currentAge'],
                $baseYear,
                $rng,
            );
        }

        return $deathAges;
    }

    /**
     * Sample a death age applying an optional per-person longevity what-if. A fixed
     * age overrides the draw entirely; an offset shifts a fresh peer draw; a
     * multiplier scales mortality before sampling. All results are clamped to
     * [currentAge, MAX_AGE].
     */
    private function sampleAdjusted(?LongevityAdjustment $adjustment, Sex $sex, int $currentAge, int $baseYear, Randomizer $rng): int
    {
        if ($adjustment === null) {
            return $this->sampleDeathAge($sex, $currentAge, $baseYear, $rng);
        }

        return match ($adjustment->mode) {
            LongevityMode::Peer => $this->sampleDeathAge($sex, $currentAge, $baseYear, $rng),
            LongevityMode::FixedAge => $this->clamp((int) round($adjustment->value), $currentAge),
            LongevityMode::OffsetYears => $this->clamp(
                $this->sampleDeathAge($sex, $currentAge, $baseYear, $rng) + (int) round($adjustment->value),
                $currentAge,
            ),
            LongevityMode::MortalityMultiplier => $this->sampleDeathAge($sex, $currentAge, $baseYear, $rng, max(0.0, $adjustment->value)),
        };
    }

    private function clamp(int $age, int $currentAge): int
    {
        return max($currentAge, min($age, CohortLifeTable::MAX_AGE));
    }
}
