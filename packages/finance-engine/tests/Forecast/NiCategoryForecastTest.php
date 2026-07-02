<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
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
 * Person::niCategory drives the employee National Insurance rate on employment earnings. Before the
 * fix it was collected and DTO-mapped but consumed by no engine code — every earner was charged the
 * standard category-A rate (a silent-drop). This proves the category reaches the forecast: the
 * reduced (B) and deferred (J) rates lower the year's National Insurance by exactly the sourced
 * band-rate difference, and a no-liability category (X) removes it entirely.
 */
final class NiCategoryForecastTest extends TestCase
{
    /** Flat assumptions (no inflation, no growth) so the year's salary and tax stay clean nominal figures. */
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

    /** Year-0 total tax (income tax + NI) for a £62,570 earner well under State Pension age, in the given NI category. */
    private function yearZeroTax(?string $niCategory): int
    {
        $household = new Household(
            'NI category',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1975-01-01'), Sex::Male, EmploymentStatus::Employed,
                grossSalary: Money::fromPounds(62_570), niCategory: $niCategory, plannedRetirementAge: 67)],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(150, 0))],
        );

        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flatAssumptions(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'))
            ->years[0]->totalTax->pence;
    }

    public function test_the_ni_category_reaches_the_forecast(): void
    {
        // Income tax is identical across categories, so the difference in year-0 total tax is exactly
        // the NI difference. On £62,570: standard NI £3,262.00, reduced (B) £943.45, deferred (J) £1,000.00.
        $standard = $this->yearZeroTax(null);
        $reduced = $this->yearZeroTax('B');
        $deferred = $this->yearZeroTax('J');
        $notLiable = $this->yearZeroTax('X');

        $this->assertSame(326_200 - 94_345, $standard - $reduced, 'category B pays the reduced NI rate');
        $this->assertSame(326_200 - 100_000, $standard - $deferred, 'category J pays the deferred NI rate');
        $this->assertSame(326_200, $standard - $notLiable, 'category X removes employee NI entirely');
    }
}
