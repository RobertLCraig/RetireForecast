<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Care;

use Random\Randomizer;

/**
 * Samples late-life care spells for a household, one Monte Carlo path at a time, from
 * {@see CareAssumptions}. Per person: a Bernoulli draw on the probability of ever needing care;
 * if it hits, a duration (exponential with the assumed mean, rounded to whole years, floored at
 * 1 and capped) and a residential-vs-nursing draw fix the annual cost. The spell is placed at the
 * end of life (the final $duration years up to the sampled age at death), because care need
 * concentrates near death — so it depends on the death ages already sampled for the path.
 *
 * The Randomizer is supplied by the caller, so a fixed seed reproduces the same spells (the
 * golden-master property the rest of the Monte Carlo relies on).
 */
final class CareCostSampler
{
    public function __construct(private readonly CareAssumptions $assumptions) {}

    /**
     * @param  list<array{id: string, currentAge: int, deathAge: int}>  $people
     * @return array<string, CareEpisode> keyed by person id — only those who incur care
     */
    public function sampleHousehold(array $people, Randomizer $rng): array
    {
        $episodes = [];
        foreach ($people as $person) {
            // One Bernoulli per person (always drawn, so the RNG stream is stable), then the
            // duration + type draws only when care actually occurs.
            if ($rng->nextFloat() >= $this->assumptions->probabilityOfCare) {
                continue;
            }

            $years = (int) round(-$this->assumptions->meanDurationYears * log(max(1e-9, 1.0 - $rng->nextFloat())));
            $years = max(1, min($this->assumptions->maxDurationYears, $years));

            $annual = $rng->nextFloat() < $this->assumptions->probabilityNursing
                ? $this->assumptions->nursingAnnual()
                : $this->assumptions->residentialAnnual();

            $toAge = $person['deathAge'];
            $fromAge = max($person['currentAge'], $toAge - $years + 1);
            $episodes[$person['id']] = new CareEpisode($fromAge, $toAge, $annual);
        }

        return $episodes;
    }
}
