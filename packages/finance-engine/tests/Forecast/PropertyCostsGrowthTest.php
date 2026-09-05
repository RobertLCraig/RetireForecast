<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Home-ownership costs, both ways they move (card 0028).
 *
 * **Smoothly:** the property-costs bucket (service charge / ground rent / levies) carries a REAL
 * growth rate compounded per projection year, charged only while the home is owned. An EXPLICIT
 * zero keeps it flat; a BLANK rate takes {@see ExpenseProfile::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS},
 * because plain CPI is the one shape the evidence rules out.
 *
 * **In a lump:** a dated one-off cost marked `while_owning_home` (a Section 20 major-works demand)
 * is charged in the year it falls and dies with the home, so a sold flat's bill never follows the
 * household into a buy, a rent or a post-forced-sale year.
 *
 * Zero growth/inflation draws, so figures are penny-exact.
 */
final class PropertyCostsGrowthTest extends TestCase
{
    private const PROPERTY_COSTS = 300_000; // £3,000/yr of service charge in the essential spend

    /** Zero growth + zero inflation, so nominal == real and spend figures are exact. */
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

    /** @return array<int, YearResult> calendarYear => year */
    private function byYear(Household $household, ?ForecastSettings $settings = null): array
    {
        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flat(), $settings ?? new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        $out = [];
        foreach ($forecast->years as $year) {
            $out[$year->calendarYear] = $year;
        }

        return $out;
    }

