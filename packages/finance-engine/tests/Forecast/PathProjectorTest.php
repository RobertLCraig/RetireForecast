<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DbPension;
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
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

final class PathProjectorTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    /** Flat assumptions (no inflation, no growth) so a salary stays a clean nominal figure. */
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

    public function test_salary_is_prorated_in_the_retirement_year_not_dropped(): void
    {
        // Born July (month 7), retires at 66 (turns 66 in 2031). With flat assumptions the £60k
        // salary is full while age < 66, 7/12 in the year they turn 66, and nil after.
        $person = new Person('p1', new DateTimeImmutable('1965-07-15'), Sex::Male, EmploymentStatus::Employed,
            grossSalary: Money::fromPounds(60_000), plannedRetirementAge: 66);
        $household = new Household('Solo', RegionProfile::EnglandWalesNi, [$person],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
            [new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30))],
        );

        $result = $this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings());

        $salary = [];
        foreach ($result->years as $y) {
            $salary[$y->calendarYear] = $y->incomeBySource['salary']->pence;
        }

        $this->assertSame(Money::fromPounds(60_000)->pence, $salary[2030]); // age 65 — full year
        $this->assertSame(Money::fromPounds(35_000)->pence, $salary[2031]); // age 66 — 7/12 of £60k
        $this->assertSame(0, $salary[2032]);                                // age 67 — retired
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

    public function test_essential_spend_is_exposed_as_the_floor_within_the_target(): void
    {
        $household = $this->couple(
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(4_000), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(300_000), Money::zero(), Money::zero(), 55),
            ],
        );

        $year0 = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $this->settings())->years[0];

        // Base year (no inflation/growth yet): the essential floor is exactly the £18k
        // entered, and it is the essential part of the £22k target (essential <= target).
        $this->assertSame(1_800_000, $year0->essentialSpend->pence);
        $this->assertSame(2_200_000, $year0->spendTarget->pence);
        $this->assertLessThanOrEqual($year0->spendTarget->pence, $year0->essentialSpend->pence);
    }

    public function test_essential_spend_includes_rent_on_the_renting_leg(): void
    {
        // No property, an £8k/yr rent: rent is an essential cost, so it lifts the essential
        // floor above the bare £18k entered (the renting household's secure-income bar).
        $expense = new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(4_000), Percent::fromPercent(70));
        $household = $this->couple($expense, pensions: [
            new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
            new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            new DcPension('p2', Money::fromPounds(300_000), Money::zero(), Money::zero(), 55),
        ]);

        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', annualRent: Money::fromPounds(8_000));
        $year0 = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $settings)->years[0];

        $this->assertSame(2_600_000, $year0->essentialSpend->pence, 'rent (£8k) lifts the essential floor to £26k');
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

    public function test_income_by_source_captures_every_regular_inflow(): void
    {
        $p1 = new Person('p1', new DateTimeImmutable('1968-04-01'), Sex::Female, EmploymentStatus::Employed, grossSalary: Money::fromPounds(40_000), plannedRetirementAge: 67);
        $p2 = new Person('p2', new DateTimeImmutable('1955-04-01'), Sex::Male, EmploymentStatus::Retired);

        $household = new Household(
            'All sources',
            RegionProfile::EnglandWalesNi,
            [$p1, $p2],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(5_000), Percent::fromPercent(70)),
            [
                new DcPension('p1', Money::fromPounds(200_000), Money::zero(), Money::zero(), 55, [
                    new WithdrawalInstruction(WithdrawalKind::Ufpls, Money::fromPounds(20_000), 58),
                ]),
                new DbPension('p2', Money::fromPounds(8_000), 65),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
            [],
            [
                new IncomeStream('p2', IncomeStreamType::Annuity, Money::fromPounds(5_000), taxable: true, inflationLinked: false, startAge: 0, endAge: null),
                new IncomeStream('p2', IncomeStreamType::Other, Money::fromPounds(4_000), taxable: false, inflationLinked: false, startAge: 0, endAge: null),
            ],
        );

        // p1 is 58 in 2026, so the UFPLS fires in the base year alongside everything else.
        $income = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $this->settings())->years[0]->incomeBySource;

        foreach (['salary', 'defined_benefit', 'state_pension', 'other_taxable', 'tax_free_income', 'pension_lump_sum', 'pension_drawdown'] as $source) {
            $this->assertArrayHasKey($source, $income);
            $this->assertTrue($income[$source]->isPositive(), "income source '{$source}' should contribute to the year");
        }
    }

    public function test_income_by_source_records_drawdown_when_funding_a_shortfall(): void
    {
        // No guaranteed income, high spend -> the shortfall is funded from savings then the pension.
        $household = new Household(
            'Drawdown',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(40_000), Money::zero(), Percent::fromPercent(70)),
            [new DcPension('p2', Money::fromPounds(300_000), Money::zero(), Money::zero(), 55)],
            [new Account('p1', AccountType::Cash, Money::fromPounds(30_000))],
        );

        $income = $this->forecaster()->forecast($household, AssumptionSetLibrary::default(), $this->settings())->years[0]->incomeBySource;

        $this->assertTrue($income['asset_drawdown']->isPositive(), 'cash should be drawn to fund the shortfall');
        $this->assertTrue($income['pension_drawdown']->isPositive(), 'the pension should be drawn once cash is exhausted');
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
