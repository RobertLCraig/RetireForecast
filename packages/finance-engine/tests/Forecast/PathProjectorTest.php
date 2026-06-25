<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Pension;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class PathProjectorTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function settings(DrawdownStrategy $strategy = DrawdownStrategy::TaxEfficient): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', drawdownStrategy: $strategy);
    }

    /**
     * @param  list<Pension>  $pensions
     * @param  list<Account>  $accounts
     */
    private function couple(ExpenseProfile $expense, array $pensions = [], array $accounts = [], ?Person $override1 = null, array $incomeStreams = []): Household
    {
        // Both born 1958: aged 68 in 2026 (over State Pension age).
        $p1 = $override1 ?? new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired);
        $p2 = new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired);

        return new Household('Test', RegionProfile::EnglandWalesNi, [$p1, $p2], $expense, $pensions, $accounts, $incomeStreams);
    }

    public function test_comfortable_household_never_runs_out(): void
    {
        $household = $this->couple(
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(4_000), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(300_000), Money::zero(), Money::zero(), 55),
            ],
        );

        $result = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $this->settings());

        $this->assertNotEmpty($result->years);
        $this->assertTrue($result->essentialsAlwaysMet);
        $this->assertNull($result->depletionCalendarYear);
        $this->assertTrue($result->terminalTotalWealth->isPositive());
    }

    public function test_underfunded_household_runs_out(): void
    {
        // Both 62 (no State Pension yet), no income, tiny pot, high spend.
        $young1 = new Person('p1', new DateTimeImmutable('1964-04-01'), Sex::Female, EmploymentStatus::NotWorking);
        $household = new Household(
            'Underfunded',
            RegionProfile::EnglandWalesNi,
            [$young1, new Person('p2', new DateTimeImmutable('1964-09-01'), Sex::Male, EmploymentStatus::NotWorking)],
            new ExpenseProfile(Money::fromPounds(25_000), Money::fromPounds(5_000), Percent::fromPercent(70)),
            [new DcPension('p2', Money::fromPounds(15_000), Money::zero(), Money::zero(), 55)],
        );

        $result = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $this->settings());

        $this->assertFalse($result->essentialsAlwaysMet);
        $this->assertNotNull($result->depletionCalendarYear);
    }

    public function test_tax_free_income_streams_are_counted_as_usable_income(): void
    {
        // Two State Pensions (~£12.5k total) cannot cover £24k of essentials on their own.
        $expense = new ExpenseProfile(Money::fromPounds(24_000), Money::zero(), Percent::fromPercent(70));
        $pensions = [
            new StatePensionEntitlement('p1', weeklyForecast: Money::of(120, 0)),
            new StatePensionEntitlement('p2', weeklyForecast: Money::of(120, 0)),
        ];
        // A tax-free income stream (e.g. DLA) of £14k/yr, owned by p1.
        $dla = new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(14_000), taxable: false, inflationLinked: true, startAge: 0, endAge: null);

        $without = $this->forecaster()->forecast($this->couple($expense, $pensions), AssumptionSetLibrary::default(), $this->settings());
        $with = $this->forecaster()->forecast($this->couple($expense, $pensions, incomeStreams: [$dla]), AssumptionSetLibrary::default(), $this->settings());

        $this->assertFalse($without->essentialsAlwaysMet, 'control: pension income alone does not cover essentials');
        $this->assertTrue($with->essentialsAlwaysMet, 'tax-free DLA income must be counted and cover essentials');
    }

    public function test_pension_aware_draws_the_pot_sooner_than_tax_efficient(): void
    {
        $build = fn () => $this->couple(
            new ExpenseProfile(Money::fromPounds(30_000), Money::fromPounds(10_000), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(200_000), Money::zero(), Money::zero(), 55),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(50_000))],
        );

        $taxEfficient = $this->forecaster()->forecast($build(), AssumptionSetLibrary::default(), $this->settings(DrawdownStrategy::TaxEfficient));
        $pensionAware = $this->forecaster()->forecast($build(), AssumptionSetLibrary::default(), $this->settings(DrawdownStrategy::PensionAware));

        // After a couple of years, the pension-aware run has drawn the pot down more.
        $this->assertLessThan(
            $taxEfficient->years[2]->pensionWealth->pence,
            $pensionAware->years[2]->pensionWealth->pence,
        );
        // ...and conversely preserved more cash (still in liquid wealth).
        $this->assertGreaterThan(
            $taxEfficient->years[2]->liquidWealth->pence,
            $pensionAware->years[2]->liquidWealth->pence,
        );
    }

    public function test_dc_contributions_funded_from_surplus_grow_the_pot(): void
    {
        // A still-working person (salary well above spend) paying into a DC pot.
        $worker = new Person('p1', new DateTimeImmutable('1968-04-01'), Sex::Female, EmploymentStatus::Employed, grossSalary: Money::fromPounds(60_000), plannedRetirementAge: 67);
        $expense = new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70));

        $withContrib = $this->couple($expense, pensions: [
            new DcPension('p1', Money::fromPounds(100_000), Money::fromPounds(10_000), Money::fromPounds(5_000), 57),
        ], override1: $worker);
        $without = $this->couple($expense, pensions: [
            new DcPension('p1', Money::fromPounds(100_000), Money::zero(), Money::zero(), 57),
        ], override1: $worker);

        $a = $this->forecaster()->forecast($withContrib, AssumptionSetLibrary::default(), $this->settings());
        $b = $this->forecaster()->forecast($without, AssumptionSetLibrary::default(), $this->settings());

        // Base year (no inflation/growth yet): the pot is larger by exactly the
        // £15,000 employee + employer contribution, funded entirely from surplus.
        $this->assertSame(
            1_500_000,
            $a->years[0]->pensionWealth->pence - $b->years[0]->pensionWealth->pence,
        );
    }

    public function test_account_contributions_route_surplus_into_investments_to_grow_faster(): void
    {
        $worker = new Person('p1', new DateTimeImmutable('1968-04-01'), Sex::Female, EmploymentStatus::Employed, grossSalary: Money::fromPounds(60_000), plannedRetirementAge: 67);
        $expense = new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70));
        $pensions = [
            new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
            new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
        ];

        $withIsa = $this->couple($expense, pensions: $pensions, accounts: [
            new Account('p1', AccountType::Isa, Money::zero(), ongoingContributions: Money::fromPounds(10_000)),
        ], override1: $worker);
        $cashOnly = $this->couple($expense, pensions: $pensions, override1: $worker);

        $a = $this->forecaster()->forecast($withIsa, AssumptionSetLibrary::default(), $this->settings());
        $b = $this->forecaster()->forecast($cashOnly, AssumptionSetLibrary::default(), $this->settings());

        // The same surplus, routed into an ISA growing at the investment return
        // rather than left sitting in cash, leaves more usable wealth at the end.
        $this->assertGreaterThan($b->terminalUsableWealth->pence, $a->terminalUsableWealth->pence);
    }

    public function test_terminal_usable_wealth_excludes_the_primary_residence(): void
    {
        $household = new Household(
            'Home owner',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(4_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(300_000), Money::zero(), Money::zero(), 55),
            ],
            [],
            [],
            new Property(Money::fromPounds(400_000), OwnershipType::Outright),
        );

        $result = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $this->settings());

        $terminalYear = $result->years[count($result->years) - 1];
        $this->assertGreaterThan(0, $terminalYear->propertyWealth->pence, 'the home should still hold value at the end');
        $this->assertLessThan(
            $result->terminalTotalWealth->pence,
            $result->terminalUsableWealth->pence,
            'usable wealth must exclude the illiquid home',
        );
        $this->assertSame(
            $terminalYear->propertyWealth->pence,
            $result->terminalTotalWealth->pence - $result->terminalUsableWealth->pence,
            'the gap between total and usable wealth is exactly the home value',
        );
    }

    public function test_forecast_honours_a_fixed_assumed_death_age(): void
    {
        $longevity = LongevityAdjustment::fixedAge(80);
        $household = new Household(
            'Short-lived',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired, longevity: $longevity),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired, longevity: $longevity),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
        );

        $result = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $this->settings());

        // Both aged 68 in 2026 and assumed to die at 80 -> the last projected year is 2038.
        $this->assertSame(2038, $result->finalCalendarYear);
    }

    public function test_forecast_terminates_at_the_last_survivor_death(): void
    {
        $result = $this->forecaster()->forecast(
            $this->couple(new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(2_000), Percent::fromPercent(70)), [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ]),
            AssumptionSetLibrary::default(),
            $this->settings(),
        );

        // Aged 68 in 2026; median death in their mid-to-late 80s -> ~15-25 years.
        $this->assertGreaterThan(2026 + 10, $result->finalCalendarYear);
        $this->assertLessThan(2026 + 45, $result->finalCalendarYear);
    }
}
