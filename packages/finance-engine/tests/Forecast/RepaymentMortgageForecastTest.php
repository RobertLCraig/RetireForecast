<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\MortgageRatePeriod;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Dto\RepaymentMortgageTerms;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Property\AmortisationSchedule;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * An ordinary capital-and-interest mortgage in the forecast: the balance AMORTISES to zero over
 * the term and the fixed-nominal instalment is charged as essential spend until it does.
 *
 * The completeness rule for this input ({@see RepaymentMortgageTerms}):
 * a repayment mortgage must reach the result on BOTH legs. The balance leg must fall (or net
 * wealth and the IHT estate are understated by every pound of capital repaid), and the payment
 * leg must be charged (or the plan looks affordable when it is not). Neither is allowed to be
 * silently dropped, and the two must never double-count against the "Mortgage" expense line.
 */
final class RepaymentMortgageForecastTest extends TestCase
{
    /** Zero inflation and zero house growth, so nominal == real and every figure is exact. */
    private function flatEconomy()
    {
        return AssumptionSetLibrary::default()
            ->withInflationMean(Percent::fromPercent(0))
            ->withHouseGrowth(Percent::fromPercent(0));
    }

    /** The ESIS quote's terms: £160,000 over 16 years, 6.23% for 60 months then 7.24%. */
    private function esisTerms(): RepaymentMortgageTerms
    {
        return new RepaymentMortgageTerms(
            termMonths: 192,
            firstPaymentYear: 2026,
            firstPaymentMonth: 9,
            ratePeriods: [
                new MortgageRatePeriod(Percent::fromPercent(6.23), 60),
                new MortgageRatePeriod(Percent::fromPercent(7.24)),
            ],
        );
    }

