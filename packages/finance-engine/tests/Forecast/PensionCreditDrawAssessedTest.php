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
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Money drawn out of a pension is assessable INCOME for Pension Credit, and the award is what
 * decides how big the shortfall that draw is covering was (board card 0077). The two are one
 * fixed point: the award moves the shortfall, the shortfall moves the draw, and the draw moves
 * the award. Before this card the projector solved them in one direction only, so a household
 * could draw thousands out of a pension and keep a credit that in life would have been taken
 * away pound for pound.
 *
 * The household below is built so the fixed point is exact to the penny and can be asserted
 * rather than approximated:
 *
 *   applicable amount   £238.00 a week x 52          = £12,376.00
 *   State Pension       £100.00 a week x 52          = £ 5,200.00
 *   spending                                          £13,000.00
 *
 * Everything it spends above the applicable amount has to come out of the pot, and only three
 * quarters of what comes out is income (the other quarter is tax-free cash, which is capital).
 * So the gross draw G solves £13,000 = £12,376.00 + G/4, giving G = £2,496.00: £624.00 of
 * tax-free cash, £1,872.00 of assessable pension income, and an award of £5,304.00. The whole
 * of the taxable income stays inside the personal allowance, so no income tax muddies it.
 */
final class PensionCreditDrawAssessedTest extends TestCase
{
    /** The 2026-27 single Standard Minimum Guarantee, £238.00 a week, annualised at 52 weeks. */
    private const APPLICABLE_ANNUAL_PENCE = 23_800 * 52;

    private const STATE_PENSION_ANNUAL_PENCE = 10_000 * 52;

    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    /** Flat assumptions (no inflation, no growth), as the sibling projector tests use. */
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

    /**
     * A single pensioner on Guarantee Credit whose only other asset is a DC pot, so every penny
     * of the shortfall has to be drawn out of a pension. Born 1958, so 68 in 2026 and over both
     * State Pension age and the pot's earliest access age from year 0. The survivor spend factor
     * is 100% because a one-person household is always "the survivor" and a discount would move
     * the spending figure the fixed point is solved against.
     */
    private function household(): Household
    {
        return new Household(
            'Test', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(13_000), Money::zero(), Percent::fromPercent(100)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(100, 0)),
                new DcPension('p1', Money::fromPounds(200_000), Money::zero(), Money::zero(), 55),
            ],
        );
    }

    /** The first projected year, drawn with the Pension-Credit-aware fill-the-bands order. */
    private function firstYear(): YearResult
    {
        return $this->forecaster()->forecast(
            $this->household(),
            $this->flatAssumptions(),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', drawdownStrategy: DrawdownStrategy::FillBands),
        )->years[0];
    }

    /**
     * The award the means test gives on an assessable income, computed the way the calculator
     * computes it: weekly, at 52 weeks, floored at nil.
     */
    private function awardOn(int $assessableAnnualPence): int
    {
        $weekly = max(0, intdiv(self::APPLICABLE_ANNUAL_PENCE, 52) - (int) round($assessableAnnualPence / 52));

        return $weekly * 52;
    }

    public function test_an_ad_hoc_pension_draw_reduces_the_pension_credit_award(): void
    {
        $year = $this->firstYear();

        // Premise: the household is on Guarantee Credit and is funding its shortfall out of the
        // pension, which is the only place it can come from.
        $this->assertGreaterThan(0, $year->incomeBySource['means_tested_benefit']->pence);
        $this->assertGreaterThan(0, $year->incomeBySource['pension_drawdown']->pence);

        // The award the household would keep if the draw were invisible to the means test: the
        // whole gap between the guarantee and the State Pension.
        $unreduced = $this->awardOn(self::STATE_PENSION_ANNUAL_PENCE);
        $this->assertSame(717_600, $unreduced);

        // What it actually keeps: the guarantee less the State Pension AND the taxable draw.
        $this->assertSame(187_200, $year->incomeBySource['pension_drawdown']->pence);
        $this->assertSame(530_400, $year->incomeBySource['means_tested_benefit']->pence);
    }

    public function test_the_tax_free_part_of_a_draw_is_not_assessed_as_income(): void
    {
        $year = $this->firstYear();

        $taxFree = $year->incomeBySource['pension_lump_sum']->pence;
        $taxable = $year->incomeBySource['pension_drawdown']->pence;
        $award = $year->incomeBySource['means_tested_benefit']->pence;

        // Premise: a quarter of the draw came out as tax-free cash, so there is something for
        // the means test to leave out.
        $this->assertGreaterThan(0, $taxFree);
        $this->assertSame(62_400, $taxFree);

        // The award is assessed on the State Pension plus the TAXABLE part only.
        $this->assertSame(
            $this->awardOn(self::STATE_PENSION_ANNUAL_PENCE + $taxable),
            $award,
        );

        // ...and that is a different figure from assessing the whole gross draw, so the test can
        // tell the two treatments apart rather than passing on either.
        $this->assertNotSame(
            $this->awardOn(self::STATE_PENSION_ANNUAL_PENCE + $taxable + $taxFree),
            $award,
        );
    }

    public function test_the_award_and_the_draw_reconcile_in_the_same_year(): void
    {
        $year = $this->firstYear();

        $award = $year->incomeBySource['means_tested_benefit']->pence;
        $taxable = $year->incomeBySource['pension_drawdown']->pence;
        $taxFree = $year->incomeBySource['pension_lump_sum']->pence;

        // The award the year PAID is the award the year's own draw earns: assess it again on the
        // income the household ended the year having, and nothing moves.
        $this->assertSame($this->awardOn(self::STATE_PENSION_ANNUAL_PENCE + $taxable), $award);

        // ...and the shortfall that draw was covering is exactly the spending that award and the
        // State Pension left unfunded. No tax is due (the whole taxable income sits inside the
        // personal allowance), so the money in and the money out balance to the penny.
        $this->assertSame(0, $year->totalTax->pence);
        $this->assertSame(0, $year->unmetSpend->pence);
        $this->assertSame(
            $year->spendTarget->pence,
            self::STATE_PENSION_ANNUAL_PENCE + $award + $taxable + $taxFree,
        );
    }
}
