<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\StatePension;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\StatePension\StatePensionAge;
use RetireForecast\FinanceEngine\StatePension\StatePensionCalculator;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class StatePensionTest extends TestCase
{
    private function calculator(string $taxYear = '2025-26'): StatePensionCalculator
    {
        return new StatePensionCalculator(TaxYearRegistry::for($taxYear));
    }

    public function test_spa_is_66_for_those_born_before_the_transition(): void
    {
        $spa = StatePensionAge::for(new DateTimeImmutable('1955-01-01'));

        $this->assertSame(66, $spa->years);
        $this->assertSame(0, $spa->months);
        $this->assertSame('2021-01-01', $spa->dateReached->format('Y-m-d'));
    }

    public function test_spa_phases_up_one_month_at_a_time_across_the_1960_cohort(): void
    {
        // First transition month: born 6 Apr–5 May 1960 → 66 years 1 month.
        $first = StatePensionAge::for(new DateTimeImmutable('1960-04-06'));
        $this->assertSame(66, $first->years);
        $this->assertSame(1, $first->months);

        // Mid-cohort: born 15 Sep 1960 → 66 years 6 months.
        $mid = StatePensionAge::for(new DateTimeImmutable('1960-09-15'));
        $this->assertSame(66, $mid->years);
        $this->assertSame(6, $mid->months);

        // Last transition month: born 6 Feb–5 Mar 1961 → 66 years 11 months.
        $last = StatePensionAge::for(new DateTimeImmutable('1961-03-05'));
        $this->assertSame(66, $last->years);
        $this->assertSame(11, $last->months);
    }

    public function test_spa_is_67_from_march_1961(): void
    {
        $spa = StatePensionAge::for(new DateTimeImmutable('1961-03-06'));

        $this->assertSame(67, $spa->years);
        $this->assertSame(0, $spa->months);
        $this->assertSame('2028-03-06', $spa->dateReached->format('Y-m-d'));
    }

    public function test_spa_phases_towards_68_across_the_1977_cohort(): void
    {
        $this->assertSame(67, StatePensionAge::for(new DateTimeImmutable('1977-04-06'))->years);
        $this->assertSame(1, StatePensionAge::for(new DateTimeImmutable('1977-04-06'))->months);
        $this->assertSame(68, StatePensionAge::for(new DateTimeImmutable('1990-01-01'))->years);
    }

    public function test_full_new_state_pension_amount(): void
    {
        $result = $this->calculator()->fromWeeklyForecast(Money::of(230, 25));

        $this->assertSame(23_025, $result->weekly->pence);
        $this->assertSame(1_197_300, $result->annual->pence); // £230.25 x 52
    }

    public function test_triple_lock_uprating_in_2026_27(): void
    {
        // Pro-rata from a full 35 qualifying years uses the year's config rate.
        $result = $this->calculator('2026-27')->fromQualifyingYears(35);

        $this->assertSame(24_130, $result->weekly->pence); // £241.30
    }

    public function test_pro_rata_from_qualifying_years(): void
    {
        // 20 of 35 years: £230.25 x 20 / 35 = £131.57.
        $result = $this->calculator()->fromQualifyingYears(20);

        $this->assertSame(13_157, $result->weekly->pence);
    }

    public function test_nothing_below_the_minimum_qualifying_years(): void
    {
        $result = $this->calculator()->fromQualifyingYears(5);

        $this->assertSame(0, $result->weekly->pence);
    }

    public function test_deferral_adds_one_percent_for_every_nine_weeks(): void
    {
        // 52 weeks deferred ≈ 5.78% uplift on £230.25 ≈ £13.30 a week.
        $result = $this->calculator()->fromWeeklyForecast(Money::of(230, 25), deferralWeeks: 52);

        $this->assertSame(1_330, $result->deferralUpliftWeekly->pence);
        $this->assertSame(24_355, $result->weekly->pence);
    }
}
