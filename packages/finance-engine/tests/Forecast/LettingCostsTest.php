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
 * Letting a property does not earn its rent (board card 0030).
 *
 * Until this existed a let home took its rent GROSS: no agent fee, no empty weeks between
 * tenants, no repairs or safety certificates, and a service charge that was charged as the
 * household's own shopping while the whole rent was taxed as profit. On a fully managed single
 * let those costs are about a quarter of the rent before tax, which is enough to turn a modelled
 * positive contribution into a real cash loss.
 *
 * The household here is deliberately plain: one retired person, a £20,000 taxable pension so the
 * tax never floors, £24,000 of rent, and a home owned outright so no finance-cost reducer is in
 * play. Year 0 carries no inflation, so the real figures are the entered ones.
 */
final class LettingCostsTest extends TestCase
{
    private const RENT = 24_000;

    private const OTHER_INCOME = 20_000;

    private function landlord(
        bool $isLet = true,
        ?Percent $management = null,
        ?Percent $void = null,
        ?Percent $maintenance = null,
        int $serviceChargePounds = 0,
        ?int $runningCostsPounds = null,
        ?int $councilTaxPounds = null,
    ): Household {
        return new Household(
            'Landlord', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(
                Money::fromPounds(18_000), Money::zero(),
                // A one-person household is charged SURVIVOR-level spend from year 0, which would
                // scale the service charge (and so its deduction) by an incidental 70%. Held at
                // 100% so the figures below are the ones entered.
                Percent::fromPercent(100),
                // The service charge is a MARKED SUBSET of the essential floor above, never an
                // addition, so both households spend the same £18,000 and only the tax differs.
                propertyCosts: $serviceChargePounds > 0 ? Money::fromPounds($serviceChargePounds) : null,
                propertyCostsRealGrowth: Percent::zero(),
            ),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30))],
            incomeStreams: [
                new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(self::OTHER_INCOME), taxable: true, inflationLinked: false, startAge: 0),
                new IncomeStream('p1', IncomeStreamType::Rental, Money::fromPounds(self::RENT), taxable: true, inflationLinked: false, startAge: 0),
            ],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(350_000),
                ownership: OwnershipType::Outright,
                runningCosts: $runningCostsPounds === null ? null : Money::fromPounds($runningCostsPounds),
                isLet: $isLet,
                lettingManagementRate: $management,
                lettingVoidRate: $void,
                lettingMaintenanceRate: $maintenance,
                annualCouncilTax: $councilTaxPounds === null ? null : Money::fromPounds($councilTaxPounds),
            ),
        );
    }

    private function year0(Household $household): YearResult
    {
        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        return $forecast->years[0];
    }

    /** The year's taxable non-pension income: the £20,000 pension plus whatever rent survived. */
    private function otherTaxable(Household $household): int
    {
        return $this->year0($household)->incomeBySource['other_taxable']->pence;
    }

    public function test_letting_costs_come_off_the_gross_rent(): void
    {
        // The three shipped defaults are 12% management, 8% void and 5% maintenance — a quarter of
        // the rent. Read from the constants that own them, so re-sourcing a figure moves this test.
        $rate = Percent::fromBasisPoints(
            Property::DEFAULT_LETTING_MANAGEMENT_BPS
            + Property::DEFAULT_LETTING_VOID_BPS
            + Property::DEFAULT_LETTING_MAINTENANCE_BPS,
        );
        $netRent = Money::fromPounds(self::RENT)->minus(Money::fromPounds(self::RENT)->applyRate($rate));

        $this->assertSame(
            Money::fromPounds(self::OTHER_INCOME)->plus($netRent)->pence,
            $this->otherTaxable($this->landlord()),
            'a let property must be modelled on the rent that reaches the landlord, not the gross',
        );
    }

    public function test_a_home_that_is_not_let_earns_its_rent_gross(): void
    {
        // Nothing about a residence changes: the deduction hangs off the let flag alone, so every
        // stored scenario that never let its home reproduces exactly as before.
        $this->assertSame(
            Money::fromPounds(self::OTHER_INCOME + self::RENT)->pence,
            $this->otherTaxable($this->landlord(isLet: false)),
        );
    }

    public function test_the_readers_own_letting_rates_win_over_the_defaults(): void
    {
        // An explicit rate — INCLUDING an explicit zero — is the reader's own figure. Here they
        // manage the let themselves (0%) and carry a 10% void and 5% repairs, so 15% comes off.
        $household = $this->landlord(
            management: Percent::zero(),
            void: Percent::fromPercent(10),
            maintenance: Percent::fromPercent(5),
        );
        $netRent = Money::fromPounds(self::RENT)->minus(Money::fromPounds(self::RENT)->applyRate(Percent::fromPercent(15)));

        $this->assertSame(
            Money::fromPounds(self::OTHER_INCOME)->plus($netRent)->pence,
            $this->otherTaxable($household),
        );
    }

    public function test_a_let_homes_service_charge_is_a_letting_expense_not_taxed_as_profit(): void
    {
        // A leaseholder letting the flat out pays the service charge to run a building somebody
        // else lives in: it is a deductible letting expense, not the household's own shopping.
        // The cash still leaves them (it stays in the spend), but it is no longer taxed as profit.
        $without = $this->otherTaxable($this->landlord());
        $with = $this->otherTaxable($this->landlord(serviceChargePounds: 3_000));

        $this->assertSame(Money::fromPounds(3_000)->pence, $without - $with);
    }

    public function test_a_residences_service_charge_is_not_deducted_from_anything(): void
    {
        // The mirror: a home they live in has no rent to deduct it from, so the charge stays
        // exactly what it was — household spend — and the income line does not move.
        $this->assertSame(
            $this->otherTaxable($this->landlord(isLet: false)),
            $this->otherTaxable($this->landlord(isLet: false, serviceChargePounds: 3_000)),
        );
    }

    public function test_a_let_homes_running_costs_are_deducted_from_rental_profit(): void
    {
        // Card 0088. Insurance and upkeep on a home somebody else lives in are the landlord's
        // letting expenses, exactly as card 0030 found for the service charge. The cash still
        // leaves the household (it stays in the spend); what changes is that it is no longer taxed
        // as profit. The council tax is held apart, so the running costs are the landlord's alone,
        // and the repairs rate is zero so only the running costs move the figure.
        $without = $this->otherTaxable($this->landlord(maintenance: Percent::zero(), councilTaxPounds: 1_500));
        $with = $this->otherTaxable($this->landlord(maintenance: Percent::zero(), runningCostsPounds: 2_000, councilTaxPounds: 1_500));

        $this->assertSame(Money::fromPounds(2_000)->pence, $without - $with);
    }

    public function test_running_costs_that_still_hold_the_council_tax_are_not_deducted(): void
    {
        // The mirror, and the adverse answer. With no council tax of its own the bill is still
        // inside the running costs, and that bill is the household's own (it stands in for the one
        // on the home they now live in), not a landlord's expense. The forecast cannot tell the two
        // apart, so it deducts none of it rather than sheltering rent with the household's own bill.
        $this->assertSame(
            $this->otherTaxable($this->landlord(maintenance: Percent::zero())),
            $this->otherTaxable($this->landlord(maintenance: Percent::zero(), runningCostsPounds: 2_000)),
        );
    }

    public function test_repairs_are_not_charged_by_both_the_rate_and_the_running_costs(): void
    {
        // Card 0088. The repairs-and-checks rate and a home's running costs both carry the repairs,
        // so a let home entered with both paid for them twice. The reader's own running-costs
        // figure carries them, and the rate yields: only management and void come off the rent.
        $costs = Property::DEFAULT_LETTING_MANAGEMENT_BPS + Property::DEFAULT_LETTING_VOID_BPS;
        $expected = Money::fromPounds(self::OTHER_INCOME + self::RENT - 2_000)
            ->minus(Money::fromPounds(self::RENT)->applyRate(Percent::fromBasisPoints($costs)))->pence;

        $defaulted = $this->landlord(runningCostsPounds: 2_000, councilTaxPounds: 1_500);
        $this->assertSame($expected, $this->otherTaxable($defaulted), 'the default repairs rate is not charged on top of running costs');
        $this->assertArrayNotHasKey('maintenance', $defaulted->primaryResidence?->assumedLettingRates() ?? [], 'a rate that was not applied is not disclosed as applied');

        $stated = $this->landlord(maintenance: Percent::fromPercent(5), runningCostsPounds: 2_000, councilTaxPounds: 1_500);
        $this->assertSame($expected, $this->otherTaxable($stated), 'nor is a stated one: the repairs are paid once');
    }

    public function test_letting_costs_can_never_shelter_income_that_is_not_rent(): void
    {
        // Expenses above the rent are a rental LOSS, which in law is carried forward against
        // future rental profit — it cannot be set against a pension. The engine does not model
        // the carry-forward, so the year floors at nil rental profit rather than sheltering income
        // it could not shelter. A £90,000 service charge must not touch the £20,000 pension.
        $this->assertSame(
            Money::fromPounds(self::OTHER_INCOME)->pence,
            $this->otherTaxable($this->landlord(serviceChargePounds: 90_000)),
        );
    }

    public function test_the_finance_cost_reducer_is_read_off_rental_profit_not_gross_rent(): void
    {
        // The April-2020 restriction gives a basic-rate reducer on the LOWER of the finance cost
        // and the rental PROFIT. Profit used to be approximated by gross rent, so a mortgaged let
        // was over-relieved on rent it never kept.
        //
        // Two landlords whose rental PROFIT is the same £18,000 by two different routes: one lets
        // £24,000 and loses the 25% default costs, the other lets £18,000 with the costs set to
        // zero. Same profit, same taxable income, so their tax bills must match exactly. They only
        // match if the reducer reads the profit; on gross rent the first would take a credit on
        // £24,000 and pay £1,200 less tax than a landlord in an identical position.
        $mortgaged = fn (int $rent, ?Percent $rates): Household => new Household(
            'Landlord', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(
                Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70),
                mortgageCosts: Money::fromPounds(30_000), // interest above the rent, so the base is the profit
            ),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30))],
            incomeStreams: [
                new IncomeStream('p1', IncomeStreamType::Other, Money::fromPounds(60_000), taxable: true, inflationLinked: false, startAge: 0),
                new IncomeStream('p1', IncomeStreamType::Rental, Money::fromPounds($rent), taxable: true, inflationLinked: false, startAge: 0),
            ],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(350_000),
                ownership: OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds(300_000),
                isLet: true,
                lettingManagementRate: $rates,
                lettingVoidRate: $rates,
                lettingMaintenanceRate: $rates,
            ),
        );

        $grossing24k = $this->year0($mortgaged(24_000, null));   // 25% comes off => £18,000 profit
        $grossing18k = $this->year0($mortgaged(18_000, Percent::zero())); // already net => £18,000 profit

        $this->assertSame($grossing18k->totalTax->pence, $grossing24k->totalTax->pence);
    }
}
