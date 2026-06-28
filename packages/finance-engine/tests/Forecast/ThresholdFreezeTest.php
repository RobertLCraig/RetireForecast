<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * UK income-tax thresholds are frozen until ForecastSettings::$freezeEndYear (April 2031),
 * then index with inflation again. During the freeze, frozen nominal thresholds against
 * inflating incomes create real fiscal drag; after it, the drag eases. This guards that the
 * projector actually models the un-freezing (the threshold inflation factor), not just the
 * freeze.
 */
final class ThresholdFreezeTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    /** A retired couple, each with a £30k DB pension in payment well above the personal allowance. */
    private function couple(): Household
    {
        return new Household(
            'Drag',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(40_000), Money::zero(), Percent::fromPercent(70)),
            [
                new DbPension('p1', Money::fromPounds(30_000), 65),
                new DbPension('p2', Money::fromPounds(30_000), 65),
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
        );
    }

    public function test_income_tax_thresholds_unfreeze_after_the_freeze_end_year(): void
    {
        $household = $this->couple();

        // Identical except for when the threshold freeze ends: 2031 (the real policy) vs a
        // far-future year that keeps thresholds frozen for the whole projection.
        $unfreezes = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', freezeEndYear: 2031);
        $frozenForever = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', freezeEndYear: 2100);

        $a = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $unfreezes);
        $b = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $frozenForever);

        $taxA = [];
        $taxB = [];
        foreach ($a->years as $year) {
            $taxA[$year->calendarYear] = $year->totalTax->pence;
        }
        foreach ($b->years as $year) {
            $taxB[$year->calendarYear] = $year->totalTax->pence;
        }

        // During the freeze the two are identical (thresholds frozen in both).
        $this->assertSame($taxB[2026], $taxA[2026], 'base-year tax is identical — the freeze is active in both');
        $this->assertSame($taxB[2030], $taxA[2030], 'within the freeze window the two are identical');

        // After the freeze ends, the indexed thresholds reduce the tax due vs frozen-forever.
        $this->assertLessThan($taxB[2035], $taxA[2035], 'after 2031 the indexed thresholds reduce the tax due');

        // And the cumulative nominal tax over the run is lower when the freeze ends.
        $this->assertLessThan(array_sum($taxB), array_sum($taxA), 'un-freezing thresholds reduces total fiscal drag');
    }
}
