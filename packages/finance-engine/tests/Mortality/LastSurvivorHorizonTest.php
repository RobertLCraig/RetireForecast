<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Mortality;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\LongevityMode;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\RepresentativeDeathAge;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;

/**
 * Board card 0061. The deterministic planning horizon is a HOUSEHOLD question — how long
 * before the LAST of them has gone — and it was being answered with each person's own
 * independent median. For a couple the last survivor lives materially longer than either
 * of them does on their own, so the money was being asked to last for a coin-flip lifespan.
 */
final class LastSurvivorHorizonTest extends TestCase
{
    private const BASE_YEAR = 2026;

    public function test_the_horizon_is_the_last_survivor_not_either_persons_own_median(): void
    {
        $table = new CohortLifeTable;
        $household = $this->couple();

        // At the 50th percentile, so the only thing being tested is the last-survivor basis
        // and not the cautious default percentile.
        $ages = RepresentativeDeathAge::forHousehold($household, $table, self::BASE_YEAR, PlanningHorizon::P50);

        $ownMedianYear = 0;
        foreach ($household->persons as $person) {
            $birthYear = (int) $person->dob->format('Y');
            $ownMedianYear = max($ownMedianYear, $birthYear + $table->medianDeathAge(
                $person->sex, self::BASE_YEAR - $birthYear, self::BASE_YEAR,
            ));
        }

        $this->assertGreaterThan(
            $ownMedianYear,
            $this->horizonYear($household, $ages),
            'the last of a couple outlives the later of their two individual medians',
        );
    }

    public function test_the_first_death_stays_at_that_persons_own_median(): void
    {
        $table = new CohortLifeTable;
        $household = $this->couple();

        $ages = RepresentativeDeathAge::forHousehold($household, $table, self::BASE_YEAR, PlanningHorizon::P50);

        // The man dies first here, and nothing about the household horizon moves him.
        $p2 = $household->persons[1];
        $this->assertSame(
            $table->medianDeathAge($p2->sex, self::BASE_YEAR - 1958, self::BASE_YEAR),
            $ages['p2'],
            'only the last survivor is carried out to the horizon',
        );
    }

    public function test_a_lone_person_at_the_median_is_still_their_own_median(): void
    {
        $table = new CohortLifeTable;
        $household = $this->couple()->withPersons([$this->couple()->persons[0]]);

        $ages = RepresentativeDeathAge::forHousehold($household, $table, self::BASE_YEAR, PlanningHorizon::P50);

        $this->assertSame(
            $table->medianDeathAge(Sex::Female, self::BASE_YEAR - 1958, self::BASE_YEAR),
            $ages['p1'],
            'a household of one has no second life to outlive, so nothing moves',
        );
    }

    public function test_the_named_percentiles_push_the_horizon_further_out(): void
    {
        $table = new CohortLifeTable;
        $household = $this->couple();

        $p50 = $this->horizonYear($household, RepresentativeDeathAge::forHousehold($household, $table, self::BASE_YEAR, PlanningHorizon::P50));
        $p75 = $this->horizonYear($household, RepresentativeDeathAge::forHousehold($household, $table, self::BASE_YEAR, PlanningHorizon::P75));
        $p90 = $this->horizonYear($household, RepresentativeDeathAge::forHousehold($household, $table, self::BASE_YEAR, PlanningHorizon::P90));

        $this->assertGreaterThan($p50, $p75, 'the 75th plans for longer than even odds');
        $this->assertGreaterThan($p75, $p90, 'the 90th plans for longer still');
        $this->assertSame(PlanningHorizon::P75, PlanningHorizon::DEFAULT, 'the cautious end is the default');
        $this->assertSame($p75, $this->horizonYear($household, RepresentativeDeathAge::forHousehold($household, $table, self::BASE_YEAR)));
        $this->assertCount(3, PlanningHorizon::cases(), '50th, 75th and 90th are the named settings');
    }

    public function test_a_stated_age_at_death_is_never_carried_out_to_the_horizon(): void
    {
        $table = new CohortLifeTable;
        $stated = $this->couple();
        $stated = $stated->withPersons([
            $stated->persons[0]->withLongevity(new LongevityAdjustment(LongevityMode::FixedAge, 92.0)),
            $stated->persons[1],
        ]);

        $ages = RepresentativeDeathAge::forHousehold($stated, $table, self::BASE_YEAR, PlanningHorizon::P90);

        $this->assertSame(92, $ages['p1'], 'a lifespan the reader stated is not quietly extended');
    }

    /** @param array<string, int> $ages */
    private function horizonYear(Household $household, array $ages): int
    {
        $year = 0;
        foreach ($household->persons as $person) {
            $year = max($year, (int) $person->dob->format('Y') + $ages[$person->id]);
        }

        return $year;
    }

    private function couple(): Household
    {
        return new Household(
            'Couple', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(28_000), Money::zero(), Percent::fromPercent(70)),
            [],
        );
    }
}
