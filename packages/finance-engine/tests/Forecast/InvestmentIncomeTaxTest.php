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
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A5: a General Investment Account's income is taxed each year (its dividends as
 * dividend income), while an ISA's is not. This is the unwrapped-asset tax drag the
 * forecast previously omitted. The properties that matter for trust:
 *  - completeness — GIA dividend income reaches the forecast as taxable income (it is
 *    not silently dropped, the mirror of the DLA bug);
 *  - the wrapper matters — an equal ISA, being tax-free, pays no such tax;
 *  - no double count — the income is paid out and taxed, and the asset then grows at
 *    capital only, so income + capital growth never exceeds the total return.
 */
final class InvestmentIncomeTaxTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /** A retired couple over State Pension age with one £200k unwrapped/ISA account on p1. */
    private function couple(AccountType $accountType): Household
    {
        return new Household(
            'Investors',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
            [new Account('p1', $accountType, Money::fromPounds(200_000))],
        );
    }

    public function test_gia_dividends_are_received_as_taxable_investment_income(): void
    {
        $year0 = $this->forecaster()->forecast($this->couple(AccountType::Gia), AssumptionSetLibrary::default(), $this->settings())->years[0];

        // Default income yield is 2.0% (nominal); base year, so real == nominal: £200,000 x 2% = £4,000.
        $this->assertArrayHasKey('investment_income', $year0->incomeBySource);
        $this->assertSame(400000, $year0->incomeBySource['investment_income']->pence, 'GIA dividends must reach the forecast as income (completeness)');
    }

    public function test_an_isa_throws_off_no_taxable_investment_income(): void
    {
        $year0 = $this->forecaster()->forecast($this->couple(AccountType::Isa), AssumptionSetLibrary::default(), $this->settings())->years[0];

        // ISA income is tax-free and reinvested, so nothing is reported as taxable investment income.
        $this->assertSame(0, $year0->incomeBySource['investment_income']->pence);
    }

    public function test_the_gia_is_taxed_where_an_equal_isa_is_not(): void
    {
        $gia = $this->forecaster()->forecast($this->couple(AccountType::Gia), AssumptionSetLibrary::default(), $this->settings())->years[0];
        $isa = $this->forecaster()->forecast($this->couple(AccountType::Isa), AssumptionSetLibrary::default(), $this->settings())->years[0];

        // Same household, same balance: the only difference is the tax wrapper. The GIA's
        // £4,000 dividends (less the £500 dividend allowance) are taxed; the ISA's are not.
        $this->assertGreaterThan($isa->totalTax->pence, $gia->totalTax->pence, 'GIA dividends must be taxed where an equal ISA is not');
    }

    public function test_total_return_is_conserved_income_plus_capital_growth_never_exceeds_it(): void
    {
        // Over a long surplus run the GIA must not out-grow an ISA: the GIA pays out and is
        // taxed on its income then grows at capital only, while the ISA reinvests tax-free at
        // the full total return. So the ISA (no drag) ends with at least as much wealth — if
        // the GIA ever came out ahead, income + capital growth would exceed the total return
        // (a double count). Terminal total wealth is the clean end-to-end check.
        $gia = $this->forecaster()->forecast($this->couple(AccountType::Gia), AssumptionSetLibrary::default(), $this->settings());
        $isa = $this->forecaster()->forecast($this->couple(AccountType::Isa), AssumptionSetLibrary::default(), $this->settings());

        $this->assertLessThan($isa->terminalTotalWealth->pence, $gia->terminalTotalWealth->pence, 'the taxed, capital-only GIA cannot beat the tax-free reinvesting ISA');
    }
}
