<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
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
}
