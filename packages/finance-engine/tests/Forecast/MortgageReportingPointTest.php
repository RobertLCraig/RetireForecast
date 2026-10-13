<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\MortgageRatePeriod;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RepaymentMortgageTerms;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Property\AmortisationSchedule;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A year's row reports the mortgage owed at the END of that year, the same point its liquid and
 * pension figures are read at. The balance used to be the one the year OPENED with, so a repayment
 * row was charged the instalment's capital without being credited with the debt it cleared, and a
 * lifetime row missed the year's roll-up (board card 0103).
 *
 * The household is {@see ForcedSaleTest::movingBalanceHousehold()}'s, never sold: one person, cash
 * only, no income, a flat economy, so nominal == real and every figure is exact.
 */
final class MortgageReportingPointTest extends TestCase
{
    private function flat(): AssumptionSet
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

    private function terms(): RepaymentMortgageTerms
    {
        return new RepaymentMortgageTerms(
            termMonths: 300,
            firstPaymentYear: 2026,
            firstPaymentMonth: 1,
            ratePeriods: [new MortgageRatePeriod(Percent::fromPercent(5))],
        );
    }

    /** @return array<int, YearResult> calendarYear => year */
    private function years(?Percent $rollUpRate, ?RepaymentMortgageTerms $repaymentTerms): array
    {
        $household = new Household(
            'ReportingPoint', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(400_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds(100_000),
                mortgageRollUpRate: $rollUpRate,
                repaymentTerms: $repaymentTerms,
            ),
        );

        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        $out = [];
        foreach ($forecast->years as $year) {
            $out[$year->calendarYear] = $year;
        }

        return $out;
    }

    public function test_a_reported_year_shows_the_mortgage_balance_after_that_years_payments(): void
    {
        $years = $this->years(null, $this->terms());
        $schedule = AmortisationSchedule::for(Money::fromPounds(100_000), $this->terms());

        $this->assertLessThan(Money::fromPounds(100_000)->pence, $years[2026]->mortgageBalance()->pence,
            'the 2026 row has paid twelve instalments, so it cannot still owe the whole loan');

        // What is left after year N's instalments is what year N+1 opens owing.
        foreach (range(2026, 2035) as $year) {
            $this->assertSame(
                $schedule->openingBalanceIn($year + 1)->pence,
                $years[$year]->mortgageBalance()->pence,
                "the {$year} row must report the balance after that year's instalments",
            );
        }
    }

    public function test_a_reported_year_shows_the_rolled_up_lifetime_mortgage_balance(): void
    {
        $years = $this->years(Percent::fromPercent(6), null);

        // Well clear of the £400k cap: each row has rolled up once more than the year before,
        // starting with the base year itself.
        $expected = Money::fromPounds(100_000)->pence;
        foreach (range(2026, 2035) as $year) {
            $expected = (int) round($expected * 1.06);
            $this->assertSame($expected, $years[$year]->mortgageBalance()->pence,
                "the {$year} row must carry that year's roll-up");
        }
    }

    public function test_total_wealth_still_reconciles_to_liquid_plus_pension_plus_equity(): void
    {
        $years = $this->years(null, $this->terms());
        $schedule = AmortisationSchedule::for(Money::fromPounds(100_000), $this->terms());

        foreach (range(2026, 2035) as $year) {
            $y = $years[$year];
            // Sum of the parts is the total, and the equity is the home less the debt on the row.
            $this->assertSame($y->liquidWealth->plus($y->pensionWealth)->plus($y->homeEquity())->pence, $y->totalWealth->pence);
            $this->assertSame($y->propertyWealth->minus($y->mortgageBalance())->pence, $y->homeEquity()->pence);

            if ($year === 2026) {
                continue;
            }
            // Across a year the household's wealth falls by the cash that left it LESS the capital
            // that cash cleared off the loan in that same year, read off the schedule's own
            // payment and interest rather than its balances. A balance a year behind misses it by
            // the difference between two years' capital repayments.
            $prev = $years[$year - 1];
            $capitalRepaid = $schedule->paymentIn($year)->pence - $schedule->interestIn($year)->pence;
            $this->assertSame(
                ($prev->liquidWealth->pence - $y->liquidWealth->pence) - $capitalRepaid,
                $prev->totalWealth->pence - $y->totalWealth->pence,
                "{$year}: wealth must fall by the spending net of the capital it repaid",
            );
        }
    }
}
