<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DbPension;
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
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * When a Defined Benefit member dies, a scheme with a survivor's fraction keeps paying that
 * fraction of the pension to the surviving partner for life. Before the fix
 * DbPension::spousePensionFraction was collected but never consumed, so the guaranteed DB
 * income silently dropped to £0 on the member's death — a completeness bug (a real input that
 * never reached the result), the joint-life analogue of the annuity survivor income the engine
 * already modelled. These tests pin that the fraction demonstrably reaches the forecast, and
 * guard the boundary (no fraction ⇒ nothing paid, as before).
 */
final class SurvivorDbPensionTest extends TestCase
{
    /** Flat assumptions (no inflation, no growth) so nominal == real and the DB figure stays a clean round number. */
    private function flatAssumptions(): AssumptionSet
    {
        return new AssumptionSet(
            name: 'flat', sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::zero(), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::zero(), inflationVolatility: Percent::zero(),
            houseGrowth: Percent::zero(), rentInflation: Percent::zero(),
            salaryGrowth: Percent::zero(), investmentIncomeYield: Percent::zero(),
        );
    }

    /**
     * The household's defined-benefit income by calendar year, for a couple where P1 holds a
     * £20k DB pension in payment and dies ~2029, and P2 survives well past it.
     *
     * @return array<int, int> calendarYear => defined_benefit pence
     */
    private function dbIncomeByYear(?Percent $survivorFraction): array
    {
        $household = new Household(
            'Survivor DB',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1950-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(78)),   // dies ~2029
                new Person('p2', new DateTimeImmutable('1952-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(95)), // survives to ~2047
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(150, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(150, 0)),
                new DbPension('p1', Money::fromPounds(20_000), normalRetirementAge: 65, spousePensionFraction: $survivorFraction),
            ],
        );

        $result = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flatAssumptions(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        $db = [];
        foreach ($result->years as $y) {
            $db[$y->calendarYear] = $y->incomeBySource['defined_benefit']->pence;
        }

        return $db;
    }

    public function test_a_survivor_fraction_keeps_paying_the_db_pension_after_the_member_dies(): void
    {
        $db = $this->dbIncomeByYear(Percent::fromPercent(50));

        // While the member (P1) lives, the household receives the full DB pension.
        $this->assertSame(Money::fromPounds(20_000)->pence, $db[2026], 'the member draws the full DB pension while alive');

        // After P1 dies (~2029) the surviving partner keeps HALF the pension for life — not £0.
        $this->assertSame(Money::fromPounds(10_000)->pence, $db[2035], 'the survivor keeps the 50% fraction after the member dies');
        $this->assertSame(Money::fromPounds(10_000)->pence, $db[2040]);
    }

    public function test_no_survivor_fraction_stops_the_db_pension_on_death(): void
    {
        // A scheme with no survivor's pension pays nothing after the member dies (unchanged behaviour),
        // proving it is the fraction — not the mere presence of a dead member's pension — doing the work.
        $db = $this->dbIncomeByYear(null);

        $this->assertSame(Money::fromPounds(20_000)->pence, $db[2026]);
        $this->assertSame(0, $db[2035], 'a DB pension with no survivor fraction stops on the member\'s death');
    }
}
