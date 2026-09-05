<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Tax\NationalInsuranceCalculator;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The retirement / State Pension age year is a TRANSITION year, and every income that starts in it
 * starts part way through it. Salary was already prorated (a person who leaves in July is not paid
 * for December), but the income replacing it was not: the State Pension paid a full year from the
 * claim year, a DB pension paid a full year from normal retirement age, and National Insurance
 * switched off for the whole calendar year in which State Pension age was reached. All three
 * favoured the household, in the one year an affordability cliff would show. Board card 0036.
 *
 * The convention throughout is {@see PathProjector::workFraction}'s: a birthday or entitlement date
 * in month n divides the year at the end of that month, so salary gets n/12 and the income that
 * replaces it gets the complement (12 − n)/12.
 */
final class TransitionYearProrationTest extends TestCase
{
    /** Flat assumptions (no inflation, no growth); the State Pension still rises by the triple-lock proxy. */
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

    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /** Every born-1965 person here reaches State Pension age at 67, in 2032, in their birth month. */
    private const BIRTH_MONTHS = [2 => '1965-02-15', 5 => '1965-05-15', 8 => '1965-08-15', 11 => '1965-11-15'];

    /**
     * A lone retired person's State Pension by calendar year, with a full new State Pension and a
     * fixed death age so the horizon does not move with the date of birth.
     *
     * @return array<int, int> calendarYear => state_pension pence
     */
    private function statePensionByYear(string $dob): array
    {
        $household = new Household(
            'State Pension transition',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable($dob), Sex::Male, EmploymentStatus::Retired,
                longevity: LongevityAdjustment::fixedAge(95))],
            new ExpenseProfile(Money::fromPounds(10_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30))],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(400_000))],
        );

        $byYear = [];
        foreach ($this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years as $year) {
            $byYear[$year->calendarYear] = $year->incomeBySource['state_pension']->pence;
        }

        return $byYear;
    }

    public function test_state_pension_is_prorated_in_the_year_the_entitlement_starts(): void
    {
        // The control reaches State Pension age in December 2031, so 2032 and 2033 are whole years
        // for them. Same run, same year index, so the same triple-lock factor applies to everyone
        // below: the comparison needs no restatement of the uprating rule.
        $whole = $this->statePensionByYear('1964-12-15');

        foreach (self::BIRTH_MONTHS as $month => $dob) {
            $sp = $this->statePensionByYear($dob);

            $this->assertSame(0, $sp[2031], "month $month: nothing is paid before State Pension age");
            $this->assertSame(
                (int) round($whole[2032] * (12 - $month) / 12),
                $sp[2032],
                "month $month: only the part of 2032 after the entitlement date is paid",
            );
            $this->assertSame($whole[2033], $sp[2033], "month $month: a whole year is paid from then on");
        }
    }

    /**
     * A lone retired person's DB pension by calendar year. The scheme grants NO increase in either
     * phase, so its running factor stays 1.0 and the nominal figure is the accrued pension itself —
     * the transition year is then readable without restating an escalation rule.
     *
     * @return array<int, int> calendarYear => defined_benefit pence
     */
    private function dbIncomeByYear(string $dob): array
    {
        $household = new Household(
            'DB transition',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable($dob), Sex::Male, EmploymentStatus::Retired,
                longevity: LongevityAdjustment::fixedAge(95))],
            new ExpenseProfile(Money::fromPounds(10_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new DbPension('p1', Money::fromPounds(12_000), normalRetirementAge: 65,
                revaluationBasis: PensionEscalationBasis::None, escalationInPayment: PensionEscalationBasis::None)],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(400_000))],
        );

        $byYear = [];
        foreach ($this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years as $year) {
            $byYear[$year->calendarYear] = $year->incomeBySource['defined_benefit']->pence;
        }

        return $byYear;
    }

    public function test_db_pension_is_prorated_in_the_year_of_normal_retirement_age(): void
    {
        foreach (self::BIRTH_MONTHS as $month => $dob) {
            $db = $this->dbIncomeByYear($dob); // turns 65 in 2030

            $this->assertSame(0, $db[2029], "month $month: nothing is paid before normal retirement age");
            $this->assertSame(
                (int) round(1_200_000 * (12 - $month) / 12),
                $db[2030],
                "month $month: only the part of 2030 after the birthday is paid",
            );
            $this->assertSame(1_200_000, $db[2031], "month $month: a whole year is paid from then on");
        }
    }

    /**
     * The National Insurance charged on a £120,000 salary, by calendar year: the difference between
     * an ordinary earner's total tax and the same earner in category X (no primary liability), so
     * income tax cancels and only NI is left. Flat assumptions mean no investment income, so nothing
     * else can differ between the two runs.
     *
     * @return array<int, int> calendarYear => employee NI pence
     */
    private function niByYear(string $dob): array
    {
        $run = function (?string $category) use ($dob): array {
            $household = new Household(
                'NI transition',
                RegionProfile::EnglandWalesNi,
                [new Person('p1', new DateTimeImmutable($dob), Sex::Male, EmploymentStatus::Employed,
                    grossSalary: Money::fromPounds(120_000), niCategory: $category, plannedRetirementAge: 70,
                    longevity: LongevityAdjustment::fixedAge(95))],
                new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
                pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30))],
            );

            $byYear = [];
            foreach ($this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years as $year) {
                $byYear[$year->calendarYear] = $year->totalTax->pence;
            }

            return $byYear;
        };

        $standard = $run(null);
        $noLiability = $run('X');

        $ni = [];
        foreach ($standard as $calendarYear => $totalTax) {
            $ni[$calendarYear] = $totalTax - $noLiability[$calendarYear];
        }

        return $ni;
    }

    public function test_national_insurance_is_charged_on_the_earnings_before_state_pension_age(): void
    {
        $ni = new NationalInsuranceCalculator(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi));
        $fullYear = $ni->onEmploymentEarnings(Money::fromPounds(120_000))->total->pence;

        foreach (self::BIRTH_MONTHS as $month => $dob) {
            $charged = $this->niByYear($dob); // reaches State Pension age in 2032, still working to 70

            $this->assertSame($fullYear, $charged[2031], "month $month: a full year of NI before State Pension age");
            $this->assertSame(
                $ni->onEmploymentEarnings(Money::fromPence((int) round(12_000_000 * $month / 12)))->total->pence,
                $charged[2032],
                "month $month: NI is still due on the earnings before the State Pension age date",
            );
            $this->assertSame(0, $charged[2033], "month $month: no NI once State Pension age is behind them");
        }
    }
}
