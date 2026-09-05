<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Tax\IncomeTaxCalculator;
use RetireForecast\FinanceEngine\Tax\TaxableIncome;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0037. Savings and dividend income stack ON TOP of non-savings income, so an
 * extra pension withdrawal pushes them across band boundaries and shrinks the Personal
 * Savings Allowance. Pricing the withdrawal against non-savings income alone understates
 * the tax on it, and the household is left holding cash it would not really have.
 *
 * Two guards:
 *  - the marginal cost of a drawdown draw is charged against the person's FULL income
 *    (savings and dividends alike), not against their non-savings income only;
 *  - a year-level reconciliation — the year's reported total tax must equal a full
 *    recomputation from the FINAL taxable income, to the penny. This is the invariant
 *    that was missing, and its absence is why the mispricing was silent.
 *
 * Fixture notes, so the reconciliation is exact rather than approximately right:
 *  - one person, retired and past State Pension age, so no National Insurance;
 *  - no unrealised gain on any holding, so no Capital Gains Tax joins the year's tax;
 *  - {@see DrawdownStrategy::PensionAware}, whose pension draws are taxable in full, so
 *    the reported `pension_drawdown` IS the taxable pension income (under FillBands it is
 *    not — the tax-free quarter is filed there too, which is board card 0074);
 *  - the threshold freeze is pushed past the horizon, so the tax function the test
 *    recomputes with is the un-indexed one. Indexation is ThresholdFreezeTest's subject.
 */
final class DrawdownMarginalTaxTest extends TestCase
{
    private const HIGHER_RATE_THRESHOLD_PENCE = 5_027_000;

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(
            baseYear: 2026,
            baseTaxYear: '2026-27',
            drawdownStrategy: DrawdownStrategy::PensionAware,
            freezeEndYear: 2200,
        );
    }

    /**
     * A single retiree whose guaranteed income sits in the basic-rate band, whose spending
     * is well above it, and who holds ONE taxable account — so the year's investment income
     * is entirely savings (cash) or entirely dividends (GIA) and never a mix that the
     * reported `investment_income` total could not be split back into.
     */
    private function retiree(AccountType $type, int $balancePounds): Household
    {
        return new Household(
            'Drawdown',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1955-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(60_000), Money::fromPounds(30_000), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new DbPension('p1', Money::fromPounds(25_000), 60),
                new DcPension('p1', Money::fromPounds(700_000), Money::zero(), Money::zero(), 55),
            ],
            accounts: [new Account('p1', $type, Money::fromPounds($balancePounds))],
        );
    }

    private function forecast(AccountType $type, int $balancePounds): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable))
            ->forecast($this->retiree($type, $balancePounds), AssumptionSetLibrary::default(), $this->settings());
    }

    /** The person's non-savings taxable income for the year, in nominal pence. */
    private function nonSavings(YearResult $nominalYear): int
    {
        $src = $nominalYear->incomeBySource;

        return $src['salary']->pence
            + $src['defined_benefit']->pence
            + $src['state_pension']->pence
            + $src['other_taxable']->pence
            + $src['pension_drawdown']->pence;
    }

    private function taxOn(int $nonSavings, int $savings, int $dividends): int
    {
        return (new IncomeTaxCalculator(TaxYearRegistry::for('2026-27')))->totalPence(new TaxableIncome(
            Money::fromPence($nonSavings),
            Money::fromPence($savings),
            Money::fromPence($dividends),
        ));
    }

    public function test_a_pension_draw_is_taxed_on_top_of_savings_income(): void
    {
        $year = $this->forecast(AccountType::Cash, 120_000)->years[0]->nominal;
        $this->assertNotNull($year);

        $savings = $year->incomeBySource['investment_income']->pence;
        $drawn = $year->incomeBySource['pension_drawdown']->pence;
        $nonSavings = $this->nonSavings($year);

        // The case the card describes must actually be built: interest is earned, a pension
        // draw happens, and the draw is what carries the person over the higher-rate
        // threshold — where the Personal Savings Allowance halves and the interest above it
        // is charged at 40% instead of 20%.
        $this->assertGreaterThan(0, $savings, 'the fixture must earn savings income');
        $this->assertGreaterThan(0, $drawn, 'the fixture must draw pension to meet its spending');
        $this->assertLessThan(self::HIGHER_RATE_THRESHOLD_PENCE, $nonSavings - $drawn + $savings, 'before the draw the person must be a basic-rate taxpayer');
        $this->assertGreaterThan(self::HIGHER_RATE_THRESHOLD_PENCE, $nonSavings + $savings, 'the draw must carry the person into higher rate');

        $this->assertSame(
            $this->taxOn($nonSavings, $savings, 0),
            $year->totalTax->pence,
            'the drawdown draw must be priced against the interest stacked on top of it',
        );
    }

    public function test_a_pension_draw_is_taxed_on_top_of_dividend_income(): void
    {
        $year = $this->forecast(AccountType::Gia, 250_000)->years[0]->nominal;
        $this->assertNotNull($year);

        $dividends = $year->incomeBySource['investment_income']->pence;
        $drawn = $year->incomeBySource['pension_drawdown']->pence;
        $nonSavings = $this->nonSavings($year);

        $this->assertGreaterThan(0, $dividends, 'the fixture must earn dividend income');
        $this->assertGreaterThan(0, $drawn, 'the fixture must draw pension to meet its spending');
        $this->assertLessThan(self::HIGHER_RATE_THRESHOLD_PENCE, $nonSavings - $drawn + $dividends, 'before the draw the person must be a basic-rate taxpayer');
        $this->assertGreaterThan(self::HIGHER_RATE_THRESHOLD_PENCE, $nonSavings + $dividends, 'the draw must carry the person into higher rate');

        $this->assertSame(
            $this->taxOn($nonSavings, 0, $dividends),
            $year->totalTax->pence,
            'the drawdown draw must be priced against the dividends stacked on top of it',
        );
    }

    public function test_each_years_total_tax_reconciles_to_a_full_recomputation(): void
    {
        $result = $this->forecast(AccountType::Cash, 120_000);
        $this->assertNotEmpty($result->years);

        $yearsWithADraw = 0;
        foreach ($result->years as $year) {
            $nominal = $year->nominal;
            $this->assertNotNull($nominal);
            if ($nominal->aliveCount === 0) {
                continue;
            }

            $savings = $nominal->incomeBySource['investment_income']->pence;
            $nonSavings = $this->nonSavings($nominal);
            if ($nominal->incomeBySource['pension_drawdown']->pence > 0 && $savings > 0) {
                $yearsWithADraw++;
            }

            // The reported figure is built incrementally — a pre-drawdown pass plus a
            // marginal charge per draw. It must land on the same penny as one full
            // computation from the year's FINAL taxable income, or the increments are
            // priced against an income the person does not have.
            $this->assertSame(
                $this->taxOn($nonSavings, $savings, 0),
                $nominal->totalTax->pence,
                "the year's total tax must equal a full recomputation in {$nominal->calendarYear}",
            );
        }

        $this->assertGreaterThan(0, $yearsWithADraw, 'the reconciliation must be exercised by years that both earn interest and draw pension');
    }
}
