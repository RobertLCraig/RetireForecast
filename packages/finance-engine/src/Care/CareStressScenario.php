<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Care;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * A single, deliberately adverse late-life care spell for the DETERMINISTIC "if significant care
 * is needed" stress scenario. Unlike {@see CareAssumptions} (which parameterises the Monte Carlo
 * care *distribution* — probabilities, random durations, sex split), this is one concrete "what if
 * it happens" case shown ALONGSIDE the care-free central estimate, so the plain-English
 * affordability verdict is never "lasts for life" beside a silently care-free path.
 *
 * It is placed on the LAST-SURVIVING partner (a couple's more adverse means-test position — alone,
 * so the home is assessable, with no partner income to share the cost, and the person more likely
 * to need care having outlived their partner) and runs the final $durationYears up to their death
 * age. The projector then means-tests each year and escalates the fee at CPI + the care real-growth
 * rate (A1), exactly as it does a Monte Carlo care spell.
 *
 * Adverse defaults (sourced, user-editable — see docs/ASSUMPTIONS.md): a $1,800/wk nursing fee
 * (top-decile / dementia-nursing, LaingBuisson 35th ed.) over 4 years (the upper tail; PSSRU mean
 * is ~2.5 yr). One spell rather than both partners' — a single significant spell still discriminates
 * a strong plan from a weak one, where a both-partners worst case would sink every plan and inform
 * nothing.
 */
final class CareStressScenario
{
    public function __construct(
        public readonly Money $nursingWeekly,
        public readonly int $durationYears,
    ) {}

    /** The shipped adverse default: 4 years of nursing care at £1,800/wk (self-funder). */
    public static function adverseDefault(): self
    {
        return new self(Money::fromPounds(1_800), 4);
    }

    /** The gross self-funder fee for one care year, in today's money. */
    public function annualCost(): Money
    {
        return Money::fromPence($this->nursingWeekly->pence * CareAssumptions::WEEKS_PER_YEAR);
    }

    /**
     * One care spell on the last-surviving person: the final $durationYears before their death age
     * (floored at their current age). Keyed by person id — empty if there are no people.
     *
     * @param  list<array{id: string, currentAge: int, deathAge: int}>  $people
     * @return array<string, CareEpisode>
     */
    public function episodesForLastSurvivor(array $people): array
    {
        if ($people === []) {
            return [];
        }

        $survivor = $people[0];
        foreach ($people as $person) {
            if ($person['deathAge'] > $survivor['deathAge']) {
                $survivor = $person;
            }
        }

        $toAge = $survivor['deathAge'];
        $fromAge = max($survivor['currentAge'], $toAge - $this->durationYears + 1);

        return [$survivor['id'] => new CareEpisode($fromAge, $toAge, $this->annualCost())];
    }
}
