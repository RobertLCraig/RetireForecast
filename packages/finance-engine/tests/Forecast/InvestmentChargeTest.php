<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Investment returns are quoted GROSS of charges, so the projector deducts the ongoing
 * platform/fund charge from each invested balance after growth
 * ({@see AssumptionSet::$investmentCharge}). These tests hold the discriminating properties:
 * the charge REACHES the result (a real, monotonic drag on wealth, not a decorative figure),
 * it is REPORTED in pounds rather than hidden inside a smaller growth line, cash is exempt,
 * and a null charge leaves the projection byte-identical so every run stored before charges
 * existed still reproduces.
 */
final class InvestmentChargeTest extends TestCase
{
    private const POT = 200_000;

    private function set(?Percent $charge): AssumptionSet
    {
        $base = AssumptionSetLibrary::default();

        return new AssumptionSet(
            name: $base->name,
            sourceNote: $base->sourceNote,
            assetClasses: $base->assetClasses,
            correlationMatrix: $base->correlationMatrix,
            inflationMean: $base->inflationMean,
            inflationVolatility: $base->inflationVolatility,
            houseGrowth: $base->houseGrowth,
            rentInflation: $base->rentInflation,
            salaryGrowth: $base->salaryGrowth,
            investmentIncomeYield: $base->investmentIncomeYield,
            careCostRealGrowth: $base->careCostRealGrowth,
            investmentCharge: $charge,
        );
    }

    /** A retired single with everything in one wrapper, so the charge has an unambiguous base. */
    private function forecast(?Percent $charge, AccountType $type = AccountType::Isa): ForecastResult
    {
        $household = new Household(
            'Charges',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1961-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(8_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(200, 0))],
            accounts: [new Account('p1', $type, Money::fromPounds(self::POT))],
        );

        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->set($charge), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_a_null_charge_leaves_every_year_byte_identical(): void
    {
        // The back-compat contract: a set stored before charges existed must reproduce exactly.
        $none = $this->forecast(null);
        $zero = $this->forecast(Percent::zero());

        $this->assertSame(
            array_map(fn (YearResult $y): int => $y->totalWealth->pence, $none->years),
            array_map(fn (YearResult $y): int => $y->totalWealth->pence, $zero->years),
            'null and an explicit zero charge must project identically',
        );
        foreach ($none->years as $year) {
            $this->assertTrue($year->investmentCharges()->isZero(), 'no charge modelled means no charge reported');
        }
    }

    public function test_the_charge_reaches_the_result_and_compounds_against_wealth(): void
    {
        // The whole point: a charged plan is POORER than a free one, and more so the higher the
        // charge — the drag has to be real and monotonic, not a figure printed beside an
        // unchanged projection.
        $free = $this->forecast(null)->years;
        $half = $this->forecast(Percent::fromPercent(0.5))->years;
        $full = $this->forecast(Percent::fromPercent(1.0))->years;

        $last = count($free) - 1;
        $this->assertLessThan($free[$last]->totalWealth->pence, $half[$last]->totalWealth->pence, '0.5% a year must cost real wealth');
        $this->assertLessThan($half[$last]->totalWealth->pence, $full[$last]->totalWealth->pence, '1.0% must cost more than 0.5%');

        // First-year magnitude: ~0.5% of the grown £200k pot, so a few hundred pounds either
        // side of £1,000 — anchored to the balance so a misplaced decimal cannot pass.
        $firstYearCharge = $half[0]->investmentCharges()->pence;
        $this->assertGreaterThan(Money::fromPounds(900)->pence, $firstYearCharge);
        $this->assertLessThan(Money::fromPounds(1_100)->pence, $firstYearCharge);
    }

    public function test_the_charge_is_reported_in_pounds_not_hidden_inside_a_smaller_growth_line(): void
    {
        // Growth stays GROSS of the charge, which is carried as its own figure — otherwise the
        // reader sees a quietly smaller growth number and no charge at all (no invisible figures).
        $free = $this->forecast(null)->years[0];
        $charged = $this->forecast(Percent::fromPercent(0.5))->years[0];

        $this->assertSame(
            $free->investmentGrowth()->pence,
            $charged->investmentGrowth()->pence,
            'the growth line is before charges, so it does not move when a charge is applied',
        );
        $this->assertGreaterThan(0, $charged->investmentCharges()->pence, 'the charge is reported as its own figure');
    }

    public function test_cash_deposits_bear_no_charge(): void
    {
        // A bank account has no platform or fund fee, so charging it would invent a cost.
        foreach ($this->forecast(Percent::fromPercent(1.0), AccountType::Cash)->years as $year) {
            $this->assertTrue($year->investmentCharges()->isZero(), 'cash carries no ongoing charge');
        }
    }

    public function test_a_pension_pot_is_charged_too(): void
    {
        // Completeness: the charge must reach the DC pot, not just the accounts — for most
        // households the pension IS the invested money.
        $household = new Household(
            'Charges',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1961-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(8_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(200, 0)),
                new DcPension('p1', Money::fromPounds(self::POT), Money::zero(), Money::zero(), 55),
            ],
        );

        $forecaster = new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');

        $charged = $forecaster->forecast($household, $this->set(Percent::fromPercent(0.5)), $settings);
        $free = $forecaster->forecast($household, $this->set(null), $settings);

        $this->assertGreaterThan(0, $charged->years[0]->investmentCharges()->pence, 'the DC pot bears the charge');
        $last = count($free->years) - 1;
        $this->assertLessThan($free->years[$last]->totalWealth->pence, $charged->years[$last]->totalWealth->pence);
    }
}
