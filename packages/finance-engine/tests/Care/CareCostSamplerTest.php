<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Care;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Care\CareAssumptions;
use RetireForecast\FinanceEngine\Care\CareCostSampler;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Money\Money;

final class CareCostSamplerTest extends TestCase
{
    /** A single care probability applied to both sexes, for the sex-agnostic behaviour tests. */
    private function assumptions(float $probability, float $probabilityNursing = 0.0): CareAssumptions
    {
        return new CareAssumptions(
            probabilityOfCareMale: $probability,
            probabilityOfCareFemale: $probability,
            meanDurationYears: 2.5,
            maxDurationYears: 8,
            probabilityNursing: $probabilityNursing,
            residentialWeekly: Money::fromPounds(1_300),
            nursingWeekly: Money::fromPounds(1_600),
        );
    }

    /** @return list<array{id: string, sex: Sex, currentAge: int, deathAge: int}> */
    private function people(): array
    {
        return [
            ['id' => 'p1', 'sex' => Sex::Male, 'currentAge' => 68, 'deathAge' => 88],
            ['id' => 'p2', 'sex' => Sex::Female, 'currentAge' => 66, 'deathAge' => 90],
        ];
    }

    public function test_certain_care_gives_everyone_an_end_of_life_spell(): void
    {
        $episodes = (new CareCostSampler($this->assumptions(1.0)))
            ->sampleHousehold($this->people(), new Randomizer(new Mt19937(42)));

        $this->assertCount(2, $episodes);
        foreach ($episodes as $id => $episode) {
            // The spell ends at the age at death and is a whole number of years, 1..8 long.
            $deathAge = $id === 'p1' ? 88 : 90;
            $this->assertSame($deathAge, $episode->toAge);
            $length = $episode->toAge - $episode->fromAge + 1;
            $this->assertGreaterThanOrEqual(1, $length);
            $this->assertLessThanOrEqual(8, $length);
            // Residential (nursing probability 0) at £1,300/wk × 52 = £67,600 a year.
            $this->assertSame(Money::fromPounds(67_600)->pence, $episode->annualCost->pence);
        }
    }

    public function test_zero_probability_gives_no_care(): void
    {
        $episodes = (new CareCostSampler($this->assumptions(0.0)))
            ->sampleHousehold($this->people(), new Randomizer(new Mt19937(1)));

        $this->assertSame([], $episodes);
    }

    public function test_nursing_certainty_charges_the_nursing_rate(): void
    {
        $episodes = (new CareCostSampler($this->assumptions(1.0, probabilityNursing: 1.0)))
            ->sampleHousehold([['id' => 'p1', 'sex' => Sex::Male, 'currentAge' => 68, 'deathAge' => 88]], new Randomizer(new Mt19937(7)));

        // Nursing at £1,600/wk LESS the £254.06 a week the NHS pays the home direct (board card
        // 0059), so £1,345.94 × 52 = £69,988.88 a year.
        $this->assertSame(
            (1_600_00 - CareAssumptions::FUNDED_NURSING_CARE_WEEKLY_PENCE) * CareAssumptions::WEEKS_PER_YEAR,
            $episodes['p1']->annualCost->pence,
        );
    }

    public function test_annual_cost_only_applies_within_the_spell(): void
    {
        $episodes = (new CareCostSampler($this->assumptions(1.0)))
            ->sampleHousehold([['id' => 'p1', 'sex' => Sex::Male, 'currentAge' => 68, 'deathAge' => 88]], new Randomizer(new Mt19937(3)));

        $episode = $episodes['p1'];
        $this->assertSame(0, $episode->annualCostAt($episode->fromAge - 1), 'before the spell: no cost');
        $this->assertGreaterThan(0, $episode->annualCostAt($episode->toAge), 'at death age: in care');
        $this->assertSame(0, $episode->annualCostAt($episode->toAge + 1), 'after death: no cost');
    }

    public function test_the_default_probability_is_higher_for_women_than_men(): void
    {
        $default = CareAssumptions::default();

        $this->assertGreaterThan(
            $default->probabilityOfCare(Sex::Male),
            $default->probabilityOfCare(Sex::Female),
            'women carry a higher lifetime care probability than men',
        );
        // The population mean stays anchored to the Dilnot/PSSRU ~1 in 4 at an even sex split.
        $this->assertEqualsWithDelta(
            0.25,
            ($default->probabilityOfCare(Sex::Male) + $default->probabilityOfCare(Sex::Female)) / 2,
            1e-9,
        );
    }

    public function test_the_sex_split_reaches_care_incidence(): void
    {
        // Same seed, same ages, differing only by sex: under the default assumptions a large
        // female cohort must incur care more often than an identical male cohort — proving the
        // sex-differentiated probability actually reaches the sampled outcome (not dropped).
        $sampler = new CareCostSampler(CareAssumptions::default());
        $men = 0;
        $women = 0;
        for ($i = 0; $i < 2_000; $i++) {
            // Two fresh randomizers on the same seed give both cohorts the identical Bernoulli
            // draw, so the difference is purely the sex-differentiated threshold (Randomizer is
            // uncloneable, so we re-seed rather than clone).
            $men += $sampler->sampleHousehold([['id' => 'm', 'sex' => Sex::Male, 'currentAge' => 70, 'deathAge' => 90]], new Randomizer(new Mt19937($i))) === [] ? 0 : 1;
            $women += $sampler->sampleHousehold([['id' => 'w', 'sex' => Sex::Female, 'currentAge' => 70, 'deathAge' => 90]], new Randomizer(new Mt19937($i))) === [] ? 0 : 1;
        }

        $this->assertGreaterThan($men, $women, 'women incur care more often than men at the same seed');
        // Sanity: each cohort's incidence tracks its assumed probability (0.20 / 0.30) within noise.
        $this->assertEqualsWithDelta(0.20, $men / 2_000, 0.04);
        $this->assertEqualsWithDelta(0.30, $women / 2_000, 0.04);
    }

    public function test_it_is_reproducible_for_a_fixed_seed(): void
    {
        $a = (new CareCostSampler($this->assumptions(0.5)))->sampleHousehold($this->people(), new Randomizer(new Mt19937(99)));
        $b = (new CareCostSampler($this->assumptions(0.5)))->sampleHousehold($this->people(), new Randomizer(new Mt19937(99)));

        $this->assertSame(array_keys($a), array_keys($b));
        foreach ($a as $id => $episode) {
            $this->assertSame($episode->fromAge, $b[$id]->fromAge);
            $this->assertSame($episode->annualCost->pence, $b[$id]->annualCost->pence);
        }
    }
}
