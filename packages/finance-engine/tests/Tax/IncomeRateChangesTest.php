<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Tax;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
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
 * The income rates Budget 2025 enacted for later years reach the years they apply to (board card
 * 0213): dividends from 2026-27, savings and property income from 2027-28. Before this a plan used
 * its base year's rates for its whole life.
 *
 * Source: https://www.gov.uk/government/publications/changes-to-tax-rates-for-property-savings-dividend-income/changes-to-tax-rates-for-property-savings-dividend-income
 */
final class IncomeRateChangesTest extends TestCase
{
    private function calculator(string $baseTaxYear, int $taxYearStart): IncomeTaxCalculator
    {
        return new IncomeTaxCalculator(TaxYearRegistry::ratesInForce(TaxYearRegistry::for($baseTaxYear), $taxYearStart));
    }

    public function test_savings_income_is_taxed_at_the_2027_rates_from_april_2027(): void
    {
        // £30,000 pension, £10,000 interest. Pension tax: (30,000 - 12,570) x 20% = £3,486. The
        // starting band is used up, the £1,000 basic-rate PSA is 0%, and £9,000 is taxed at 22%.
        $income = new TaxableIncome(Money::fromPounds(30_000), Money::fromPounds(10_000), Money::zero());

        $this->assertSame(Money::fromPounds(3_486 + 1_980)->pence, $this->calculator('2026-27', 2027)->totalPence($income));
    }

    public function test_savings_income_keeps_the_old_rates_before_april_2027(): void
    {
        $income = new TaxableIncome(Money::fromPounds(30_000), Money::fromPounds(10_000), Money::zero());

        $this->assertSame(Money::fromPounds(3_486 + 1_800)->pence, $this->calculator('2026-27', 2026)->totalPence($income));
    }

    public function test_property_income_is_stacked_last_and_taxed_at_the_property_rates(): void
    {
        // £40,000 of non-savings income, £15,000 of it rent profit. The allowance goes to the
        // £25,000 pension first: (25,000 - 12,570) x 20% = £2,486. The rent all sits in the
        // basic band: £15,000 x 22% = £3,300. Before April 2027 the whole lot is 20%.
        $income = new TaxableIncome(Money::fromPounds(40_000), Money::zero(), Money::zero(), Money::fromPounds(15_000));

        $this->assertSame(Money::fromPounds(2_486 + 3_300)->pence, $this->calculator('2026-27', 2027)->totalPence($income));
        $this->assertSame(Money::fromPounds(5_486)->pence, $this->calculator('2026-27', 2026)->totalPence($income));
    }

    public function test_a_2025_base_year_plan_takes_the_2026_dividend_rates(): void
    {
        // £30,000 pension, £5,000 dividends: £500 allowance, £4,500 at the ordinary rate.
        $income = new TaxableIncome(Money::fromPounds(30_000), Money::zero(), Money::fromPounds(5_000));

        $this->assertSame(
            Money::fromPounds(3_486)->pence + (int) round(450_000 * 0.1075),
            $this->calculator('2025-26', 2026)->totalPence($income),
        );
        $this->assertSame(
            Money::fromPounds(3_486)->pence + (int) round(450_000 * 0.0875),
            $this->calculator('2025-26', 2025)->totalPence($income),
        );
    }

    public function test_rental_income_is_taxed_at_the_property_rates_from_april_2027(): void
    {
        // Two households the same but for whether £15,000 a year is rent or another taxable
        // income. Every year until 2027 they pay the same tax; from 2027-28 the rent pays 2 points
        // more on all of it, because it all sits in the basic band.
        $rent = $this->yearsByCalendar(IncomeStreamType::Rental);
        $other = $this->yearsByCalendar(IncomeStreamType::Other);

        $this->assertSame(0, $rent[2026]->totalTax->pence - $other[2026]->totalTax->pence);
        foreach ([2027, 2030] as $year) {
            $this->assertSame(
                Money::fromPounds(300)->pence,
                $rent[$year]->totalTax->pence - $other[$year]->totalTax->pence,
                "the rent is taxed at 22% in {$year}",
            );
        }
    }

    /**
     * Nominal years by calendar year, with investment income switched off so cash banked from the
     * surplus earns no taxable interest (the same construction as BuyToLetFinanceCostTest).
     *
     * @return array<int, YearResult>
     */
    private function yearsByCalendar(IncomeStreamType $type): array
    {
        $household = new Household(
            'Rent', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            incomeStreams: [
                new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(20_000), taxable: true, inflationLinked: false, startAge: 0),
                new IncomeStream('p1', $type, Money::fromPounds(15_000), taxable: true, inflationLinked: false, startAge: 0),
            ],
        );

        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast(
                $household,
                AssumptionSetLibrary::default()
                    ->withInflationMean(Percent::fromPercent(3))
                    ->withInvestmentIncomeYield(Percent::fromPercent(0))
                    ->withAssetClasses(array_map(
                        static fn (AssetClassAssumption $a): AssetClassAssumption => new AssetClassAssumption(
                            $a->name,
                            Percent::fromBasisPoints($a->expectedRealReturn->basisPoints - 2000),
                            $a->volatility,
                        ),
                        AssumptionSetLibrary::default()->assetClasses,
                    )),
                new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            );

        $out = [];
        foreach ($forecast->years as $year) {
            $out[$year->calendarYear] = $year->nominal;
        }

        return $out;
    }
}
