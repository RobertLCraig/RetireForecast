<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AnnuityPurchase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
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
 * Board card 0065, criterion #2. Annuitising a DC pot used to hand the WHOLE amount to the
 * insurer and tax every penny of the income that came back. In reality the money is crystallised
 * first: a quarter of it comes out as a tax-free lump sum and only the balance buys the annuity.
 *
 * Modelling it the old way under-rated annuitising against drawdown twice over, in the same
 * direction: the plan never saw the tax-free cash at all, and the income it did see was taxed in
 * full. Drawdown got its quarter tax free and the annuity did not, on the exact comparison this
 * tool exists to run.
 *
 * A PURCHASED LIFE ANNUITY (bought with money that is not pension money) has no lump sum to take,
 * because there is nothing to crystallise, so it must be untouched by all of this.
 */
final class AnnuityTaxFreeLumpSumTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    /** No inflation and no growth, so every figure below is exact in nominal AND real pence. */
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

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /**
     * A couple, both 68 in the 2026 base year, with two full State Pensions and £15,000 of
     * essential spend, so nothing has to be drawn and only the annuity purchase moves money.
     *
     * @param  list<DcPension>  $pensions
     * @param  list<Account>  $accounts
     */
    private function couple(array $pensions = [], array $accounts = []): Household
    {
        return new Household(
            'Annuity lump sum', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                ...$pensions,
            ],
            $accounts,
        );
    }

    /** £100,000 of pension money annuitised at 68 on a level 7.2% rate. */
    private function pensionAnnuity(): DcPension
    {
        return new DcPension(
            'p2', Money::fromPounds(100_000), Money::zero(), Money::zero(), 55,
            annuityPurchase: new AnnuityPurchase(atAge: 68, amount: Money::fromPounds(100_000), rate: Percent::fromPercent(7.2)),
        );
    }

    public function test_annuitising_a_pension_pot_pays_the_tax_free_lump_sum_before_the_balance_is_annuitised(): void
    {
        $year = $this->forecaster()->forecast($this->couple([$this->pensionAnnuity()]), $this->flatAssumptions(), $this->settings())->years[0];

        // £100,000 crystallised: £25,000 out as a tax-free lump sum...
        $this->assertSame(2_500_000, $year->incomeBySource['pension_lump_sum']->pence);
        // ...and the remaining £75,000 buys the income, at £75,000 x 7.2% = £5,400 a year.
        $this->assertSame(540_000, $year->incomeBySource['other_taxable']->pence);
        // The whole pot has left the pension either way.
        $this->assertSame(0, $year->pensionWealth->pence);
    }

    public function test_the_lump_sum_is_money_the_household_can_actually_spend(): void
    {
        // Completeness: the quarter that comes out must REACH the plan, not merely be reported.
        // The couple's State Pensions already cover the spend, so the lump sum is banked whole.
        $flat = $this->flatAssumptions();
        $control = $this->forecaster()->forecast($this->couple(), $flat, $this->settings())->years[0];
        $annuitised = $this->forecaster()->forecast($this->couple([$this->pensionAnnuity()]), $flat, $this->settings())->years[0];

        // At least the lump sum more than the control, which holds no pension at all. It is MORE
        // than exactly that because the annuity income the other three quarters bought is banked
        // the same year; the point here is only that the quarter itself arrives.
        $this->assertGreaterThanOrEqual(
            $control->liquidWealth->pence + 2_500_000,
            $annuitised->liquidWealth->pence,
            'the tax-free quarter should be banked as spendable cash',
        );
    }

    public function test_an_annuity_bought_with_money_that_is_not_pension_money_has_no_lump_sum(): void
    {
        // There is nothing to crystallise, so the whole £100,000 buys income: £7,200 a year.
        $account = new Account(
            'p2', AccountType::Cash, Money::fromPounds(100_000),
            annuityPurchase: new AnnuityPurchase(atAge: 68, amount: Money::fromPounds(100_000), rate: Percent::fromPercent(7.2)),
        );

        $year = $this->forecaster()->forecast($this->couple([], [$account]), $this->flatAssumptions(), $this->settings())->years[0];

        $this->assertSame(0, $year->incomeBySource['pension_lump_sum']->pence);
        $this->assertSame(720_000, $year->incomeBySource['other_taxable']->pence);
    }
}