    /**
     * A couple with a £400,000 home carrying a £160,000 mortgage, and enough assets that nothing
     * depletes to disturb the picture. $terms null = the pre-existing static-balance behaviour.
     * $mortgageLine is the "Mortgage" expense line, which an amortising loan must supersede.
     */
    private function couple(?RepaymentMortgageTerms $terms, int $mortgageLine = 0): Household
    {
        return new Household(
            'Repayment mortgage',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(80)),   // dies 2035
                new Person('p2', new DateTimeImmutable('1957-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(90)), // dies 2047 (final)
            ],
            new ExpenseProfile(
                Money::fromPounds(18_000 + $mortgageLine),
                Money::zero(),
                Percent::fromPercent(70),
                mortgageCosts: $mortgageLine > 0 ? Money::fromPounds($mortgageLine) : null,
            ),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
            ],
            accounts: [
                new Account('p1', AccountType::Cash, Money::fromPounds(500_000)),
                new Account('p2', AccountType::Cash, Money::fromPounds(500_000)),
            ],
            primaryResidence: new Property(
                Money::fromPounds(400_000),
                OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds(160_000),
                repaymentTerms: $terms,
            ),
            relationshipStatus: RelationshipStatus::MarriedOrCivilPartnership,
        );
    }

    private function forecast(Household $h, bool $modelIht = false): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, $this->flatEconomy(), new ForecastSettings(
                baseYear: 2026,
                baseTaxYear: '2026-27',
                modelIht: $modelIht,
                homeToDescendants: true,
            ));
    }

    /** @return array<int, int> calendar year => reported mortgage balance in pence */
    private function balancesByYear(ForecastResult $result): array
    {
        $out = [];
        foreach ($result->years as $year) {
            $out[$year->calendarYear] = $year->mortgageBalance()->pence;
        }

        return $out;
    }

    public function test_the_reported_balance_follows_the_amortisation_schedule_to_the_penny(): void
    {
        $balances = $this->balancesByYear($this->forecast($this->couple($this->esisTerms())));
        $schedule = AmortisationSchedule::for(Money::fromPounds(160_000), $this->esisTerms());

        // Zero inflation, so the reported real balance is the schedule's nominal balance exactly.
        foreach (range(2026, 2043) as $year) {
            $this->assertArrayHasKey($year, $balances);
            $this->assertSame(
                $schedule->openingBalanceIn($year)->pence,
                $balances[$year],
                "the {$year} balance must be the schedule's opening balance",
            );
        }
    }

    public function test_the_balance_reaches_zero_when_the_term_ends_and_stays_there(): void
    {
        $balances = $this->balancesByYear($this->forecast($this->couple($this->esisTerms())));

        $this->assertSame(16_000_000, $balances[2026], 'the plan opens owing the whole loan');
        $this->assertGreaterThan(0, $balances[2042], 'the final year of the term still owes something');
        $this->assertSame(0, $balances[2043], 'the loan is cleared once the term has run');

        foreach ($balances as $year => $balance) {
            if ($year > 2043) {
                $this->assertSame(0, $balance, "nothing is owed in {$year}");
            }
        }
    }

    public function test_a_static_mortgage_is_untouched_by_the_change(): void
    {
        // No repayment terms = the pre-existing shape: the balance never moves. This is the
        // back-compatibility guarantee — every stored scenario without terms is byte-identical.
        $balances = $this->balancesByYear($this->forecast($this->couple(null)));

        foreach ($balances as $year => $balance) {
            $this->assertSame(16_000_000, $balance, "a static mortgage balance never changes ({$year})");
        }
    }

    public function test_the_instalment_is_charged_as_essential_spend(): void
    {
        $withLoan = $this->forecast($this->couple($this->esisTerms()));
        $without = $this->forecast($this->couple(null));

        $schedule = AmortisationSchedule::for(Money::fromPounds(160_000), $this->esisTerms());

        // The only difference between the two households is the amortising loan, so the extra
        // essential spend in each year must be exactly that year's instalments.
        $byYear = [];
        foreach ($without->years as $year) {
            $byYear[$year->calendarYear] = $year->essentialSpend->pence;
        }

        foreach ($withLoan->years as $year) {
            $expected = $byYear[$year->calendarYear] + $schedule->paymentIn($year->calendarYear)->pence;
            $this->assertSame(
                $expected,
                $year->essentialSpend->pence,
                "the {$year->calendarYear} essential floor must include that year's instalments",
            );
        }

        // ...and it is a real cost: 2026 carries four instalments, a full year carries twelve.
        $this->assertSame(4 * 131_854, $schedule->paymentIn(2026)->pence);
    }

    public function test_the_instalment_is_fixed_nominal_and_does_not_rise_with_inflation(): void
    {
        // With inflation ON, ordinary spend rises with CPI but the instalment does not — so in
        // today's money the mortgage gets CHEAPER every year. This is the whole reason a payment
        // cannot be modelled as an expense line, which the projector re-inflates annually.
        $result = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast(
                $this->couple($this->esisTerms()),
                AssumptionSetLibrary::default()->withInflationMean(Percent::fromPercent(3)),
                new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            );

        $baseline = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast(
                $this->couple(null),
                AssumptionSetLibrary::default()->withInflationMean(Percent::fromPercent(3)),
                new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            );

        $baselineByYear = [];
        foreach ($baseline->years as $year) {
            $baselineByYear[$year->calendarYear] = $year->essentialSpend->pence;
        }

        // The real-terms cost of the mortgage, year by year.
        $realCost = [];
        foreach ($result->years as $year) {
            $cost = $year->essentialSpend->pence - $baselineByYear[$year->calendarYear];
            if ($year->calendarYear >= 2027 && $year->calendarYear <= 2041) {
                $realCost[$year->calendarYear] = $cost;
            }
        }

        // Every full year of the term costs strictly less in today's money than the year before
        // (bar the 2031 step up when the fixed deal reverts to the higher variable rate).
        foreach ($realCost as $year => $cost) {
            if (! isset($realCost[$year + 1]) || $year + 1 === 2031 || $year + 1 === 2032) {
                continue;
            }
            $this->assertLessThan($cost, $realCost[$year + 1], 'the real cost of the instalment must fall into '.($year + 1));
        }

        $this->assertLessThan(
            $realCost[2027] * 0.8,
            $realCost[2041],
            'after 15 years of 3% inflation the instalment should cost well under 80% of its opening real value',
        );
    }

    public function test_the_survivor_still_owes_the_lender_the_full_instalment(): void
    {
        // p1 dies in 2035. Ordinary household spend drops to the 70% survivor factor, but the
        // mortgage does not: a lender does not halve the instalment because a borrower died.
        $withLoan = $this->forecast($this->couple($this->esisTerms()));
        $without = $this->forecast($this->couple(null));

        $schedule = AmortisationSchedule::for(Money::fromPounds(160_000), $this->esisTerms());

        $byYear = [];
        foreach ($without->years as $year) {
            $byYear[$year->calendarYear] = [$year->essentialSpend->pence, $year->aliveCount];
        }

        foreach ($withLoan->years as $year) {
            if ($year->aliveCount !== 1 || $year->calendarYear > 2042) {
                continue;
            }
            [$baseSpend] = $byYear[$year->calendarYear];
            $this->assertSame(
                $baseSpend + $schedule->paymentIn($year->calendarYear)->pence,
                $year->essentialSpend->pence,
                "the survivor's {$year->calendarYear} instalment is not reduced by the survivor factor",
            );
        }

        // Guard the premise: there really are survivor-only years inside the term.
        $survivorYears = array_filter(
            $withLoan->years,
            static fn ($y) => $y->aliveCount === 1 && $y->calendarYear <= 2042,
        );
        $this->assertNotEmpty($survivorYears, 'the fixture must have survivor years inside the mortgage term');
    }

    public function test_the_schedule_supersedes_the_mortgage_expense_line_rather_than_doubling_it(): void
    {
        // A scenario converted from an interest-only shape may still carry its old "Mortgage"
        // expense line. The schedule owns the payment, so the line must be dropped, not added.
        $withStaleLine = $this->forecast($this->couple($this->esisTerms(), mortgageLine: 7_080));
        $clean = $this->forecast($this->couple($this->esisTerms()));

        foreach ($withStaleLine->years as $i => $year) {
            $this->assertSame(
                $clean->years[$i]->essentialSpend->pence,
                $year->essentialSpend->pence,
                "a stale Mortgage expense line must not be charged on top in {$year->calendarYear}",
            );
        }
    }

    public function test_repaying_the_capital_lifts_net_wealth_above_an_interest_only_loan(): void
    {
        // The point of a repayment mortgage: by the end of the term the debt is gone, so the
        // household's net worth is higher than an identical interest-only borrower's by the whole
        // loan — the understatement the static-balance model used to carry.
        $repayment = $this->forecast($this->couple($this->esisTerms()));
        $interestOnly = $this->forecast($this->couple(null));

        $repaymentByYear = [];
        foreach ($repayment->years as $year) {
            $repaymentByYear[$year->calendarYear] = $year->homeEquity()->pence;
        }

        foreach ($interestOnly->years as $year) {
            if ($year->calendarYear < 2027) {
                continue;
            }
            $this->assertGreaterThan(
                $year->homeEquity()->pence,
                $repaymentByYear[$year->calendarYear],
                "repaying capital must leave more equity by {$year->calendarYear}",
            );
        }

        // Once the term has run, the home is owned outright: equity == the whole house value.
        $this->assertSame(40_000_000, $repaymentByYear[2043], 'the home is unencumbered after the term');
    }

    public function test_a_loan_cannot_both_amortise_and_roll_up(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Property(
            Money::fromPounds(400_000),
            OwnershipType::Mortgaged,
            outstandingMortgage: Money::fromPounds(160_000),
            mortgageRollUpRate: Percent::fromPercent(6.5),
            repaymentTerms: $this->esisTerms(),
        );
    }
}
