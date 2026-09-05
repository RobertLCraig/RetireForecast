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
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
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
 * A Defined Benefit pension increases on the basis the scheme actually uses, and the basis it
 * uses while DEFERRED is not the basis it uses IN PAYMENT. Before board card 0035 the projector
 * ran one household-wide factor that escalated every DB pension at full CPI for ever, so both
 * dropdowns were collected, stored, validated and mapped onto the DTO — and never read. Pre-1997
 * accrual with no statutory increase was handed a forecast in which it rose with prices for
 * thirty years.
 *
 * These tests pin each basis to a figure that can be checked by hand, and pin the deferral
 * boundary, so a control the reader can see cannot go back to meaning nothing.
 */
final class DbEscalationTest extends TestCase
{
    /** The £/yr accrued pension every case here is built on. */
    private const ACCRUED = 20_000;

    /**
     * Flat-real assumptions with a CHOSEN inflation rate: nothing grows in real terms, so the DB
     * income is the only thing moving and every expected figure is the accrued pension times a
     * compounding factor a reader can check on paper.
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
     * The household's defined-benefit income by calendar year, for a couple where P1 holds a
     * £20k DB pension on the given bases and both live well past the twenty years these tests
     * read. $memberBirthYear sets whether the pension is already in payment in the base year
     * (born 1956 = age 70 against a normal retirement age of 65) or still deferred (born 1971 =
     * age 55, so it comes into payment in 2036).
     *
     * Read off the year's NOMINAL twin, not its real figures: escalation is a nominal rule, and
     * the real view divides it straight back out again — a CPI-escalated pension and a frozen one
     * would be told apart only by the deflator, which is the thing under test wearing a disguise.
     *
     * @return array<int, int> calendarYear => nominal defined_benefit pence
     */
    private function dbIncomeByYear(
        PensionEscalationBasis $escalationInPayment,
        float $inflationPercent,
        PensionEscalationBasis $revaluationBasis = PensionEscalationBasis::Cpi,
        int $memberBirthYear = 1956,
        ?Percent $fixedEscalationRate = null,
    ): array {
        $household = new Household(
            'DB escalation',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable($memberBirthYear.'-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(99)),
                new Person('p2', new DateTimeImmutable(($memberBirthYear + 2).'-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(99)),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(150, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(150, 0)),
                new DbPension(
                    'p1',
                    Money::fromPounds(self::ACCRUED),
                    normalRetirementAge: 65,
                    revaluationBasis: $revaluationBasis,
                    escalationInPayment: $escalationInPayment,
                    fixedEscalationRate: $fixedEscalationRate,
                ),
            ],
        );

        $result = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->assumptionsWithInflation($inflationPercent), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        $db = [];
        foreach ($result->years as $y) {
            $nominal = $y->nominal;
            self::assertNotNull($nominal, 'the deterministic forecast carries a nominal twin');
            $db[$y->calendarYear] = $nominal->incomeBySource['defined_benefit']->pence;
        }

        return $db;
    }

    /** The accrued pension compounded at $ratePercent for $years, in pence. */
    private function compounded(float $ratePercent, int $years): float
    {
        return Money::fromPounds(self::ACCRUED)->pence * ((1.0 + $ratePercent / 100.0) ** $years);
    }

    /**
     * AC#1. A pre-1997 slice of accrual with no statutory increase is FLAT in nominal terms: the
     * same pounds in 2046 as in 2026, worth barely a third as much. Before card 0035 it rose at
     * full CPI, which on a 5% path is £53k of nominal income the scheme never promised.
     */
    public function test_no_escalation_in_payment_holds_a_db_pension_flat_in_nominal_terms(): void
    {
        $db = $this->dbIncomeByYear(PensionEscalationBasis::None, 5.0);

        $this->assertSame(Money::fromPounds(self::ACCRUED)->pence, $db[2026]);
        $this->assertSame(Money::fromPounds(self::ACCRUED)->pence, $db[2036], 'a pension with no escalation does not rise at ten years');
        $this->assertSame(Money::fromPounds(self::ACCRUED)->pence, $db[2046], 'nor at twenty');
    }

    /**
     * AC#2. A capped basis (statutory LPI) applies the year's inflation UP TO the cap and no
     * more, so on a 5% path a 2.5%-capped pension rises at 2.5% and a 5%-capped one rises at the
     * full 5%. The pair proves the cap is read per basis rather than one blanket rule.
     *
     * The delta is a few pence: the engine compounds a running factor year by year where the
     * expectation raises one power, so they agree to floating-point noise, not to the penny.
     */
    public function test_a_capped_escalation_applies_inflation_up_to_the_cap_and_no_more(): void
    {
        $cappedAt2_5 = $this->dbIncomeByYear(PensionEscalationBasis::CpiCappedAt2_5, 5.0);
        $this->assertEqualsWithDelta($this->compounded(2.5, 20), $cappedAt2_5[2046], 50.0, 'a 2.5% cap bites on a 5% inflation path');

        $cappedAt5 = $this->dbIncomeByYear(PensionEscalationBasis::CpiCappedAt5, 5.0);
        $this->assertEqualsWithDelta($this->compounded(5.0, 20), $cappedAt5[2046], 50.0, 'a 5% cap does not bite on the same path');

        // And the cap is a CAP, not a rate: below it, the pension gets the inflation it earned.
        $lowInflation = $this->dbIncomeByYear(PensionEscalationBasis::CpiCappedAt5, 2.0);
        $this->assertEqualsWithDelta($this->compounded(2.0, 20), $lowInflation[2046], 50.0, 'under the cap the pension rises at inflation');
    }

    /**
     * AC#3. A DEFERRED pension revalues on its revaluation basis until normal retirement age and
     * escalates on its in-payment basis afterwards — the two are different rules and the engine
     * used to apply one factor to both. The member is 55 in the base year with a normal
     * retirement age of 65, so 2036 is the first year in payment.
     *
     * Revaluation None, escalation CPI: the pension arrives at £20,000 in 2036 having gained
     * nothing in deferment, then rises at 5% for ten years. The old single factor made 2036 pay
     * £32,578 — ten years of CPI the scheme never granted.
     */
    public function test_a_deferred_pension_revalues_on_one_basis_then_escalates_on_the_other(): void
    {
        $db = $this->dbIncomeByYear(
            escalationInPayment: PensionEscalationBasis::Cpi,
            inflationPercent: 5.0,
            revaluationBasis: PensionEscalationBasis::None,
            memberBirthYear: 1971,
        );

        $this->assertSame(0, $db[2035], 'nothing is paid before normal retirement age');
        $this->assertSame(Money::fromPounds(self::ACCRUED)->pence, $db[2036], 'a pension revalued on None arrives at its accrued amount');
        $this->assertEqualsWithDelta($this->compounded(5.0, 10), $db[2046], 50.0, 'and then escalates on its in-payment basis');
    }

    /**
     * The mirror image, which is the case that proves the two bases are not simply swapped: a
     * pension revalued at CPI in deferment but FROZEN once in payment arrives higher and then
     * stops moving.
     */
    public function test_a_deferred_pension_frozen_in_payment_arrives_revalued_and_then_stands_still(): void
    {
        $db = $this->dbIncomeByYear(
            escalationInPayment: PensionEscalationBasis::None,
            inflationPercent: 5.0,
            revaluationBasis: PensionEscalationBasis::Cpi,
            memberBirthYear: 1971,
        );

        $this->assertEqualsWithDelta($this->compounded(5.0, 10), $db[2036], 50.0, 'ten years of deferred revaluation reach the payment date');
        $this->assertSame($db[2036], $db[2046], 'and nothing is added once it is in payment');
    }

    /**
     * Every basis the reader can choose moves the twenty-year income to a DIFFERENT place, which
     * is the whole point of the control. RPI is the exception and is asserted below on its own:
     * the engine models no RPI-over-CPI wedge (see PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS),
     * so it lands on CPI.
     */
    public function test_each_escalation_basis_reaches_a_different_twenty_year_income(): void
    {
        $at2046 = [];
        foreach ([
            PensionEscalationBasis::None,
            PensionEscalationBasis::Cpi,
            PensionEscalationBasis::CpiCappedAt5,
            PensionEscalationBasis::CpiCappedAt2_5,
            PensionEscalationBasis::Fixed,
        ] as $basis) {
            $at2046[$basis->value] = $this->dbIncomeByYear($basis, 6.0)[2046];
        }

        $this->assertSame(
            count($at2046),
            count(array_unique($at2046)),
            'five bases, five different twenty-year incomes: '.json_encode($at2046)
        );
        // Ordered as a reader would expect: nothing < a 2.5% cap < a 3% fixed rate < a 5% cap < full CPI at 6%.
        $this->assertSame(
            ['none', 'cpi_capped_2_5', 'fixed', 'cpi_capped_5', 'cpi'],
            array_keys($at2046 = $this->sortedAscending($at2046)),
        );
    }

    /**
     * The RPI case, stated openly rather than left to be discovered. The engine has no sourced
     * RPI-over-CPI wedge, so RPI escalates at CPI; this pins that it is a DELIBERATE zero and
     * fails the moment a wedge is introduced without the disclosure moving with it.
     */
    public function test_rpi_escalates_at_cpi_because_no_wedge_is_modelled(): void
    {
        $this->assertSame(0, PensionEscalationBasis::RPI_OVER_CPI_WEDGE_BPS);

        $rpi = $this->dbIncomeByYear(PensionEscalationBasis::Rpi, 6.0);
        $cpi = $this->dbIncomeByYear(PensionEscalationBasis::Cpi, 6.0);

        $this->assertSame($cpi[2046], $rpi[2046]);
    }

    /**
     * A scheme that grants a fixed increase uses the rate the READER entered, not the engine's
     * disclosed default — otherwise the new input would be as dead as the dropdown was.
     */
    public function test_a_fixed_escalation_uses_the_rate_the_scheme_actually_grants(): void
    {
        $db = $this->dbIncomeByYear(
            escalationInPayment: PensionEscalationBasis::Fixed,
            inflationPercent: 6.0,
            fixedEscalationRate: Percent::fromPercent(5),
        );

        $this->assertEqualsWithDelta($this->compounded(5.0, 20), $db[2046], 50.0);
    }

    /** @param  array<string, int>  $byBasis */
    private function sortedAscending(array $byBasis): array
    {
        asort($byBasis);

        return $byBasis;
    }
}
