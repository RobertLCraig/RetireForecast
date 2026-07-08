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
 * Above-CPI growth of the home-ownership cost bucket (service charge / ground rent /
 * levies): the property-costs marker carries an optional REAL growth rate, compounded per
 * projection year, charged only while the home is owned. Zero growth is byte-identical to
 * the pre-feature engine; the escalation follows the bucket when a sale strips it. Zero
 * growth/inflation draws, so figures are penny-exact.
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

    private function household(?Percent $growth, ?Property $property = null): Household
    {
        return new Household(
            'PropGrowth', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(
                Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(100),
                propertyCosts: Money::fromPence(self::PROPERTY_COSTS),
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

    public function test_zero_growth_is_byte_identical_to_no_growth(): void
    {
        $none = $this->byYear($this->household(null));
        $zero = $this->byYear($this->household(Percent::zero()));

        foreach ($none as $calendarYear => $year) {
            $this->assertSame($year->spendTarget->pence, $zero[$calendarYear]->spendTarget->pence);
            $this->assertSame($year->liquidWealth->pence, $zero[$calendarYear]->liquidWealth->pence);
        }
        $this->assertSame(Money::fromPounds(18_000)->pence, $none[2036]->spendTarget->pence, 'flat without the lever');
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
