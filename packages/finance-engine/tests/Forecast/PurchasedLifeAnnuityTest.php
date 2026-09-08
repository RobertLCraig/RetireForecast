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
use RetireForecast\FinanceEngine\Pension\PurchasedLifeAnnuity;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Buying secured income with money that is NOT pension money (board card 0060).
 *
 * Before this, an annuity could only be funded from a DC pot, so the textbook case for
 * annuitising — a large essential floor, a much younger spouse, and savings held in cash or an
 * ISA rather than a pension — could not be modelled at all. These tests cover the four things
 * the card asks for: a named non-pension source, the purchased-life-annuity tax split, a
 * deferred start, and an enhanced (impaired-health) rate.
 */
final class PurchasedLifeAnnuityTest extends TestCase
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
     * essential spend — so nothing has to be drawn and the accounts are left alone unless an
     * annuity purchase takes from them.
     *
     * @param  list<Account>  $accounts
     * @param  list<DcPension>  $dcPensions
     */
    private function couple(array $accounts, array $dcPensions = []): Household
    {
        return new Household(
            'Annuity', RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
                ...$dcPensions,
            ],
            $accounts,
        );
    }

    /** A £100,000 cash account belonging to p2, optionally carrying an annuity purchase. */
    private function cashAccount(?AnnuityPurchase $annuity): Account
    {
        return new Account('p2', AccountType::Cash, Money::fromPounds(100_000), annuityPurchase: $annuity);
    }

    public function test_an_annuity_can_be_bought_from_a_named_non_pension_account(): void
    {
        // The household holds NO pension pot at all: £100,000 sits in cash. Buying an annuity with
        // it is the one intervention that removes the survivor's longevity risk, and until board
        // card 0060 the engine could not express it.
        $annuity = new AnnuityPurchase(atAge: 68, amount: Money::fromPounds(100_000), rate: Percent::fromPercent(7.2));

        $control = $this->forecaster()->forecast($this->couple([$this->cashAccount(null)]), $this->flatAssumptions(), $this->settings())->years[0];
        $bought = $this->forecaster()->forecast($this->couple([$this->cashAccount($annuity)]), $this->flatAssumptions(), $this->settings())->years[0];

        // The control account earns no annuity income and keeps its £100,000 (plus that year's
        // surplus, which both plans bank).
        $this->assertSame(0, $control->incomeBySource['other_taxable']->pence);
        $this->assertGreaterThanOrEqual(10_000_000, $control->liquidWealth->pence);

        // Bought: the cash is exchanged for £100,000 × 7.2% = £7,200 a year of income for life,
        // and the £100,000 has demonstrably left the account.
        $this->assertSame(720_000, $bought->incomeBySource['other_taxable']->pence);
        $this->assertLessThan($control->liquidWealth->pence - 9_000_000, $bought->liquidWealth->pence);
    }

    public function test_only_the_interest_element_of_a_purchased_life_annuity_is_taxed(): void
    {
        // The same £7,200 a year, bought two ways: from a DC pot (taxable in full) and from cash
        // (a purchased life annuity, where the capital element is a return of the buyer's own
        // money and is exempt). Everything else about the two households is identical.
        //
        // The pot leg commits £133,333.32 rather than £100,000, because since board card 0065 a
        // pension purchase crystallises first: a quarter comes out as a tax-free lump sum and the
        // remaining £99,999.99 is what buys the income. That is what makes the two GROSS incomes
        // equal, which is the whole basis of the comparison below.
        $fromPot = $this->couple([], [new DcPension(
            'p2', Money::fromPounds(133_334), Money::zero(), Money::zero(), 55,
            annuityPurchase: new AnnuityPurchase(68, Money::fromPence(13_333_332), Percent::fromPercent(7.2)),
        )]);
        $fromCash = $this->couple([$this->cashAccount(new AnnuityPurchase(68, Money::fromPounds(100_000), Percent::fromPercent(7.2)))]);

        $potYear = $this->forecaster()->forecast($fromPot, $this->flatAssumptions(), $this->settings())->years[0];
        $cashYear = $this->forecaster()->forecast($fromCash, $this->flatAssumptions(), $this->settings())->years[0];

        // Both pay the same gross income...
        $this->assertSame(720_000, $potYear->incomeBySource['other_taxable']->pence);
        $this->assertSame(720_000, $cashYear->incomeBySource['other_taxable']->pence);

        // ...but the purchased life annuity is taxed on its interest element only. The exempt
        // capital element is READ from the rule and the life table that own it, never restated
        // here, so re-sourcing either moves this expectation with it. p2 is a basic-rate taxpayer
        // in this year (a full State Pension plus £7,200), so the tax saved is 20% of it.
        $expectancy = (new CohortLifeTable)->lifeExpectancy(Sex::Male, 68, 2026);
        $exempt = PurchasedLifeAnnuity::capitalElementPerYear(Money::fromPounds(100_000), $expectancy, Money::fromPounds(7_200));
        $this->assertGreaterThan(0, $exempt->pence, 'a purchased life annuity must have an exempt capital element');

        $saved = $potYear->totalTax->pence - $cashYear->totalTax->pence;
        $this->assertEqualsWithDelta((int) round($exempt->pence * 0.20), $saved, 2);
    }

    public function test_a_deferred_annuity_pays_nothing_until_the_chosen_age(): void
    {
        // Bought at 68 with income deferred to 72: the money goes in 2026, the income starts 2030.
        $annuity = new AnnuityPurchase(68, Money::fromPounds(100_000), Percent::fromPercent(7.2), incomeFromAge: 72);
        $years = $this->forecaster()->forecast($this->couple([$this->cashAccount($annuity)]), $this->flatAssumptions(), $this->settings())->years;

        $income = [];
        foreach ($years as $year) {
            $income[$year->calendarYear] = $year->incomeBySource['other_taxable']->pence;
        }

        // The purchase itself happened in the base year: the whole £100,000 has left the account,
        // so all that is left liquid is the year's banked surplus...
        $this->assertLessThan(10_000_000, $years[0]->liquidWealth->pence);
        // ...while the income pays nothing until p2 reaches 72 (born September 1958, so 2030).
        $this->assertSame(0, $income[2026]);
        $this->assertSame(0, $income[2029]);
        $this->assertSame(720_000, $income[2030]);
    }

    public function test_an_enhanced_annuity_buys_more_income_at_a_disclosed_uplift(): void
    {
        $ordinary = new AnnuityPurchase(68, Money::fromPounds(100_000), Percent::fromPercent(7.2));
        $enhanced = new AnnuityPurchase(68, Money::fromPounds(100_000), Percent::fromPercent(7.2), enhanced: true);

        $plain = $this->forecaster()->forecast($this->couple([$this->cashAccount($ordinary)]), $this->flatAssumptions(), $this->settings())->years[0];
        $impaired = $this->forecaster()->forecast($this->couple([$this->cashAccount($enhanced)]), $this->flatAssumptions(), $this->settings())->years[0];

        // The uplift is READ from the constant that owns it, so re-sourcing it moves this too.
        $expected = (int) round(10_000_000 * $enhanced->effectiveRate()->asFraction());
        $this->assertSame(720_000, $plain->incomeBySource['other_taxable']->pence);
        $this->assertSame($expected, $impaired->incomeBySource['other_taxable']->pence);
        $this->assertGreaterThan($plain->incomeBySource['other_taxable']->pence, $impaired->incomeBySource['other_taxable']->pence);
    }
}
