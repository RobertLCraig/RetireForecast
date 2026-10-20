<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
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
 * Board card 0145. A draw from a pot inherited from a death under 75 is free of income tax (card
 * 0079), but it is still INCOME for Pension Credit, which assesses income whatever its tax
 * treatment. Only the tax-free quarter of an ordinary draw is capital and kept out (card 0077).
 *
 * Both tests rest on one piece of arithmetic. A survivor on Guarantee Credit who spends more than
 * the guarantee has to draw the difference. If ALL of a draw is assessed, every pound drawn takes
 * a pound of the award, so the award cannot survive: the household ends on its State Pension plus
 * the draw and no credit. If only three quarters is assessed, a quarter of each pound drawn is
 * kept, and the award survives. So "is the award nil" tells the two treatments apart without the
 * test restating the guarantee.
 *
 * The survivor (P2, born 1955) is over State Pension age throughout, has £100 a week of State
 * Pension and nothing else. The couple spends £20,000, so their Pension Credit leaves no surplus
 * to bank, and the survivor 70% of it, £14,000, a little over the single guarantee. P1 dies at 72
 * in 2026; 2028 is a year P2 lives alone with nothing banked.
 */
final class InheritedPensionDrawPensionCreditTest extends TestCase
{
    /** Flat assumptions (no inflation, no growth), as the sibling Pension Credit tests use. */
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

    /** 2028 for the household; $potOwner holds the only DC pot. */
    private function survivorYear(string $potOwner, DrawdownStrategy $strategy): YearResult
    {
        $household = new Household(
            'Inherited draw, Pension Credit',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1954-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(72)),
                new Person('p2', new DateTimeImmutable('1955-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(95)),
            ],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(100, 0)),
                new DcPension($potOwner, Money::fromPounds(200_000), Money::zero(), Money::zero(), earliestAccessAge: 57),
            ],
        );

        $years = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flatAssumptions(), new ForecastSettings(
                baseYear: 2026,
                baseTaxYear: '2026-27',
                drawdownStrategy: $strategy,
            ))->years;
        foreach ($years as $year) {
            if ($year->calendarYear === 2028) {
                $this->assertSame(1, $year->aliveCount, 'the fixture needs P1 gone and P2 alone by 2028');

                return $year;
            }
        }
        $this->fail('the forecast must reach 2028');
    }

    /** @return list<array{0: DrawdownStrategy}> */
    public static function strategies(): array
    {
        // The two ad-hoc draw closures: TaxEfficient runs $drawPension, FillBands $drawPensionUfpls.
        return [[DrawdownStrategy::TaxEfficient], [DrawdownStrategy::FillBands]];
    }

    private function pence(YearResult $year, string $source): int
    {
        return ($year->incomeBySource[$source] ?? Money::zero())->pence;
    }

    /** Criterion #1. */
    #[DataProvider('strategies')]
    public function test_a_tax_free_inherited_pension_draw_is_assessed_for_pension_credit(DrawdownStrategy $strategy): void
    {
        $year = $this->survivorYear('p1', $strategy);

        // Premise: the survivor lives on a tax-free draw from the pot P1 left them.
        $drawn = $this->pence($year, 'inherited_pension');
        $this->assertGreaterThan(0, $drawn, 'the survivor has to draw the inherited pot to live');
        $this->assertSame(0, $this->pence($year, 'pension_drawdown'), 'and none of it is taxable (card 0079)');
        $this->assertSame(0, $year->totalTax->pence);

        // The whole draw is income for the means test, so it takes the award pound for pound and,
        // with the spending above the guarantee, takes all of it.
        $this->assertSame(0, $this->pence($year, 'means_tested_benefit'), 'a tax-free inherited draw is still assessable income for Pension Credit');

        // And the year reconciles: the State Pension and the draw alone meet the spending.
        $this->assertSame(0, $year->unmetSpend->pence);
        $this->assertSame($year->spendTarget->pence, $this->pence($year, 'state_pension') + $drawn);
    }

    /**
     * Criterion #2: the same household, but the pot is the survivor's own, so it has a quarter.
     * FillBands only: it is the order whose ad-hoc draw takes the UFPLS-style tax-free quarter.
     */
    public function test_the_tax_free_quarter_of_an_ordinary_draw_is_still_not_assessed(): void
    {
        $year = $this->survivorYear('p2', DrawdownStrategy::FillBands);

        // Premise: an ordinary draw, part tax-free cash, part taxable income.
        $taxFree = $this->pence($year, 'pension_lump_sum');
        $taxable = $this->pence($year, 'pension_drawdown');
        $this->assertGreaterThan(0, $taxFree, 'an ordinary draw carries a tax-free quarter');
        $this->assertGreaterThan(0, $taxable);
        $this->assertSame(0, $this->pence($year, 'inherited_pension'));

        // The quarter is capital, not income, so the award survives the draw.
        $award = $this->pence($year, 'means_tested_benefit');
        $this->assertGreaterThan(0, $award, 'the tax-free quarter of an ordinary draw is not assessed as income');

        $this->assertSame(0, $year->unmetSpend->pence);
        $this->assertSame($year->spendTarget->pence, $this->pence($year, 'state_pension') + $award + $taxable + $taxFree);
    }
}
