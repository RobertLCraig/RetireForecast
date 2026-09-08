<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Spendable wealth ("how much you would have left") adds the pension pot to the cash and
 * investments. A pension pot is NOT spendable at face value: three quarters of it is taxable on
 * the way out. Board card 0076.
 *
 * The figure is not decoration — it is what the safety-buffer warning is measured against and the
 * terminal wealth the buy / rent / stay-put plans are ranked on — so overstating it fires the
 * money-is-thin warning late and favours whichever plan ends with more of its wealth inside a
 * pension.
 *
 * The economy here is FLAT (no growth, no inflation, no charges), so every figure below is exact
 * in nominal AND real pence and the pot at the end is the pot that was entered.
 */
final class SpendableWealthNetOfPensionTaxTest extends TestCase
{
    /** A basic-rate income: £27,000 gross keeps the marginal rate at 20% for the whole plan. */
    private const INCOME_POUNDS = 27_000;

    private const POT_POUNDS = 200_000;

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

    /**
     * One retired basic-rate taxpayer whose income covers the spend, so the pot is never drawn on
     * and reaches the end of the plan whole. $pclsTakenPounds is Lump Sum Allowance already spent.
     */
    private function household(?int $pclsTakenPounds = null): Household
    {
        return new Household(
            'Pot holder',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(19_000), Money::zero(), Percent::fromPercent(100)),
            pensions: [
                new DcPension(
                    'p1',
                    Money::fromPounds(self::POT_POUNDS),
                    Money::zero(),
                    Money::zero(),
                    55,
                    pclsTakenToDate: $pclsTakenPounds === null ? null : Money::fromPounds($pclsTakenPounds),
                ),
            ],
            incomeStreams: [
                new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(self::INCOME_POUNDS), true, false, 60),
            ],
        );
    }

    private function forecast(Household $household): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_spendable_wealth_is_net_of_tax_on_the_pension_part(): void
    {
        $result = $this->forecast($this->household());
        $terminal = $result->years[array_key_last($result->years)];

        // The pot is untouched: nothing was planned out of it and the income met the spend.
        $this->assertSame(Money::fromPounds(self::POT_POUNDS)->pence, $terminal->pensionWealth->pence);

        // £200,000 pot: £50,000 tax-free, £150,000 taxable at the 20% marginal rate the
        // projected income puts this person in — £30,000 of tax, so about £170,000 in the hand.
        $tax = Money::fromPounds(30_000)->pence;

        $this->assertSame(
            $terminal->liquidWealth->pence + $terminal->pensionWealth->pence - $tax,
            $result->terminalUsableWealth->pence,
            'spendable wealth must be net of the tax due on the pension part',
        );
        $this->assertSame($tax, $terminal->pensionTaxIfDrawn()->pence);
        $this->assertSame($terminal->usableWealth()->pence, $result->terminalUsableWealth->pence);
    }

    public function test_the_tax_free_quarter_is_not_netted(): void
    {
        $lsa = TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi)->pension->lumpSumAllowance;

        // Nothing of the allowance spent: a quarter of the pot comes out tax-free, so the tax
        // netted is strictly less than taxing the whole pot would be.
        $fresh = $this->forecast($this->household());
        $freshTerminal = $fresh->years[array_key_last($fresh->years)];
        $this->assertSame(
            Money::fromPounds(self::POT_POUNDS - 50_000)->applyRate(Percent::fromPercent(20))->pence,
            $freshTerminal->pensionTaxIfDrawn()->pence,
            'the tax-free quarter must not be netted',
        );
        $this->assertLessThan(
            $freshTerminal->pensionWealth->applyRate(Percent::fromPercent(20))->pence,
            $freshTerminal->pensionTaxIfDrawn()->pence,
        );

        // The quarter is capped by what is LEFT of the Lump Sum Allowance. With £250,000 of it
        // already spent only £18,275 of this pot can come out tax-free, not £50,000.
        $spent = $this->forecast($this->household(pclsTakenPounds: 250_000));
        $spentTerminal = $spent->years[array_key_last($spent->years)];
        $headroom = $lsa->pence - Money::fromPounds(250_000)->pence;
        $this->assertSame(1_827_500, $headroom, 'the 2026-27 Lump Sum Allowance leaves £18,275');
        $this->assertSame(
            Money::fromPence(Money::fromPounds(self::POT_POUNDS)->pence - $headroom)->applyRate(Percent::fromPercent(20))->pence,
            $spentTerminal->pensionTaxIfDrawn()->pence,
            'only the allowance still left may come out tax-free',
        );
    }

    /** A guard against the flat economy quietly stopping being flat. */
    public function test_the_fixture_leaves_the_pot_alone(): void
    {
        $result = $this->forecast($this->household());
        foreach ($result->years as $year) {
            $this->assertSame(
                Money::fromPounds(self::POT_POUNDS)->pence,
                $year->pensionWealth->pence,
                "the pot must be untouched in {$year->calendarYear}",
            );
        }
    }
}