    /**
     * @param  list<array{atAge: int, amount: Money, label: string, condition?: string}>  $oneOffCosts
     */
    private function household(?Percent $growth, ?Property $property = null, ?Money $propertyCosts = null, array $oneOffCosts = []): Household
    {
        return new Household(
            'PropGrowth', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(
                Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(100),
                oneOffCosts: $oneOffCosts,
                propertyCosts: $propertyCosts ?? Money::fromPence(self::PROPERTY_COSTS),
                propertyCostsRealGrowth: $growth,
            ),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(400_000))],
            primaryResidence: $property ?? new Property(currentValue: Money::fromPounds(300_000), ownership: OwnershipType::Outright),
        );
    }

    public function test_the_property_cost_bucket_compounds_above_inflation(): void
    {
        $years = $this->byYear($this->household(Percent::fromPercent(2)));

        // Year 0 charges today's figure; five years in, the £3,000 bucket has compounded at
        // 2% real while the rest of the £18,000 stays flat.
        $escalation = static fn (int $yearIndex): int => (int) round(self::PROPERTY_COSTS * (1.02 ** $yearIndex - 1.0));
        $this->assertSame(Money::fromPounds(18_000)->pence, $years[2026]->spendTarget->pence);
        $this->assertSame(Money::fromPounds(18_000)->pence + $escalation(5), $years[2031]->spendTarget->pence);
        $this->assertSame(Money::fromPounds(18_000)->pence + $escalation(10), $years[2036]->spendTarget->pence);

        // The bucket is essential, so the floor climbs with it (reconciliation: same delta).
        $this->assertSame(
            $years[2031]->spendTarget->pence - $years[2026]->spendTarget->pence,
            $years[2031]->essentialSpend->pence - $years[2026]->essentialSpend->pence,
        );
    }

    public function test_an_explicit_zero_rate_keeps_the_bucket_flat(): void
    {
        // An explicit zero is the reader SAYING their service charge tracks inflation, which beats
        // the engine's default. (Before card 0028 a null rate meant the same thing; it no longer
        // does, because a blank input now takes the disclosed default below.)
        $zero = $this->byYear($this->household(Percent::zero()));

        $this->assertSame(Money::fromPounds(18_000)->pence, $zero[2026]->spendTarget->pence);
        $this->assertSame(Money::fromPounds(18_000)->pence, $zero[2036]->spendTarget->pence, 'flat when the reader says flat');
    }

    public function test_a_blank_rate_escalates_the_bucket_at_the_engine_default(): void
    {
        // Card 0028: a service charge left with no rate used to ride plain CPI, which is the one
        // shape the evidence rules out: block insurance, building-safety compliance and the energy
        // inside a service charge have all compounded above CPI. A blank input now takes the
        // adverse-but-defensible default the profile owns, and the figure is READ from that
        // constant so the test cannot drift from what the projection charged.
        $rate = Percent::fromBasisPoints(ExpenseProfile::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS)->asFraction();
        $escalation = static fn (int $yearIndex): int => (int) round(self::PROPERTY_COSTS * ((1.0 + $rate) ** $yearIndex - 1.0));

        $years = $this->byYear($this->household(null));

        $this->assertGreaterThan(0, $escalation(1), 'the default must be ABOVE CPI, or it discloses nothing');
        $this->assertSame(Money::fromPounds(18_000)->pence, $years[2026]->spendTarget->pence, 'year 0 charges the figure as entered');
        $this->assertSame(Money::fromPounds(18_000)->pence + $escalation(10), $years[2036]->spendTarget->pence);
    }

    public function test_a_household_with_no_property_costs_gets_no_default_escalation(): void
    {
        // No noise: the default belongs to the while-owning-home bucket. A household with no
        // service charge at all must not have spend invented for it.
        $years = $this->byYear($this->household(null, propertyCosts: Money::zero()));

        $this->assertSame(Money::fromPounds(18_000)->pence, $years[2036]->spendTarget->pence);
    }

    public function test_the_escalation_stops_when_a_forced_sale_removes_the_bucket(): void
    {
        // The home is force-sold in 2030: property costs (and their escalation) stop with it,
        // leaving the ordinary spend minus the whole bucket from the sale year on.
        $years = $this->byYear(
            $this->household(Percent::fromPercent(2), new Property(
                currentValue: Money::fromPounds(300_000),
                ownership: OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds(50_000),
                mortgageRedemptionYear: 2030,
                mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
            )),
        );

        $escalation = static fn (int $yearIndex): int => (int) round(self::PROPERTY_COSTS * (1.02 ** $yearIndex - 1.0));
        $this->assertSame(Money::fromPounds(18_000)->pence + $escalation(3), $years[2029]->spendTarget->pence, 'escalating while owned');
        $this->assertSame(Money::fromPounds(15_000)->pence, $years[2031]->spendTarget->pence, 'bucket and escalation both gone after the sale');
    }

    public function test_a_major_works_cost_is_charged_in_the_year_it_falls(): void
    {
        // The lumpy half of card 0028. A Section 20 major-works demand on a block is legally
        // enforceable, cannot be deferred and lands as ONE bill: the shape of liability a thin
        // margin cannot absorb. p1 is 68 in 2026, so the age-70 demand falls in 2028.
        $works = Money::fromPounds(12_000);
        $base = $this->byYear($this->household(Percent::zero()));
        $withWorks = $this->byYear($this->household(Percent::zero(), oneOffCosts: [
            ['atAge' => 70, 'amount' => $works, 'label' => 'Section 20 major works', 'condition' => 'while_owning_home'],
        ]));

        $this->assertSame($works->pence, $withWorks[2028]->spendTarget->pence - $base[2028]->spendTarget->pence);
        $this->assertSame(0, $withWorks[2029]->spendTarget->pence - $base[2029]->spendTarget->pence, 'one bill, one year');
    }

    public function test_a_major_works_cost_is_not_charged_once_the_home_has_been_sold(): void
    {
        // A major-works demand is a liability of OWNING the flat, so it must follow the property
        // costs it belongs beside. The home is force-sold in 2030; the age-74 demand falls in 2032
        // and belongs to whoever bought the flat, not to this household.
        $forcedSale = static fn (): Property => new Property(
            currentValue: Money::fromPounds(300_000),
            ownership: OwnershipType::Mortgaged,
            outstandingMortgage: Money::fromPounds(50_000),
            mortgageRedemptionYear: 2030,
            mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
        );

        $base = $this->byYear($this->household(Percent::zero(), $forcedSale()));
        $withWorks = $this->byYear($this->household(Percent::zero(), $forcedSale(), oneOffCosts: [
            ['atAge' => 74, 'amount' => Money::fromPounds(12_000), 'label' => 'Section 20 major works', 'condition' => 'while_owning_home'],
        ]));

        $this->assertSame(0, $withWorks[2032]->spendTarget->pence - $base[2032]->spendTarget->pence);
    }

    public function test_a_sell_variant_drops_a_major_works_cost_with_the_rest_of_the_property_costs(): void
    {
        // withoutPropertyCosts is the buy/rent variants' profile. A demand on the flat they sold
        // in year 0 must go with the service charge, or the comparison charges them for a building
        // they never owned in that plan. An ordinary one-off (care, a new car) is NOT property-
        // linked and stays.
        $profile = new ExpenseProfile(
            Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(100),
            oneOffCosts: [
                ['atAge' => 74, 'amount' => Money::fromPounds(12_000), 'label' => 'Section 20 major works', 'condition' => 'while_owning_home'],
                ['atAge' => 80, 'amount' => Money::fromPounds(5_000), 'label' => 'New car'],
            ],
            propertyCosts: Money::fromPence(self::PROPERTY_COSTS),
        );

        $sold = $profile->withoutPropertyCosts();

        $this->assertSame(['New car'], array_column($sold->oneOffCosts, 'label'));
    }

    public function test_a_variant_without_property_costs_never_escalates(): void
    {
        // withoutPropertyCosts (the sell variants' profile) strips the bucket, so the growth
        // rate has nothing to grow — spend stays flat.
        $household = $this->household(Percent::fromPercent(2));
        $sold = new Household(
            'PropGrowthSold', RegionProfile::EnglandWalesNi,
            $household->persons,
            $household->expenseProfile->withoutPropertyCosts(),
            accounts: $household->accounts,
        );

        $years = $this->byYear($sold);
        $this->assertSame(Money::fromPounds(15_000)->pence, $years[2026]->spendTarget->pence);
        $this->assertSame(Money::fromPounds(15_000)->pence, $years[2036]->spendTarget->pence, 'no phantom escalation on a sold home');
    }
}
