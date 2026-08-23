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
use RetireForecast\FinanceEngine\Forecast\PathProjector;
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
    private function couple(ExpenseProfile $expense, array $pensions = [], array $accounts = [], ?Person $override1 = null, array $incomeStreams = [], ?Person $override2 = null): Household
    {
        // Both born 1958: aged 68 in 2026 (over State Pension age).
        $p1 = $override1 ?? new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired);
        $p2 = $override2 ?? new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired);

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

    public function test_the_severe_disability_addition_needs_both_partners_disabled(): void
    {
        $expense = new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70));
        $pensions = [
            new StatePensionEntitlement('p1', weeklyForecast: Money::of(203, 0)),
            new StatePensionEntitlement('p2', weeklyForecast: Money::of(203, 0)),
        ];
        $pc = fn (Household $h): int => $this->forecaster()->forecast($h, $this->flatAssumptions(), $this->settings())
            ->years[0]->incomeBySource['means_tested_benefit']->pence;

        $disabledP1 = new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired, receivesDisabilityBenefit: true);
        $disabledP2 = new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired, receivesDisabilityBenefit: true);

        // £406/wk exceeds the plain £363.25 couple guarantee → no Pension Credit.
        $this->assertSame(0, $pc($this->couple($expense, $pensions)));

        // ONE disabled partner in a couple gives NO severe-disability addition (the non-disabled
        // co-resident blocks it — Turn2us: both partners must get a qualifying benefit). Income
        // still exceeds the plain guarantee → £0. This is the couple-SDP fix (was wrongly £86.05).
        $this->assertSame(0, $pc($this->couple($expense, $pensions, override1: $disabledP1)));

        // BOTH partners disabled → the SDP at the COUPLE rate (2 × £86.05 = £172.10), lifting the
        // guarantee to £535.35 → £129.35/wk = £6,726.20 a year.
        $this->assertSame(12_935 * 52, $pc($this->couple($expense, $pensions, override1: $disabledP1, override2: $disabledP2)));
    }

    public function test_a_partner_who_cares_for_a_disabled_partner_unlocks_the_carer_addition(): void
    {
        $expense = new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70));
        $pensions = [
            new StatePensionEntitlement('p1', weeklyForecast: Money::of(203, 0)),
            new StatePensionEntitlement('p2', weeklyForecast: Money::of(203, 0)),
        ];
        $pc = fn (Household $h): int => $this->forecaster()->forecast($h, $this->flatAssumptions(), $this->settings())
            ->years[0]->incomeBySource['means_tested_benefit']->pence;

        // p2 receives a disability benefit; p1 provides the care (underlying entitlement to
        // Carer's Allowance). The carer addition £48.15 lifts the £363.25 guarantee to £411.40 →
        // £5.40/wk = £280.80 a year.
        $disabledP2 = new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired, receivesDisabilityBenefit: true);
        $carerP1 = new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired, caresForPartner: true);

        // Disabled partner but NO carer flag → one disabled partner, no SDP, no carer → income
        // £406 exceeds the plain guarantee → £0.
        $this->assertSame(0, $pc($this->couple($expense, $pensions, override2: $disabledP2)));
        // Add the caring partner → the carer addition applies.
        $this->assertSame(540 * 52, $pc($this->couple($expense, $pensions, override1: $carerP1, override2: $disabledP2)));
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

    public function test_a_dc_pot_is_not_drawn_before_its_earliest_access_age(): void
    {
        // An early retiree (53 in 2026) whose only asset is a DC pot with an earliest access
        // age of 57. The £20k/yr shortfall cannot legally be met from the pension before 57
        // (normal minimum pension age), so no pension is drawn until the access year (2030,
        // age 57) — the pot must not silently fund an infeasible early-retirement plan.
        $person = new Person('p1', new DateTimeImmutable('1973-06-15'), Sex::Male, EmploymentStatus::Retired);
        $household = new Household('EarlyRetiree', RegionProfile::EnglandWalesNi, [$person],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
            [new DcPension('p1', Money::fromPounds(200_000), Money::zero(), Money::zero(), 57)],
        );

        $result = $this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings());

        $drawByYear = [];
        foreach ($result->years as $y) {
            $drawByYear[$y->calendarYear] = $y->incomeBySource['pension_drawdown']->pence;
        }

        // Below the access age (2026 age 53 … 2029 age 56): the pot is untouchable.
        $this->assertSame(0, $drawByYear[2026], 'a DC pot must not be drawn at 53');
        $this->assertSame(0, $drawByYear[2029], 'still gated at 56 — one year before access');
        // From the access age (2030, age 57): the pot funds the shortfall.
        $this->assertGreaterThan(0, $drawByYear[2030], 'the pot becomes drawable at 57');
    }

    public function test_income_sources_reconcile_to_tax_plus_spend_in_a_cgt_year(): void
    {
        // Money-in == money-out each year: income + capital drawn == tax + met spend. In a
        // GIA-disposal year the capital drawn to pay the CGT is a real withdrawal, so
        // asset_drawdown deliberately exceeds the spend-only shortfallFunded by exactly that
        // tax. This pins the identity so a future edit can't silently unbalance the ladder.
        // (Flat assumptions ⇒ real == nominal, so the reconciliation is exact to the penny.)
        $person = new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Male, EmploymentStatus::Retired);
        $household = new Household('CgtReconcile', RegionProfile::EnglandWalesNi, [$person],
            new ExpenseProfile(Money::fromPounds(40_000), Money::zero(), Percent::fromPercent(70)),
            [], // no pensions / State Pension → zero income, so all spend is drawn from the GIA
            [new Account('p1', AccountType::Gia, Money::fromPounds(500_000), unrealisedGain: Money::fromPounds(400_000))],
        );

        $year0 = $this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings())->years[0];

        // Non-vacuous: the year drew from the GIA and realised CGT (no income tax here).
        $this->assertTrue($year0->incomeBySource['asset_drawdown']->isPositive());
        $this->assertTrue($year0->totalTax->isPositive(), 'a gainful GIA disposal must realise CGT');

        // asset_drawdown includes the capital drawn to pay the CGT, so it exceeds the
        // spend-only shortfallFunded by exactly the tax.
        $this->assertSame(
            $year0->shortfallFunded->plus($year0->totalTax)->pence,
            $year0->incomeBySource['asset_drawdown']->pence,
        );

        // Full identity: income + all capital drawn == tax + met spend.
        $capitalDrawn = $year0->incomeBySource['pension_lump_sum']
            ->plus($year0->incomeBySource['pension_drawdown'])
            ->plus($year0->incomeBySource['asset_drawdown']);
        $this->assertSame(
            $year0->totalTax->plus($year0->spendTarget)->pence,
            $year0->grossIncome->plus($capitalDrawn)->pence,
        );
    }

    public function test_a_per_pot_growth_override_reaches_the_forecast(): void
    {
        // A DC growth override grows that pot at its own real rate. With flat assumptions (0%
        // blended return) an un-drawn pot stays flat by default but compounds under a +5% override.
        $make = fn (?Percent $override): Household => new Household(
            'PotGrowth', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(10_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(300, 0)), // covers spend → no draw
                new DcPension('p1', Money::fromPounds(100_000), Money::zero(), Money::zero(), 55, growthAssumptionOverride: $override),
            ],
        );

        $without = $this->forecaster()->forecast($make(null), $this->flatAssumptions(), $this->settings());
        $with = $this->forecaster()->forecast($make(Percent::fromPercent(5)), $this->flatAssumptions(), $this->settings());

        // The un-drawn pot is flat by default, but has compounded under the override by year 5.
        $this->assertSame(Money::fromPounds(100_000)->pence, $without->years[5]->pensionWealth->pence);
        $this->assertGreaterThan($without->years[5]->pensionWealth->pence, $with->years[5]->pensionWealth->pence);
    }

    public function test_a_per_property_growth_override_reaches_the_forecast(): void
    {
        // A property growth override grows the home at its own real rate (0% blended by default).
        $home = fn (?Percent $override): Property => new Property(
            currentValue: Money::fromPounds(300_000), ownership: OwnershipType::Outright,
            growthAssumptionOverride: $override,
        );
        $without = $this->forecaster()->forecast($this->homeownerCouple($home(null)), $this->flatAssumptions(), $this->settings());
        $with = $this->forecaster()->forecast($this->homeownerCouple($home(Percent::fromPercent(6))), $this->flatAssumptions(), $this->settings());

        $this->assertSame(Money::fromPounds(300_000)->pence, $without->years[5]->propertyWealth->pence);
        $this->assertGreaterThan($without->years[5]->propertyWealth->pence, $with->years[5]->propertyWealth->pence);
    }

    public function test_a_per_account_yield_override_reaches_the_forecast(): void
    {
        // A per-account yield override pays that GIA's income at its own rate. Flat assumptions
        // have a 0% default yield, so year-0 investment income is nil by default and 4% of the
        // GIA balance (£4,000 on £100k) under the override.
        $make = fn (?Percent $yield): Household => new Household(
            'GiaYield', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(10_000), Money::zero(), Percent::fromPercent(70)),
            [new StatePensionEntitlement('p1', weeklyForecast: Money::of(300, 0))],
            [new Account('p1', AccountType::Gia, Money::fromPounds(100_000), yield: $yield)],
        );

        $without = $this->forecaster()->forecast($make(null), $this->flatAssumptions(), $this->settings())->years[0];
        $with = $this->forecaster()->forecast($make(Percent::fromPercent(4)), $this->flatAssumptions(), $this->settings())->years[0];

        $this->assertSame(0, $without->incomeBySource['investment_income']->pence);
        $this->assertSame(Money::fromPounds(4_000)->pence, $with->incomeBySource['investment_income']->pence);
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

        // ...and the point of preferring capital is the credit it saves: across the plan the
        // Pension-Credit-aware order keeps strictly more Guarantee Credit than the order that
        // draws pension income, which the means test claws back £-for-£.
        $credit = function ($forecast): int {
            $total = 0;
            foreach ($forecast->years as $year) {
                $total += $year->incomeBySource['means_tested_benefit']->pence;
            }

            return $total;
        };
        $this->assertGreaterThan($credit($pensionAware), $credit($fillBands));
    }

    /**
     * The household slice #5 is judged on: a retired couple of 68 whose two full State Pensions
     * use up almost all of both personal allowances, spending £45,000 against £20,000 of cash and
     * a £600,000 pot. From year 1 every extra pound of spend has to come out of the pension and
     * into the basic-rate band, which is exactly where a tax-free quarter is worth something.
     * Rebuilt per run because a forecast consumes the household.
     */
    private function ufplsCouple(?Money $pclsTakenToDate = null): Household
    {
        return $this->couple(
            new ExpenseProfile(Money::fromPounds(45_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p1', Money::fromPounds(600_000), Money::zero(), Money::zero(), 55, pclsTakenToDate: $pclsTakenToDate),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(20_000))],
        );
    }

    private function lifetimeTax(DrawdownStrategy $strategy, Household $household): int
    {
        $total = 0;
        foreach ($this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings($strategy))->years as $year) {
            $total += $year->totalTax->pence;
        }

        return $total;
    }

    /**
     * Lifetime tax under FillBands BEFORE slice #5, when every ad-hoc pension draw was taxed on
     * 100% of the gross. Pinned so the improvement is measured against a real number rather than
     * asserted vaguely; recomputing it means reverting PathProjector, not editing this constant.
     */
    private const FILL_BANDS_LIFETIME_TAX_BEFORE_UFPLS = 10_353_810;

    public function test_a_fill_bands_pension_draw_is_taken_ufpls_style_and_pays_less_lifetime_tax(): void
    {
        // A quarter of each draw is tax-free cash while the Lump Sum Allowance lasts, so the same
        // spend is met with less taxable income than the old fully-taxable drawdown.
        $this->assertLessThan(
            self::FILL_BANDS_LIFETIME_TAX_BEFORE_UFPLS,
            $this->lifetimeTax(DrawdownStrategy::FillBands, $this->ufplsCouple()),
        );
    }

    public function test_a_fill_bands_draw_with_no_lump_sum_allowance_left_is_fully_taxable_as_before(): void
    {
        // Graceful degradation: with the whole allowance already used there is no tax-free
        // element, so the UFPLS draw IS the old fully-taxable draw, to the penny.
        $lsa = TaxYearRegistry::for('2026-27')->pension->lumpSumAllowance;

        $this->assertSame(
            self::FILL_BANDS_LIFETIME_TAX_BEFORE_UFPLS,
            $this->lifetimeTax(DrawdownStrategy::FillBands, $this->ufplsCouple($lsa)),
        );
    }

    public function test_the_tax_free_cash_a_fill_bands_plan_takes_never_exceeds_the_lump_sum_allowance(): void
    {
        // The ad-hoc UFPLS draw and an explicit instruction share ONE ledger ($state['lsaUsed']),
        // so tax-free cash already taken is gone: it cannot also fund a full 25% of every draw.
        // All but £10,000 of the allowance is used up front, which the plan exhausts in a year or
        // two - so the run must land strictly between "no allowance left" and "all of it left".
        $lsa = TaxYearRegistry::for('2026-27')->pension->lumpSumAllowance;
        $nearlyAllUsed = Money::fromPence($lsa->pence - 10_000_00);

        $withLittleLeft = $this->lifetimeTax(DrawdownStrategy::FillBands, $this->ufplsCouple($nearlyAllUsed));

        // Were the allowance not shared, this run would take a tax-free quarter for ever and
        // match the full-headroom figure instead.
        $this->assertGreaterThan($this->lifetimeTax(DrawdownStrategy::FillBands, $this->ufplsCouple()), $withLittleLeft);
        $this->assertLessThan(self::FILL_BANDS_LIFETIME_TAX_BEFORE_UFPLS, $withLittleLeft);
    }

    public function test_fill_bands_never_ufpls_draws_a_pot_before_its_owner_reaches_its_access_age(): void
    {
        // p1 is 58; the pot's earliest access age is 65, so it is legally untouchable for seven
        // years whatever order the fill planner would prefer. The 2026-07-02 access-age gate must
        // hold on the UFPLS path exactly as it does on the fully-taxable one.
        $locked = $this->couple(
            new ExpenseProfile(Money::fromPounds(30_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new DcPension('p1', Money::fromPounds(200_000), Money::zero(), Money::zero(), 65)],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(80_000))],
            override1: new Person('p1', new DateTimeImmutable('1968-04-01'), Sex::Female, EmploymentStatus::Retired),
        );

        $result = $this->forecaster()->forecast($locked, $this->flatAssumptions(), $this->settings(DrawdownStrategy::FillBands));

        // Ages 58 to 64 (year indices 0 to 6): the pot is untouched, so the shortfall falls on cash.
        foreach (range(0, 6) as $yearIndex) {
            $this->assertSame(
                Money::fromPounds(200_000)->pence,
                $result->years[$yearIndex]->pensionWealth->pence,
                "the pot was drawn at age {$result->years[$yearIndex]->ages['p1']}, before its access age of 65",
            );
        }
        // ...and once she reaches 65 it is drawn, so the gate delays the draw rather than losing it.
        $this->assertLessThan(Money::fromPounds(200_000)->pence, $result->years[7]->pensionWealth->pence);
    }

    public function test_flexible_access_caps_later_money_purchase_contributions_at_the_mpaa(): void
    {
        // A worker of 60 with a £20k employer contribution. Taking a UFPLS is flexible access, so
        // from the following year the pot may only be topped up to the Money Purchase Annual
        // Allowance: the plan cannot draw the pot down in the free bands and recycle the cash back.
        $worker = fn (): Person => new Person('p1', new DateTimeImmutable('1966-04-01'), Sex::Female,
            EmploymentStatus::Employed, grossSalary: Money::fromPounds(80_000), plannedRetirementAge: 70);
        $expense = new ExpenseProfile(Money::fromPounds(30_000), Money::zero(), Percent::fromPercent(70));
        $pot = fn (array $plan): DcPension => new DcPension('p1', Money::fromPounds(100_000),
            Money::zero(), Money::fromPounds(20_000), 55, withdrawalPlan: $plan);

        $triggered = $this->couple($expense, pensions: [
            $pot([new WithdrawalInstruction(WithdrawalKind::Ufpls, Money::fromPounds(4_000), 60)]),
        ], override1: $worker());
        $untouched = $this->couple($expense, pensions: [$pot([])], override1: $worker());

        $a = $this->forecaster()->forecast($triggered, $this->flatAssumptions(), $this->settings());
        $b = $this->forecaster()->forecast($untouched, $this->flatAssumptions(), $this->settings());

        $mpaa = TaxYearRegistry::for('2026-27')->pension->moneyPurchaseAnnualAllowance->pence;

        // The year AFTER the trigger: only the MPAA goes in, not the £20,000 the employer pays.
        $this->assertSame($mpaa, $a->years[2]->pensionWealth->pence - $a->years[1]->pensionWealth->pence);
        // ...where an untriggered member still receives the whole employer contribution.
        $this->assertSame(Money::fromPounds(20_000)->pence, $b->years[2]->pensionWealth->pence - $b->years[1]->pensionWealth->pence);
    }

    public function test_the_mpaa_caps_a_surplus_funded_contribution_in_the_trigger_year_itself(): void
    {
        // The OTHER half of the timing above, and the reason the docblock on contributionHeadroom
        // cannot say the cap simply "bites the year after". projectYear pays the employer and
        // net-pay routes before the withdrawals that set the trigger, so those escape the trigger
        // year — but applyContributions, which funds a contribution out of the year's surplus, runs
        // AFTER them, so that route is capped in the trigger year itself. Whether the cap bites
        // therefore depends on which route the money took rather than on the date, which is an
        // artefact of the year order and is carded as 0073. Pinned here so re-timing it is a
        // deliberate change to a red test, not a silent one.
        //
        // p1 is 60 in 2026 and retired, so nothing but the surplus route can pay in. £60,000 of
        // income against £12,000 of spend leaves far more surplus than the £20,000 contribution
        // asks for, so the allowance is the only thing that can hold it back.
        $expense = new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(70));
        $build = fn (array $plan): Household => $this->couple($expense,
            pensions: [new DcPension('p1', Money::fromPounds(100_000), Money::fromPounds(20_000), Money::zero(), 55, withdrawalPlan: $plan)],
            override1: new Person('p1', new DateTimeImmutable('1966-04-01'), Sex::Female, EmploymentStatus::Retired),
            incomeStreams: [new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(60_000), true, true, 0)],
        );

        $triggered = $this->forecaster()->forecast(
            $build([new WithdrawalInstruction(WithdrawalKind::Ufpls, Money::fromPounds(4_000), 60)]),
            $this->flatAssumptions(), $this->settings(),
        );
        $untouched = $this->forecaster()->forecast($build([]), $this->flatAssumptions(), $this->settings());

        $mpaa = TaxYearRegistry::for('2026-27')->pension->moneyPurchaseAnnualAllowance->pence;

        // Flat assumptions, so the pot at the end of year 0 is arithmetic: £100,000, less the
        // £4,000 taken, plus only the MPAA of the £20,000 asked for — in the trigger year itself.
        $this->assertSame(
            Money::fromPounds(96_000)->pence + $mpaa,
            $triggered->years[0]->pensionWealth->pence,
        );
        // ...where the same contribution goes in whole for a member who has not triggered it.
        $this->assertSame(Money::fromPounds(120_000)->pence, $untouched->years[0]->pensionWealth->pence);
    }

    public function test_an_ad_hoc_taxable_pension_draw_triggers_the_mpaa_too_not_only_a_ufpls(): void
    {
        // Flexible access is not only a UFPLS: taking taxable drawdown income out of a money-purchase
        // pot triggers the MPAA just the same. Under the tax-efficient order a shortfall is funded
        // straight out of the pot as taxable income, so a member still being paid into is restricted
        // from the next year. Without this only the fill-the-bands candidate carried the cap, and the
        // optimiser compared its three runs on unequal terms.
        $worker = new Person('p1', new DateTimeImmutable('1966-04-01'), Sex::Female,
            EmploymentStatus::Employed, grossSalary: Money::fromPounds(20_000), plannedRetirementAge: 70);
        $household = $this->couple(
            new ExpenseProfile(Money::fromPounds(40_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new DcPension('p1', Money::fromPounds(300_000), Money::zero(), Money::fromPounds(20_000), 55)],
            override1: $worker,
        );

        // Tax-efficient: no pot is drawn by instruction, only to meet the £20k-a-year shortfall.
        $result = $this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings());

        $mpaa = TaxYearRegistry::for('2026-27')->pension->moneyPurchaseAnnualAllowance->pence;
        $pot = fn (int $i): int => $result->years[$i]->pensionWealth->pence;
        $grew = fn (int $i): int => $pot($i) - ($i === 0 ? Money::fromPounds(300_000)->pence : $pot($i - 1));

        // Flat assumptions, flat pay and flat spend, so the draw is the same size every year and the
        // only thing that changes between year 0 and year 1 is the contribution the MPAA now blocks:
        // £20,000 goes in the year of the trigger, the MPAA every year after it.
        $this->assertSame(Money::fromPounds(20_000)->pence - $mpaa, $grew(0) - $grew(1));
        $this->assertSame($grew(1), $grew(2), 'the cap should hold, not lapse after one year');
    }

    public function test_a_fill_bands_draw_from_an_inherited_pot_takes_no_tax_free_quarter(): void
    {
        // Beneficiary drawdown carries no 25% tax-free cash — the deceased's fund is under its own
        // regime, not the heir's pension — and so it cannot spend the heir's own Lump Sum Allowance
        // either. It did: the UFPLS closure split any pot it touched, so the heir got a quarter of a
        // dead partner's pot tax-free and their own allowance paid for it. The tell is that the tax
        // then DEPENDS on the heir's headroom, so run the same plan with all of it and with none.
        //
        // p2 dies at 69 leaving a pot p2 could never touch (access age 75), so it passes whole to p1
        // as an inherited pot. p1's own pension is an empty pot that exists only to carry the
        // tax-free cash already taken, which is what seeds their allowance ledger — so the two runs
        // differ in nothing but the heir's headroom.
        $lsa = TaxYearRegistry::for('2026-27')->pension->lumpSumAllowance;
        $build = fn (?Money $heirPclsTaken): Household => $this->couple(
            new ExpenseProfile(Money::fromPounds(45_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p1', Money::zero(), Money::zero(), Money::zero(), 55, pclsTakenToDate: $heirPclsTaken),
                new DcPension('p2', Money::fromPounds(400_000), Money::zero(), Money::zero(), 75),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(20_000))],
            override2: new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male,
                EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(69)),
        );

        $withHeadroom = $this->lifetimeTax(DrawdownStrategy::FillBands, $build(null));

        $this->assertGreaterThan(0, $withHeadroom, 'the inherited pot is never drawn, so this proves nothing');
        $this->assertSame($withHeadroom, $this->lifetimeTax(DrawdownStrategy::FillBands, $build($lsa)),
            "the heir's own allowance changed the tax on an inherited pot, so it took a tax-free quarter of it");
    }

    public function test_drawing_an_inherited_pot_does_not_cap_the_heirs_own_contributions(): void
    {
        // Beneficiary drawdown is not a member trigger event. p1 is 50, still working and still
        // being paid into; p2 dies at 69 leaving a pot p2 could never touch (access age 75), so it
        // passes whole to p1 as an inherited pot with access age 0. p1's own pot is locked until 60,
        // so every ad-hoc draw in the window below comes out of the INHERITED pot. That must not
        // cost p1 their own £60,000 allowance — and certainly not before they are even 55.
        $household = $this->couple(
            new ExpenseProfile(Money::fromPounds(40_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new DcPension('p1', Money::fromPounds(100_000), Money::zero(), Money::fromPounds(20_000), 60),
                new DcPension('p2', Money::fromPounds(30_000), Money::zero(), Money::zero(), 75),
            ],
            override1: new Person('p1', new DateTimeImmutable('1976-04-01'), Sex::Female,
                EmploymentStatus::Employed, grossSalary: Money::fromPounds(20_000), plannedRetirementAge: 70),
            override2: new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male,
                EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(69)),
        );

        $result = $this->forecaster()->forecast($household, $this->flatAssumptions(), $this->settings());

        $byYear = [];
        foreach ($result->years as $y) {
            $byYear[$y->calendarYear] = $y->pensionWealth->pence;
        }

        // The inheritance really happened: p2's untouched £30,000 reaches p1 (nothing else adds to
        // pension wealth beyond the £20,000 contribution, so a year that gains more than that is
        // the transfer landing).
        // The scenario really exercises the trigger: p2's pot was untouchable while p2 lived
        // (£100k + £30k + the year's £20k in 2026), and from 2028 the shortfall is met out of the
        // inherited pot, so the pot gains less than the contribution paid in.
        $this->assertSame(Money::fromPounds(150_000)->pence, $byYear[2026], 'p2 could not touch a pot locked to 75');
        $this->assertLessThan(Money::fromPounds(20_000)->pence, $byYear[2028] - $byYear[2027],
            'the inherited pot was never drawn, so this proves nothing about the trigger');

        // Once the inherited pot is spent, pension wealth is p1's own pot alone, so each year's
        // growth IS the employer contribution. Full allowance, not the MPAA — flat assumptions, so
        // these are exact. p1 turns 60 (their own access age) in 2036, so the window ends before it.
        foreach ([2033, 2034, 2035] as $year) {
            $this->assertSame(
                Money::fromPounds(20_000)->pence,
                $byYear[$year] - $byYear[$year - 1],
                "drawing an inherited pot capped the heir's own contributions in {$year}",
            );
        }
    }

    public function test_an_employer_contribution_the_mpaa_blocks_is_not_paid_anywhere_else(): void
    {
        // Where money the cap blocks ends up depends on whose money it was, and the employer's
        // answer is "nowhere". Their contribution never passes through the household's cashflow, so
        // unlike a net-pay or surplus-funded one it cannot fall back into pay or into savings — it
        // is simply not paid, and the plan is that much poorer. contributionHeadroom's docblock and
        // the reader-facing sentence in mpaaWarnings both used to promise it landed somewhere.
        //
        // The tell: cap a £20,000 employer contribution at the £10,000 MPAA and the household must
        // end up no better off than one whose employer only ever offered £10,000.
        $worker = fn (): Person => new Person('p1', new DateTimeImmutable('1966-04-01'), Sex::Female,
            EmploymentStatus::Employed, grossSalary: Money::fromPounds(80_000), plannedRetirementAge: 70);
        $expense = new ExpenseProfile(Money::fromPounds(30_000), Money::zero(), Percent::fromPercent(70));
        $build = fn (int $employer): Household => $this->couple($expense, pensions: [
            new DcPension('p1', Money::fromPounds(100_000), Money::zero(), Money::fromPounds($employer), 55,
                withdrawalPlan: [new WithdrawalInstruction(WithdrawalKind::Ufpls, Money::fromPounds(4_000), 60)]),
        ], override1: $worker());

        $generous = $this->forecaster()->forecast($build(20_000), $this->flatAssumptions(), $this->settings());
        $capped = $this->forecaster()->forecast($build(10_000), $this->flatAssumptions(), $this->settings());

        $mpaa = TaxYearRegistry::for('2026-27')->pension->moneyPurchaseAnnualAllowance->pence;

        // Flat assumptions, so each year's pot movement IS the contribution. From the year after the
        // trigger both receive the same MPAA, so the £10,000 the cap blocked reached no pot...
        foreach ([2, 3, 4] as $y) {
            $this->assertSame($mpaa, $generous->years[$y]->pensionWealth->pence - $generous->years[$y - 1]->pensionWealth->pence);
            $this->assertSame($mpaa, $capped->years[$y]->pensionWealth->pence - $capped->years[$y - 1]->pensionWealth->pence);
            // ...and it reached no bank account either: both households hold the same cash to the
            // penny. (p1 works to 70, so no pot is drawn to fund a shortfall in this window and the
            // larger pot cannot make the two diverge for any other reason.)
            $this->assertSame($capped->years[$y]->liquidWealth->pence, $generous->years[$y]->liquidWealth->pence,
                'the employer money the MPAA blocked turned up as cash');
        }
    }

    public function test_a_pcls_crystallises_the_rest_of_the_pot_so_a_later_draw_takes_no_second_quarter(): void
    {
        // Taking £100,000 of tax-free cash crystallises £400,000 of pot: £100,000 is paid out and
        // the other £300,000 is designated to drawdown, which has had its quarter. The fill-the-bands
        // closure used to split whatever was left in the pot 25/75 all over again, bounded only by
        // the allowance ledger — so the same money paid for a tax-free quarter twice, and this card
        // put that on the optimiser's path for every scenario.
        //
        // The tell needs no magic number. Once the residue is crystallised, the tax on drawing it
        // cannot depend on how much Lump Sum Allowance the member has left — there is no quarter to
        // spend it on. So run the same plan twice: once with £168,275 of allowance still free after
        // the lump sum, and once with exactly enough for the lump sum and nothing after it.
        $lsa = TaxYearRegistry::for('2026-27')->pension->lumpSumAllowance;
        $pcls = Money::fromPounds(100_000);
        $build = fn (?Money $alreadyTaken): Household => $this->couple(
            new ExpenseProfile(Money::fromPounds(45_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p1', Money::fromPounds(400_000), Money::zero(), Money::zero(), 55,
                    withdrawalPlan: [new WithdrawalInstruction(WithdrawalKind::Pcls, $pcls, 68)],
                    pclsTakenToDate: $alreadyTaken),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(20_000))],
        );

        // Premise: the residue really is drawn later, so the runs below are measuring something.
        $run = $this->forecaster()->forecast($build(null), $this->flatAssumptions(), $this->settings(DrawdownStrategy::FillBands));
        $this->assertLessThan(
            Money::fromPounds(300_000)->pence,
            $run->years[count($run->years) - 1]->pensionWealth->pence,
            'the crystallised residue was never drawn, so this proves nothing',
        );

        $roomLeftOver = $this->lifetimeTax(DrawdownStrategy::FillBands, $build(null));
        $noRoomLeftOver = $this->lifetimeTax(DrawdownStrategy::FillBands, $build(Money::fromPence($lsa->pence - $pcls->pence)));

        $this->assertGreaterThan(0, $roomLeftOver);
        $this->assertSame($roomLeftOver, $noRoomLeftOver,
            'the tax on drawing a crystallised pot moved with the allowance, so it took a second tax-free quarter');
    }

    public function test_two_lump_sums_crystallise_as_much_as_one_of_twice_the_size(): void
    {
        // Taking £50,000 of tax-free cash twice designates the same £400,000 of pot to drawdown as
        // taking £100,000 once: each £X paid out crystallises £X / 25%. The second lump sum used to
        // be debited out of the residue the FIRST one left behind, because drawFromPot takes
        // crystallised money first — so £50,000 quietly turned uncrystallised again and the next
        // fill-the-bands draw took a quarter of it tax-free. It compounds with each further row,
        // and ScenarioBuilder lets a reader add as many rows per pension as they like.
        //
        // The tell needs no magic number: same pot, same cash, same year, same allowance. The two
        // plans differ only in how the cash was instructed, so they must pay identical tax. The
        // spend is set high enough to draw the pot right down — the leak is at the tail, because
        // crystallised money is drawn first and a plan that stops short never reaches the pence
        // that were wrongly left uncrystallised.
        $build = fn (array $plan): Household => $this->couple(
            new ExpenseProfile(Money::fromPounds(60_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                new DcPension('p1', Money::fromPounds(400_000), Money::zero(), Money::zero(), 55, withdrawalPlan: $plan),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(20_000))],
        );

        $inOneGo = $this->lifetimeTax(DrawdownStrategy::FillBands, $build([
            new WithdrawalInstruction(WithdrawalKind::Pcls, Money::fromPounds(100_000), 68),
        ]));
        $inTwoHalves = $this->lifetimeTax(DrawdownStrategy::FillBands, $build([
            new WithdrawalInstruction(WithdrawalKind::Pcls, Money::fromPounds(50_000), 68),
            new WithdrawalInstruction(WithdrawalKind::Pcls, Money::fromPounds(50_000), 68),
        ]));

        $this->assertGreaterThan(0, $inOneGo);
        $this->assertSame($inOneGo, $inTwoHalves,
            'splitting one lump sum in two left part of the pot uncrystallised, so a later draw took a second tax-free quarter');
    }

    public function test_a_draw_out_of_crystallised_money_gets_no_second_tax_free_quarter(): void
    {
        $lsa = 268_275_00;

        // Money already designated to drawdown is drawn first and is wholly taxable, so only the
        // rest of a draw is split: £2,000 crystallised out of a £4,000 draw leaves £500 tax-free.
        $this->assertSame([500_00, 3_500_00], PathProjector::ufplsSplit(4_000_00, $lsa, 0.25, 2_000_00));
        // A wholly crystallised pot is wholly taxable, whatever allowance is left — which is
        // exactly the fully-taxable draw the model has always charged.
        $this->assertSame([0, 4_000_00], PathProjector::ufplsSplit(4_000_00, $lsa, 0.25, 4_000_00));
        $this->assertSame([0, 4_000_00], PathProjector::ufplsSplit(4_000_00, $lsa, 0.25, 9_000_00));
        // Nil crystallised is the ordinary uncrystallised pot, and is the default.
        $this->assertSame(
            PathProjector::ufplsSplit(4_000_00, $lsa, 0.25),
            PathProjector::ufplsSplit(4_000_00, $lsa, 0.25, 0),
        );

        // The band being filled is spent pound for pound while the crystallised slice lasts...
        $this->assertSame(2_000_00, PathProjector::maxUfplsGross(2_000_00, $lsa, 0.25, 2_000_00));
        // ...and the ordinary two-cap solve applies to whatever room is left after it.
        $this->assertSame(6_000_00, PathProjector::maxUfplsGross(5_000_00, $lsa, 0.25, 2_000_00));

        // The solved cap still fits the room at every pence of it, crystallised slice and all.
        foreach (range(1, 400) as $room) {
            $gross = PathProjector::maxUfplsGross($room, $lsa, 0.25, 200);
            $this->assertLessThanOrEqual($room, PathProjector::ufplsSplit($gross, $lsa, 0.25, 200)[1]);
        }
    }

    public function test_the_ufpls_split_respects_the_lump_sum_allowance_and_the_band_being_filled(): void
    {
        // 25% tax-free while the allowance lasts...
        $this->assertSame([1_000_00, 3_000_00], PathProjector::ufplsSplit(4_000_00, 268_275_00, 0.25));
        // ...capped by what is left of it, so the rest of the draw is taxable...
        $this->assertSame([250_00, 3_750_00], PathProjector::ufplsSplit(4_000_00, 250_00, 0.25));
        // ...and with none left the whole draw is taxable (the pre-#5 behaviour).
        $this->assertSame([0, 4_000_00], PathProjector::ufplsSplit(4_000_00, 0, 0.25));

        // The largest draw whose TAXABLE part fits £3,000 of band: £4,000 while the allowance
        // covers the tax-free quarter...
        $this->assertSame(4_000_00, PathProjector::maxUfplsGross(3_000_00, 268_275_00, 0.25));
        // ...and once the allowance runs out, only room + what is left of the allowance.
        $this->assertSame(3_500_00, PathProjector::maxUfplsGross(3_000_00, 500_00, 0.25));
        $this->assertSame(3_000_00, PathProjector::maxUfplsGross(3_000_00, 0, 0.25));
        // No band left is no draw: 75% of any UFPLS is taxable, so nothing fits.
        $this->assertSame(0, PathProjector::maxUfplsGross(0, 268_275_00, 0.25));

        // The solved cap really does fit the room, at every pence of it (the floor in the split
        // can otherwise push the taxable part one penny over).
        foreach (range(1, 400) as $room) {
            $gross = PathProjector::maxUfplsGross($room, 268_275_00, 0.25);
            $this->assertLessThanOrEqual($room, PathProjector::ufplsSplit($gross, 268_275_00, 0.25)[1]);
        }
    }

    public function test_only_the_members_own_pot_carries_lump_sum_allowance_to_spend(): void
    {
        $lsa = 268_275_00;
        $own = ['inherited' => false];

        // An ordinary pot spends what is left of the member's own allowance...
        $this->assertSame($lsa, PathProjector::lsaHeadroom($lsa, 0, $own));
        $this->assertSame(68_275_00, PathProjector::lsaHeadroom($lsa, 200_000_00, $own));
        // ...never a negative one, so a ledger already over the allowance cannot hand a draw
        // NEGATIVE headroom, which the split would read as a bigger taxable part than the draw.
        $this->assertSame(0, PathProjector::lsaHeadroom($lsa, 300_000_00, $own));

        // An inherited pot has none, whatever the heir has left: beneficiary drawdown is not the
        // heir's pension, so there is no tax-free quarter in it and their allowance must not pay
        // for one. Both routes into the split ask this, which is the point of it being one home —
        // the ad-hoc closure knew the rule and the planned route did not.
        $this->assertSame(0, PathProjector::lsaHeadroom($lsa, 0, ['inherited' => true]));
        // A pot built before the flag existed is the member's own, not an inherited one.
        $this->assertSame($lsa, PathProjector::lsaHeadroom($lsa, 0, []));
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
