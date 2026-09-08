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
use RetireForecast\FinanceEngine\Dto\WithdrawalInstruction;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0080. A pot the reader STARTS with can already be in drawdown: it has had its
 * tax-free quarter and every pound drawn out of it is taxed as income. The projector used to seed
 * every starting pot as wholly uncrystallised, so it handed a second quarter to money that had
 * already had one, silently, because the pot and the allowance ledger both looked right.
 *
 * The economy here is FLAT (no growth, no inflation), so every figure below is exact in pence.
 */
final class StartingCrystallisedPotTest extends TestCase
{
    private const POT_POUNDS = 200_000;

    /** The planned draw, taken at 61, one year into the plan. */
    private const DRAW_POUNDS = 40_000;

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
     * One retired basic-rate taxpayer whose other income covers the spend, so the ONLY money out
     * of the pot is the planned UFPLS at 61 and nothing ad-hoc muddies the year.
     */
    private function household(?int $crystallisedPounds): Household
    {
        return new Household(
            'Drawdown holder',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1966-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(19_000), Money::zero(), Percent::fromPercent(100)),
            pensions: [
                new DcPension(
                    'p1',
                    Money::fromPounds(self::POT_POUNDS),
                    Money::zero(),
                    Money::zero(),
                    55,
                    [new WithdrawalInstruction(WithdrawalKind::Ufpls, Money::fromPounds(self::DRAW_POUNDS), 61)],
                    crystallisedValue: $crystallisedPounds === null ? null : Money::fromPounds($crystallisedPounds),
                ),
            ],
            incomeStreams: [
                new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(27_000), true, false, 0),
            ],
        );
    }

    private function forecast(Household $household): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    /** The year the planned draw is taken. */
    private function drawYear(ForecastResult $result): YearResult
    {
        foreach ($result->years as $year) {
            if (($year->incomeBySource['pension_lump_sum']->pence + $year->incomeBySource['pension_drawdown']->pence) > 0) {
                return $year;
            }
        }

        $this->fail('no year drew on the pension');
    }

    public function test_a_draw_from_an_already_crystallised_pot_takes_no_tax_free_quarter(): void
    {
        // Wholly in drawdown: the quarter was taken years ago, so all £40,000 is taxable income
        // and none of it is tax-free cash.
        $year = $this->drawYear($this->forecast($this->household(self::POT_POUNDS)));

        $this->assertSame(0, $year->incomeBySource['pension_lump_sum']->pence, 'a crystallised pot has no tax-free cash left to pay');
        $this->assertSame(
            Money::fromPounds(self::DRAW_POUNDS)->pence,
            $year->incomeBySource['pension_drawdown']->pence,
            'every pound out of a drawdown fund is taxable income',
        );
    }

    public function test_an_uncrystallised_pot_still_gets_its_quarter(): void
    {
        // The control: the same draw out of a pot that has never been touched is a quarter
        // tax-free. Without this the test above would pass on a projector that paid no tax-free
        // cash at all.
        $year = $this->drawYear($this->forecast($this->household(0)));

        $this->assertSame(Money::fromPounds(10_000)->pence, $year->incomeBySource['pension_lump_sum']->pence);
        $this->assertSame(Money::fromPounds(30_000)->pence, $year->incomeBySource['pension_drawdown']->pence);
    }

    public function test_a_pot_the_reader_said_nothing_about_keeps_todays_behaviour(): void
    {
        // Nothing stored moves: an unanswered pot is wholly uncrystallised, exactly as before the
        // field existed. The assumption is disclosed rather than applied silently: see
        // AssumedFiguresDisclosureTest.
        $unstated = $this->drawYear($this->forecast($this->household(null)));
        $stated = $this->drawYear($this->forecast($this->household(0)));

        $this->assertSame($stated->incomeBySource['pension_lump_sum']->pence, $unstated->incomeBySource['pension_lump_sum']->pence);
        $this->assertSame($stated->incomeBySource['pension_drawdown']->pence, $unstated->incomeBySource['pension_drawdown']->pence);
    }

    public function test_a_part_crystallised_pot_gets_a_quarter_of_the_untouched_part_only(): void
    {
        // Half in drawdown. The projector draws CRYSTALLISED money first, so the whole £40,000
        // comes out of the £100,000 already in drawdown and is taxable in full.
        $year = $this->drawYear($this->forecast($this->household(100_000)));

        $this->assertSame(0, $year->incomeBySource['pension_lump_sum']->pence);
        $this->assertSame(Money::fromPounds(self::DRAW_POUNDS)->pence, $year->incomeBySource['pension_drawdown']->pence);
    }
}
