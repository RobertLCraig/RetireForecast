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
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The year's CAPITAL growth (share/fund appreciation left in the pots) is surfaced on
 * {@see \RetireForecast\FinanceEngine\Forecast\YearResult::investmentGrowth} so the cashflow
 * ladder can show where wealth grows beyond the interest/dividends paid out as income. The
 * discriminating property: an invested pot (ISA) shows real capital growth, while cash of the
 * same size shows ~none — its return is paid out as taxable interest income instead, not left
 * in the pot as capital. That is the "where the gains come from" split the results page needs.
 */
final class InvestmentGrowthTest extends TestCase
{
    private function forecast(Account $account): ForecastResult
    {
        $household = new Household(
            'Growth',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1961-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(8_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(200, 0))],
            accounts: [$account],
        );

        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_an_invested_pot_shows_real_capital_growth(): void
    {
        // £100k in an ISA (grows at the full return, tax-free) — its first-year real capital
        // growth is positive and a plausible fraction of the balance (a few % real), never zero.
        $growth = $this->forecast(new Account('p1', AccountType::Isa, Money::fromPounds(100_000)))
            ->years[0]->investmentGrowth();

        $this->assertGreaterThan(Money::fromPounds(1_000)->pence, $growth->pence, 'an invested ISA should show real capital growth');
        $this->assertLessThan(Money::fromPounds(12_000)->pence, $growth->pence, 'but a sane few-% real, not a runaway figure');
    }

    public function test_cash_shows_almost_no_capital_growth_its_return_is_paid_out_as_income(): void
    {
        // The SAME £100k in cash grows barely at all as capital — its return comes out as
        // interest income (taxed each year), not appreciation left in the pot. So it must not
        // be double-counted as investment growth.
        $cashGrowth = $this->forecast(new Account('p1', AccountType::Cash, Money::fromPounds(100_000)))
            ->years[0]->investmentGrowth();
        $isaGrowth = $this->forecast(new Account('p1', AccountType::Isa, Money::fromPounds(100_000)))
            ->years[0]->investmentGrowth();

        $this->assertLessThan(Money::fromPounds(500)->pence, abs($cashGrowth->pence), 'cash return is income, not capital growth');
        $this->assertGreaterThan($cashGrowth->pence + Money::fromPounds(1_000)->pence, $isaGrowth->pence, 'the ISA grows as capital where cash does not');
    }
}
