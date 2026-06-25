<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Mortality;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Mortality\JointLifeSampler;

final class MortalityTest extends TestCase
{
    public function test_cohort_curve_follows_the_period_diagonal(): void
    {
        $table = new CohortLifeTable;

        $curve = $table->cohortCurve(Sex::Male, currentAge: 65, baseYear: 2025);

        // Age 65 in 2025 is the published ONS male q(65,2025).
        $this->assertSame(0.011674, $curve[65]);
        // Age 90 for that same person is reached in 2050: the diagonal cell.
        $this->assertSame($table->periodQx(Sex::Male, 90, 2050), $curve[90]);
        // Female mortality is lower than male at the same age/year.
        $this->assertLessThan(
            $table->periodQx(Sex::Male, 75, 2025),
            $table->periodQx(Sex::Female, 75, 2025),
        );
    }

    public function test_curve_hard_caps_at_the_top_age(): void
    {
        $curve = (new CohortLifeTable)->cohortCurve(Sex::Female, 70, 2025);

        $this->assertArrayHasKey(CohortLifeTable::MAX_AGE, $curve);
        $this->assertSame(1.0, $curve[CohortLifeTable::MAX_AGE]);
    }

    public function test_mortality_multiplier_shifts_the_median_death_age(): void
    {
        $table = new CohortLifeTable;

        $peer = $table->medianDeathAge(Sex::Male, 65, 2026);
        $worse = $table->medianDeathAge(Sex::Male, 65, 2026, 2.0);   // double the mortality rates
        $better = $table->medianDeathAge(Sex::Male, 65, 2026, 0.5);  // half the mortality rates

        $this->assertLessThan($peer, $worse, 'higher mortality shortens the median lifespan');
        $this->assertGreaterThan($peer, $better, 'lower mortality lengthens it');
    }

    public function test_sampling_is_reproducible_under_a_fixed_seed(): void
    {
        $sampler = new JointLifeSampler(new CohortLifeTable);

        $a = $sampler->sampleDeathAge(Sex::Male, 65, 2025, new Randomizer(new Mt19937(42)));
        $b = $sampler->sampleDeathAge(Sex::Male, 65, 2025, new Randomizer(new Mt19937(42)));

        $this->assertSame($a, $b);
        $this->assertGreaterThanOrEqual(65, $a);
        $this->assertLessThanOrEqual(CohortLifeTable::MAX_AGE, $a);
    }

    public function test_mean_sampled_lifespan_matches_ons_life_expectancy(): void
    {
        $sampler = new JointLifeSampler(new CohortLifeTable);
        $rng = new Randomizer(new Mt19937(2024));

        $draws = 4000;
        $maleSum = 0;
        $femaleSum = 0;
        for ($i = 0; $i < $draws; $i++) {
            $maleSum += $sampler->sampleDeathAge(Sex::Male, 65, 2025, $rng);
            $femaleSum += $sampler->sampleDeathAge(Sex::Female, 65, 2025, $rng);
        }

        $maleMean = $maleSum / $draws;
        $femaleMean = $femaleSum / $draws;

        // ONS cohort life expectancy at 65 is ~19.8 (M) / ~22.5 (F): mean death age ~85 / ~87.
        $this->assertGreaterThan(82.0, $maleMean);
        $this->assertLessThan(88.0, $maleMean);
        $this->assertGreaterThan(84.0, $femaleMean);
        $this->assertLessThan(90.0, $femaleMean);
        // Women outlive men on average.
        $this->assertGreaterThan($maleMean, $femaleMean);
    }
}
