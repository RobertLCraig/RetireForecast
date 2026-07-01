<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AnnuityPurchase;
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
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Pension;
use RetireForecast\FinanceEngine\Dto\PensionEscalationBasis;
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

    public function test_pension_credit_tops_up_a_low_income_pensioner_couple(): void
    {
        $household = $this->couple(
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(120, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(120, 0)),
            ],
        );

        $year0 = $this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years[0];

        // Couple guarantee £363.25/wk − £240/wk of State Pension = £123.25/wk = £6,409.00 a year.
        $this->assertSame(640_900, $year0->incomeBySource['means_tested_benefit']->pence);
        $this->assertGreaterThan(0, $year0->netIncome->pence); // it reaches spendable cash, tax-free
    }

    public function test_pension_credit_is_zero_when_income_exceeds_the_guarantee(): void
    {
        $household = $this->couple(
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
        );

        $year0 = $this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years[0];

        $this->assertSame(0, $year0->incomeBySource['means_tested_benefit']->pence);
    }

    public function test_capital_from_a_sale_erodes_pension_credit_in_the_forecast(): void
    {
        // The downsizing trap, modelled in-projection: a couple eligible for £123.25/wk of
        // Guarantee Credit who hold £130,000 (e.g. from selling the home) have it wiped — the
        // capital deems (£130k − £10k) / £500 = £240/wk of tariff income, above the award.
        $household = $this->couple(
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(120, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(120, 0)),
            ],
            [new Account('p1', AccountType::Cash, Money::fromPounds(130_000))],
        );

        $year0 = $this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years[0];

        $this->assertSame(0, $year0->incomeBySource['means_tested_benefit']->pence);
    }

    public function test_the_disability_flag_unlocks_the_severe_disability_addition(): void
    {
        $expense = new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70));
        $pensions = [
            new StatePensionEntitlement('p1', weeklyForecast: Money::of(203, 0)),
            new StatePensionEntitlement('p2', weeklyForecast: Money::of(203, 0)),
        ];

        // £406/wk exceeds the plain £363.25 couple guarantee → no Pension Credit.
        $able = $this->couple($expense, $pensions);
        $this->assertSame(0, $this->forecaster()->forecast($able, $this->flatAssumptions(), $this->settings())
            ->years[0]->incomeBySource['means_tested_benefit']->pence);

        // Flagging one partner as receiving a disability benefit adds the £86.05/wk severe-
        // disability addition, lifting the guarantee to £449.30 → £43.30/wk = £2,251.60 a year.
        $disabledP1 = new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired, receivesDisabilityBenefit: true);
        $withSdp = $this->couple($expense, $pensions, override1: $disabledP1);
        $this->assertSame(4_330 * 52, $this->forecaster()->forecast($withSdp, $this->flatAssumptions(), $this->settings())
            ->years[0]->incomeBySource['means_tested_benefit']->pence);
    }

    public function test_a_let_home_counts_as_assessable_capital_and_erodes_pension_credit(): void
    {
        // A low-income couple who qualify for Pension Credit — but they LET their home and live
        // elsewhere. Its equity (£300k − £100k = £200k) becomes assessable capital, whose tariff
        // income wipes the Guarantee Credit, exactly as selling the home would (the let-to-let trap).
        $make = fn (bool $isLet): Household => new Household(
            'Let', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(120, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(120, 0)),
            ],
            primaryResidence: new Property(Money::fromPounds(300_000), OwnershipType::Mortgaged, outstandingMortgage: Money::fromPounds(100_000), isLet: $isLet),
        );

        $pc = fn (Household $h): int => $this->forecaster()->forecast($h, $this->flatAssumptions(), $this->settings())
            ->years[0]->incomeBySource['means_tested_benefit']->pence;

        // Occupied: the home is the exempt main residence → Pension Credit is paid.
        $this->assertGreaterThan(0, $pc($make(false)));
        // Let out: £200k of equity is assessable capital → the tariff wipes the award.
        $this->assertSame(0, $pc($make(true)));
    }

    /**
     * @param  list<Pension>  $pensions
     */
    private function homeownerCouple(Property $home, array $accounts = [], int $essential = 20_000): Household
    {
        return new Household(
            'Redeem',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds($essential), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
            $accounts,
            primaryResidence: $home,
        );
    }

    public function test_mortgage_redemption_repays_the_balance_from_capital_once_in_the_redemption_year(): void
    {
        $home = new Property(
            currentValue: Money::fromPounds(300_000),
            ownership: OwnershipType::Mortgaged,
            outstandingMortgage: Money::fromPounds(100_000),
            mortgageRedemptionYear: 2030,
            mortgageMaturityAction: MortgageMaturityAction::RepayFromCapital,
        );
        $household = $this->homeownerCouple($home, [new Account('p1', AccountType::Cash, Money::fromPounds(150_000))]);

        $byYear = [];
        foreach ($this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years as $y) {
            $byYear[$y->calendarYear] = $y;
        }

        // The £100k repayment lands once, in 2030: spend that year is ~£100k above the year before,
        // funded from the cash, and 2031 returns to the baseline (it is a one-off, not recurring).
        $jump = $byYear[2030]->spendTarget->pence - $byYear[2029]->spendTarget->pence;
        $this->assertEqualsWithDelta(Money::fromPounds(100_000)->pence, $jump, Money::fromPounds(500)->pence);
        $this->assertEqualsWithDelta($byYear[2029]->spendTarget->pence, $byYear[2031]->spendTarget->pence, Money::fromPounds(500)->pence);
        $this->assertTrue($byYear[2030]->incomeBySource['asset_drawdown']->isPositive(), 'the repayment is funded from savings');
    }

    public function test_a_repay_from_capital_redemption_the_household_cannot_afford_shows_a_shortfall(): void
    {
        // £200k due to redeem but only £20k of savings → the keep-the-home option is unaffordable,
        // so the year the mortgage falls due leaves spend unmet (the feasibility signal).
        $home = new Property(
            currentValue: Money::fromPounds(300_000),
            ownership: OwnershipType::Mortgaged,
            outstandingMortgage: Money::fromPounds(200_000),
            mortgageRedemptionYear: 2030,
            mortgageMaturityAction: MortgageMaturityAction::RepayFromCapital,
        );
        $household = $this->homeownerCouple($home, [new Account('p1', AccountType::Cash, Money::fromPounds(20_000))]);

        $byYear = [];
        foreach ($this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years as $y) {
            $byYear[$y->calendarYear] = $y;
        }

        $this->assertTrue($byYear[2030]->unmetSpend->isPositive(), 'a redemption that cannot be met surfaces as unmet spend');
    }

    public function test_a_refinanced_mortgage_has_no_redemption_spike(): void
    {
        $home = new Property(
            currentValue: Money::fromPounds(300_000),
            ownership: OwnershipType::Mortgaged,
            outstandingMortgage: Money::fromPounds(100_000),
            mortgageRedemptionYear: 2030,
            mortgageMaturityAction: MortgageMaturityAction::Refinance,
        );
        $household = $this->homeownerCouple($home, [new Account('p1', AccountType::Cash, Money::fromPounds(150_000))]);

        $byYear = [];
        foreach ($this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years as $y) {
            $byYear[$y->calendarYear] = $y;
        }

        // Refinance rolls the loan over — no capital event, so 2030 matches its neighbours.
        $this->assertEqualsWithDelta($byYear[2029]->spendTarget->pence, $byYear[2030]->spendTarget->pence, Money::fromPounds(500)->pence);
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

    public function test_fill_bands_draws_pension_within_the_free_bands_before_taxed_capital(): void
    {
        // p1 is 58 (below State Pension age), so the couple is a mixed-age household with no
        // Pension Credit and, with no State Pension income yet, a full personal allowance of
        // headroom. FillBands should draw the pension within that free band (0% tax) before
        // spending the tax-free cash; TaxEfficient spends the cash first.
        $p1 = new Person('p1', new DateTimeImmutable('1968-04-01'), Sex::Female, EmploymentStatus::Retired);
        $build = fn () => $this->couple(
            new ExpenseProfile(Money::fromPounds(30_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new DcPension('p1', Money::fromPounds(200_000), Money::zero(), Money::zero(), 55)],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(80_000))],
            override1: $p1,
        );

        $taxEfficient = $this->forecaster()->forecast($build(), $this->flatAssumptions(), $this->settings(DrawdownStrategy::TaxEfficient));
        $fillBands = $this->forecaster()->forecast($build(), $this->flatAssumptions(), $this->settings(DrawdownStrategy::FillBands));

        // FillBands has drawn the pension pot down (used the free band); TaxEfficient has not.
        $this->assertLessThan(
            $taxEfficient->years[1]->pensionWealth->pence,
            $fillBands->years[1]->pensionWealth->pence,
        );
        // ...and conversely preserved more of the tax-free cash.
        $this->assertGreaterThan(
            $taxEfficient->years[1]->liquidWealth->pence,
            $fillBands->years[1]->liquidWealth->pence,
        );
    }

    public function test_fill_bands_is_pension_credit_aware_and_leaves_the_pension_intact(): void
    {
        // A low-income couple over State Pension age on Guarantee Credit. Any taxable pension
        // income would claw the credit back £-for-£, so FillBands draws the tax-free cash first
        // and leaves the pension untouched, where PensionAware would draw the pension.
        $build = fn () => $this->couple(
            new ExpenseProfile(Money::fromPounds(30_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(120, 0)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(120, 0)),
                new DcPension('p1', Money::fromPounds(100_000), Money::zero(), Money::zero(), 55),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(60_000))],
        );

        // Premise check: the household is actually on Guarantee Credit.
        $year0 = $this->forecaster()->forecast($build(), $this->flatAssumptions(), $this->settings(DrawdownStrategy::FillBands))->years[0];
        $this->assertGreaterThan(0, $year0->incomeBySource['means_tested_benefit']->pence);

        $pensionAware = $this->forecaster()->forecast($build(), $this->flatAssumptions(), $this->settings(DrawdownStrategy::PensionAware));
        $fillBands = $this->forecaster()->forecast($build(), $this->flatAssumptions(), $this->settings(DrawdownStrategy::FillBands));

        // FillBands (Pension-Credit-aware) has left more pension intact than PensionAware.
        $this->assertGreaterThan(
            $pensionAware->years[1]->pensionWealth->pence,
            $fillBands->years[1]->pensionWealth->pence,
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
        // No private income and high spend: Pension Credit tops up part of the gap, but the
        // shortfall still exhausts the small cash buffer and then draws the pension.
        $household = new Household(
            'Drawdown',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(40_000), Money::zero(), Percent::fromPercent(70)),
            [new DcPension('p2', Money::fromPounds(300_000), Money::zero(), Money::zero(), 55)],
            [new Account('p1', AccountType::Cash, Money::fromPounds(5_000))],
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

    /** Inflation but no real growth, so a nominal annuity's real value erodes at a clean rate. */
    private function inflationOnlyAssumptions(float $inflationPercent): AssumptionSet
    {
        return new AssumptionSet(
            name: 'inflation', sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::zero(), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::fromPercent($inflationPercent), inflationVolatility: Percent::zero(),
            houseGrowth: Percent::zero(), rentInflation: Percent::zero(),
            salaryGrowth: Percent::zero(), investmentIncomeYield: Percent::zero(),
        );
    }

    public function test_an_annuity_purchase_converts_the_pot_into_a_lifetime_income(): void
    {
        // p2 (68 in 2026) with a £100k pot. Two State Pensions cover the £15k essentials, so nothing
        // is drawn and the control pot is left intact — isolating the effect of the annuity.
        $pensions = fn (?AnnuityPurchase $annuity): array => [
            new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
            new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            new DcPension('p2', Money::fromPounds(100_000), Money::zero(), Money::zero(), 55, annuityPurchase: $annuity),
        ];
        $expense = new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70));

        // Level annuity, 7.2%, bought at 68 (fires in the base year 2026).
        $annuity = new AnnuityPurchase(atAge: 68, amount: Money::fromPounds(100_000), rate: Percent::fromPercent(7.2));

        $control = $this->forecaster()->forecast($this->couple($expense, $pensions(null)), $this->flatAssumptions(), $this->settings())->years[0];
        $withAnnuity = $this->forecaster()->forecast($this->couple($expense, $pensions($annuity)), $this->flatAssumptions(), $this->settings())->years[0];

        // Control: no annuity income, the £100k pot intact.
        $this->assertSame(0, $control->incomeBySource['other_taxable']->pence);
        $this->assertSame(10_000_000, $control->pensionWealth->pence);

        // With the annuity: the pot is converted (pension wealth gone), paying £100k × 7.2% = £7,200
        // a year of taxable income — completeness: the annuity demonstrably reaches the forecast.
        $this->assertSame(0, $withAnnuity->pensionWealth->pence, 'the pot is exchanged for the annuity, so holds no drawable value');
        $this->assertSame(720_000, $withAnnuity->incomeBySource['other_taxable']->pence);
    }

    public function test_a_level_annuity_erodes_in_real_terms_while_an_escalating_one_holds(): void
    {
        $build = fn (PensionEscalationBasis $basis): Household => $this->couple(
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(100_000), Money::zero(), Money::zero(), 55,
                    annuityPurchase: new AnnuityPurchase(68, Money::fromPounds(100_000), Percent::fromPercent(7.2), $basis)),
            ],
        );

        $assume = $this->inflationOnlyAssumptions(3.0);
        $level = $this->forecaster()->forecast($build(PensionEscalationBasis::None), $assume, $this->settings())->years;
        $rpi = $this->forecaster()->forecast($build(PensionEscalationBasis::Rpi), $assume, $this->settings())->years;

        $real = fn (array $years, int $i): int => $years[$i]->incomeBySource['other_taxable']->pence;

        // Both start at the same £7,200 real in the purchase year.
        $this->assertSame(720_000, $real($level, 0));
        $this->assertSame(720_000, $real($rpi, 0));

        // A level annuity pays a flat NOMINAL income, so its REAL value falls with inflation...
        $this->assertLessThan($real($level, 0), $real($level, 5));
        // ...while an RPI annuity escalates with inflation, holding its real value (± a rounding penny).
        $this->assertEqualsWithDelta(720_000, $real($rpi, 5), 5);
    }

    public function test_a_joint_annuity_continues_to_the_survivor_but_a_single_life_one_stops(): void
    {
        // The annuitant (p2) dies at 70 (2028); the partner (p1) lives to 85. A level annuity of
        // £7,200 is bought at 68. Flat assumptions, so nominal == real and the figures are exact.
        $build = fn (?Percent $survivorFraction): Household => new Household(
            'Joint annuity', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(85)),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(70)),
            ],
            new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(100_000), Money::zero(), Money::zero(), 55,
                    annuityPurchase: new AnnuityPurchase(68, Money::fromPounds(100_000), Percent::fromPercent(7.2), PensionEscalationBasis::None, $survivorFraction)),
            ],
        );

        $byYear = function (Household $h): array {
            $out = [];
            foreach ($this->forecaster()->forecast($h, $this->flatAssumptions(), $this->settings())->years as $y) {
                $out[$y->calendarYear] = $y->incomeBySource['other_taxable']->pence;
            }

            return $out;
        };

        $joint = $byYear($build(Percent::fromPercent(50)));
        $single = $byYear($build(null));

        // While the annuitant lives (2027), both pay the full £7,200.
        $this->assertSame(720_000, $joint[2027]);
        $this->assertSame(720_000, $single[2027]);

        // After the annuitant dies (2029, aged 71): the joint annuity pays the survivor 50% = £3,600;
        // the single-life annuity stops entirely.
        $this->assertSame(360_000, $joint[2029]);
        $this->assertSame(0, $single[2029]);
    }
}
