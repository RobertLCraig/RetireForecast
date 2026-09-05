<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\StatePension\StatePensionUprating;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * How long the triple lock is assumed to last is a CHOICE, and until board card 0038 the engine
 * made it silently: `growState` multiplied the State Pension factor by `max($infl, 0.025)` with
 * no source, no setting and no control. On an inflation path near 2% that floor binds in most
 * years, so the State Pension grew in real terms for forty years and the Pension Credit
 * guarantee, uprated by the same running factor, rose with it.
 *
 * These tests pin each of the three choices to a figure a reader can check on paper, so a control
 * that now exists cannot quietly go back to meaning nothing.
 *
 * Read off the year's NOMINAL twin. Uprating is a nominal rule and the real view divides the
 * deflator straight back out, so under prices-only the real State Pension is flat whatever the
 * rule does — the deflator would hide exactly the bug under test.
 */
final class StatePensionUpratingTest extends TestCase
{
    /** The weekly State Pension every case here is built on. */
    private const WEEKLY = 230;

    /**
     * Flat-real assumptions at a chosen inflation rate: nothing else grows, so the State Pension
     * is the only thing moving and every expected figure is the base pension times a compounding
     * factor.
     */
    private function assumptionsWithInflation(float $percent): AssumptionSet
    {
        return new AssumptionSet(
            name: 'flat', sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::zero(), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::fromPercent($percent), inflationVolatility: Percent::zero(),
            houseGrowth: Percent::zero(), rentInflation: Percent::zero(),
            salaryGrowth: Percent::zero(), investmentIncomeYield: Percent::zero(),
        );
    }

    /**
     * The household's nominal State Pension income by calendar year, for a retired couple both
     * already past State Pension age in the base year (born 1950 = age 76), so the pension is in
     * payment from year 0 and every year of the series is an uprating year.
     *
     * @return array<int, int> calendarYear => nominal state_pension pence
     */
    private function statePensionByYear(
        StatePensionUprating $uprating = StatePensionUprating::TripleLock,
        ?int $tripleLockUntilYear = null,
        float $inflationPercent = 1.0,
    ): array {
        $household = new Household(
            'State Pension uprating',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1950-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(99)),
                new Person('p2', new DateTimeImmutable('1952-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(99)),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(self::WEEKLY, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(self::WEEKLY, 0)),
            ],
        );

        $result = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast(
                $household,
                $this->assumptionsWithInflation($inflationPercent),
                new ForecastSettings(
                    baseYear: 2026,
                    baseTaxYear: '2026-27',
                    statePensionUprating: $uprating,
                    tripleLockUntilYear: $tripleLockUntilYear,
                ),
            );

        $sp = [];
        foreach ($result->years as $y) {
            $nominal = $y->nominal;
            self::assertNotNull($nominal, 'the deterministic forecast carries a nominal twin');
            $sp[$y->calendarYear] = $nominal->incomeBySource['state_pension']->pence;
        }

        return $sp;
    }

    /** The base-year household State Pension (both members) compounded at $rate for $years, in pence. */
    private function compounded(array $sp, float $ratePercent, int $years): float
    {
        return $sp[2026] * ((1.0 + $ratePercent / 100.0) ** $years);
    }

    /**
     * AC#1/#3. The floor is a real figure with real effects: on a 1% inflation path the full
     * triple lock lifts the State Pension by 2.5% a year, which is 1.5% a year of REAL growth
     * compounding for the whole plan. This is the engine's default and what every stored plan
     * has been running on, pinned here so the disclosure has something true to disclose.
     */
    public function test_the_triple_lock_floor_uprates_the_state_pension_above_inflation(): void
    {
        $sp = $this->statePensionByYear(inflationPercent: 1.0);

        $this->assertEqualsWithDelta($this->compounded($sp, 2.5, 20), $sp[2046], 200.0,
            'the full triple lock lifts the pension by the 2.5% floor, not the 1% inflation it earned');
    }

    /**
     * AC#2. Prices only: the floor is gone, so the State Pension rises at the 1% the path
     * actually produced and is FLAT in real terms. This is the adverse branch of the same
     * contested policy, and before card 0038 there was no way to ask for it.
     */
    public function test_inflation_only_uprating_drops_the_floor_altogether(): void
    {
        $sp = $this->statePensionByYear(StatePensionUprating::Inflation, inflationPercent: 1.0);

        $this->assertEqualsWithDelta($this->compounded($sp, 1.0, 20), $sp[2046], 200.0,
            'with no floor the pension rises at inflation and no more');
    }

    /**
     * AC#2. The middle choice: the lock holds to a stated year and prices alone after it. The
     * boundary is inclusive — 2036 is the last year the floor lifts the pension into — so ten
     * years at 2.5% then ten at 1%, which no single-rate rule can produce.
     */
    public function test_the_triple_lock_can_be_ended_in_a_stated_year(): void
    {
        $sp = $this->statePensionByYear(StatePensionUprating::TripleLockUntil, tripleLockUntilYear: 2036, inflationPercent: 1.0);

        $lockedYears = $sp[2026] * (1.025 ** 10);
        $this->assertEqualsWithDelta($lockedYears, $sp[2036], 200.0, 'the floor applies up to and including the stated year');
        $this->assertEqualsWithDelta($lockedYears * (1.01 ** 10), $sp[2046], 200.0, 'and prices alone after it');

        // And it is genuinely between the other two, not a relabelling of either.
        $this->assertGreaterThan($this->statePensionByYear(StatePensionUprating::Inflation, inflationPercent: 1.0)[2046], $sp[2046]);
        $this->assertLessThan($this->statePensionByYear(inflationPercent: 1.0)[2046], $sp[2046]);
    }

    /**
     * AC#2. The floor is a FLOOR, not a rate: where inflation beats 2.5% the pension gets the
     * inflation it earned, so on a 5% path all three choices agree. Without this the "triple
     * lock" case could be a hardcoded 2.5% and every test above would still pass.
     */
    public function test_the_floor_does_not_bite_when_inflation_beats_it(): void
    {
        $locked = $this->statePensionByYear(inflationPercent: 5.0);
        $prices = $this->statePensionByYear(StatePensionUprating::Inflation, inflationPercent: 5.0);

        $this->assertEqualsWithDelta($this->compounded($locked, 5.0, 20), $locked[2046], 200.0);
        $this->assertSame($prices[2046], $locked[2046], 'above the floor the choice makes no difference at all');
    }

    /**
     * AC#2. A TripleLockUntil with no year to lock to has nothing to hold: it falls to prices
     * alone rather than silently reverting to a lock that never ends. The optimistic reading of a
     * half-filled form is exactly the failure this card exists to remove.
     */
    public function test_ending_the_lock_in_no_stated_year_leaves_prices_alone(): void
    {
        $this->assertSame(
            $this->statePensionByYear(StatePensionUprating::Inflation, inflationPercent: 1.0)[2046],
            $this->statePensionByYear(StatePensionUprating::TripleLockUntil, inflationPercent: 1.0)[2046],
        );
    }
}
