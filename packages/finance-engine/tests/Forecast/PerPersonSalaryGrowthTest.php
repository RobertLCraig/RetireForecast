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
 * Person::salaryGrowth is a per-person real (above-inflation) salary-growth override. Before the
 * fix it was collected, validated and mapped into the Person DTO but read by no engine code — the
 * projector escalated one household-wide salary factor off the assumption set, so a person's own
 * entered growth silently vanished (a completeness bug). These tests pin that the override reaches
 * the forecast at the exact rate, that each person escalates independently, and that a null override
 * falls back to the assumption-set figure.
 */
final class PerPersonSalaryGrowthTest extends TestCase
{
    /** Flat assumptions (no inflation, no economy-wide salary growth) so an override is the only escalation. */
    private function flatAssumptions(?Percent $salaryGrowth = null): AssumptionSet
    {
        $salaryGrowth ??= Percent::zero();

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
            salaryGrowth: $salaryGrowth, investmentIncomeYield: Percent::zero(),
        );
    }

    /**
     * The household's salary income by calendar year.
     *
     * @param  list<Person>  $people
     * @return array<int, int> calendarYear => salary pence
     */
    private function salaryByYear(array $people, AssumptionSet $assumptions): array
    {
        $pensions = [];
        foreach ($people as $person) {
            $pensions[] = new StatePensionEntitlement($person->id, weeklyForecast: Money::of(150, 0));
        }
        $household = new Household(
            'Salary growth',
            RegionProfile::EnglandWalesNi,
            $people,
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
            pensions: $pensions,
        );

        $result = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $assumptions, new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        $salary = [];
        foreach ($result->years as $y) {
            $salary[$y->calendarYear] = $y->incomeBySource['salary']->pence;
        }

        return $salary;
    }

    private function worker(string $id, Money $salary, ?Percent $growth): Person
    {
        // Born 1975 (age 51 in 2026), retires at 67 — a full salary through the years asserted below.
        return new Person($id, new DateTimeImmutable('1975-01-01'), Sex::Male, EmploymentStatus::Employed,
            grossSalary: $salary, salaryGrowth: $growth, plannedRetirementAge: 67);
    }

    public function test_a_per_person_override_escalates_that_persons_salary_at_its_own_rate(): void
    {
        $salary = $this->salaryByYear(
            [$this->worker('p1', Money::fromPounds(50_000), Percent::fromPercent(5))],
            $this->flatAssumptions(),
        );

        // 5% real, zero inflation: £50,000 → £52,500 → £55,125, compounding on the entered growth.
        $this->assertSame(Money::fromPounds(50_000)->pence, $salary[2026]);
        $this->assertSame(Money::fromPounds(52_500)->pence, $salary[2027]);
        $this->assertSame(Money::fromPounds(55_125)->pence, $salary[2028]);
    }

    public function test_each_person_escalates_independently_not_off_one_household_factor(): void
    {
        // The core of the bug: two workers with different growth must diverge. P1 grows 5%, P2 is flat.
        $salary = $this->salaryByYear([
            $this->worker('p1', Money::fromPounds(50_000), Percent::fromPercent(5)),
            $this->worker('p2', Money::fromPounds(40_000), Percent::zero()),
        ], $this->flatAssumptions());

        // Year 1: 50,000×1.05 + 40,000×1.00 = 52,500 + 40,000 = 92,500. P2 did not inherit P1's growth.
        $this->assertSame(Money::fromPounds(90_000)->pence, $salary[2026]);
        $this->assertSame(Money::fromPounds(92_500)->pence, $salary[2027]);
    }

    public function test_a_null_override_falls_back_to_the_assumption_set_salary_growth(): void
    {
        // No per-person growth, but the assumption set grows salaries 3% real → the person follows it.
        $salary = $this->salaryByYear(
            [$this->worker('p1', Money::fromPounds(50_000), null)],
            $this->flatAssumptions(Percent::fromPercent(3)),
        );

        $this->assertSame(Money::fromPounds(50_000)->pence, $salary[2026]);
        $this->assertSame(Money::fromPounds(51_500)->pence, $salary[2027]); // 50,000 × 1.03
    }
}
