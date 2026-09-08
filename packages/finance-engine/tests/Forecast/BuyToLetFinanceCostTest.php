<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\IncomeStream;
use RetireForecast\FinanceEngine\Dto\IncomeStreamType;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The buy-to-let finance-cost restriction (since April 2020): a landlord's mortgage interest is
 * not deductible from rental profit, but yields a basic-rate (20%) tax reducer on the lower of the
 * finance cost and the rental profit. The projector applies it only when the home is LET, so a
 * mortgaged let no longer taxes the rent at the full marginal rate with no relief for the interest.
 *
 * The two households differ only in the let flag, and carry enough other income (a £20k pension)
 * that the reducer never caps at the tax due — so the year-0 tax gap is exactly the credit.
 */
final class BuyToLetFinanceCostTest extends TestCase
{
    private const INTEREST = 1_617_096; // £16,170.96/yr interest-only BTL payment

    private function landlord(bool $isLet, int $rentPounds = 21_600): Household
    {
        return new Household(
            'Landlord', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(
                Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70),
                mortgageCosts: Money::fromPence(self::INTEREST),
            ),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30))],
            incomeStreams: [
                // A £20k taxable pension so the tax comfortably exceeds the credit (no cap), plus
                // the rent. Only the Rental stream is the reducer base — the pension is not rent.
                new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(20_000), taxable: true, inflationLinked: false, startAge: 0),
                new IncomeStream('p1', IncomeStreamType::Rental, Money::fromPounds($rentPounds), taxable: true, inflationLinked: false, startAge: 0),
            ],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(350_000),
                ownership: OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds(208_000),
                isLet: $isLet,
                // Every letting cost held at an explicit ZERO, so these tests stay about the
                // finance-cost reducer alone. With the shipped defaults the let twin would also
                // hold a quarter less taxable rent than the residential one, and at a basic-rate
                // marginal rate the tax that saves happens to equal the reducer itself, so a
                // reducer read off the wrong base would still land on the expected number.
                // {@see LettingCostsTest} is where the deduction itself is proved.
                lettingManagementRate: Percent::zero(),
                lettingVoidRate: Percent::zero(),
                lettingMaintenanceRate: Percent::zero(),
            ),
        );
    }

    private function year0(Household $household): YearResult
    {
        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        return $forecast->years[0];
    }

    public function test_letting_gives_a_basic_rate_credit_on_the_mortgage_interest(): void
    {
        // Rent (£21,600) exceeds the interest (£16,170.96), so the reducer base is the interest:
        // credit = 20% × £16,170.96 = £3,234.19. The only difference is the let flag.
        $residential = $this->year0($this->landlord(isLet: false));
        $let = $this->year0($this->landlord(isLet: true));

        $this->assertSame(
            (int) round(0.20 * self::INTEREST), // 323,419 pence
            $residential->totalTax->pence - $let->totalTax->pence,
            'a let property gets the 20% finance-cost reducer a residential one does not',
        );
    }

    public function test_the_reducer_base_is_the_lower_of_interest_and_rental_income(): void
    {
        // Rent £10,000 < interest £16,170.96, so the reducer base is the rent: credit = 20% × £10,000.
        $residential = $this->year0($this->landlord(isLet: false, rentPounds: 10_000));
        $let = $this->year0($this->landlord(isLet: true, rentPounds: 10_000));

        $this->assertSame(
            (int) round(0.20 * Money::fromPounds(10_000)->pence),
            $residential->totalTax->pence - $let->totalTax->pence,
        );
    }

    public function test_the_relievable_finance_cost_is_nominal_interest_not_a_cpi_inflated_figure(): void
    {
        // Interest-only interest on a fixed balance is fixed in cash terms, so the relievable
        // finance cost is the same £16,170.96 in year 10 as in year 0. Inflating it with CPI
        // overstates the credit — and here it would push the base past the £21,600 rent, so the
        // credit would be read off the rent (20% × £21,600) instead of the interest.
        $residential = $this->yearsByCalendar($this->landlord(isLet: false));
        $let = $this->yearsByCalendar($this->landlord(isLet: true));

        $this->assertSame(
            (int) round(0.20 * self::INTEREST),
            $residential[2036]->totalTax->pence - $let[2036]->totalTax->pence,
            'ten years of CPI must not change the finance-cost credit on a fixed interest-only loan',
        );
    }

    /**
     * Every year of the forecast in NOMINAL pounds, keyed by calendar year, under live 3% CPI
     * and with every source of investment income switched off — no dividend yield, and a real
     * return low enough that the nominal cash rate floors at zero. That matters: the credit is
     * cash, and cash the residential household never got would otherwise earn taxable interest,
     * so the year-10 tax gap would be the credit MINUS the tax on ten years of that interest
     * rather than the credit itself. Off, the gap is the credit exactly.
     *
     * @return array<int, YearResult>
     */
    private function yearsByCalendar(Household $household): array
    {
        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast(
                $household,
                AssumptionSetLibrary::default()
                    ->withInflationMean(Percent::fromPercent(3))
                    ->withInvestmentIncomeYield(Percent::fromPercent(0))
                    // Crush the asset returns so the invested pot cannot grow into the tax answer:
                    // this test is about the finance-cost credit, not about the market. (It used
                    // to ask for a uniform return shift; board card 0062 removed that, because a
                    // user-facing growth edit has to move the mix, not the asset classes.)
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
