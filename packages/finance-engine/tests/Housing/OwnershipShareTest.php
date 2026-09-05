<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\CgtHistory;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Property::ownershipShare is the household's beneficial share of a home held with others (tenants
 * in common; null = wholly owned). Before the fix it was collected and DTO-mapped but consumed by
 * no engine code — the household was treated as owning the whole home (a silent-drop). HMRC
 * apportions the value, the sale proceeds and the taxable gain by beneficial share, so these tests
 * pin that a part share reaches each: sale proceeds and CGT scale to the household's share (and the
 * sale still reconciles), and the property wealth in the forecast is only the share owned.
 */
final class OwnershipShareTest extends TestCase
{
    private function comparison(): HousingComparison
    {
        return new HousingComparison(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function household(?Percent $share, ?Money $mortgage = null, ?CgtHistory $cgt = null): Household
    {
        return new Household(
            'Share',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(20_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            primaryResidence: new Property(
                currentValue: Money::fromPounds(400_000),
                ownership: OwnershipType::Outright,
                everLet: $cgt !== null,
                outstandingMortgage: $mortgage,
                ownershipShare: $share,
                cgtHistory: $cgt,
            ),
        );
    }

    public function test_sale_proceeds_are_the_households_share_and_reconcile(): void
    {
        $action = new HousingAction(salePrice: Money::fromPounds(400_000));
        $half = $this->comparison()->saleProceeds($this->household(Percent::fromPercent(50), Money::fromPounds(100_000)), $action);
        $whole = $this->comparison()->saleProceeds($this->household(null, Money::fromPounds(100_000)), $action);

        // Whole: £400k − £100k mortgage − £16k (4% selling costs) = £284k. Owning half → each figure halved.
        $this->assertSame(Money::fromPounds(200_000)->pence, $half->salePrice->pence);
        $this->assertSame(Money::fromPounds(50_000)->pence, $half->outstandingMortgage->pence);
        $this->assertSame(Money::fromPounds(8_000)->pence, $half->sellingCosts->pence);
        $this->assertSame(Money::fromPounds(142_000)->pence, $half->netProceeds->pence, 'the household keeps its share of the proceeds');
        $this->assertSame(Money::fromPounds(284_000)->pence, $whole->netProceeds->pence);

        // The reconciliation invariant still holds on the household's share (no pence created or lost).
        $this->assertSame(
            $half->salePrice->pence,
            $half->netProceeds->pence + $half->outstandingMortgage->pence + $half->sellingCosts->pence + $half->capitalGainsTax->pence,
        );
    }

    public function test_cgt_is_charged_on_the_households_share_of_the_gain(): void
    {
        // A home let for much of ownership → partial-PRR CGT on sale. Bought £100k, sold £400k, owned
        // 20 years but the main residence for only 5, so most of the £292k gain is chargeable.
        $cgt = new CgtHistory(
            purchasePrice: Money::fromPounds(100_000),
            improvementCosts: Money::zero(),
            ownershipMonths: 240,
            mainResidenceMonths: 60,
            higherRateOnSale: true,
            owners: 1,
        );
        $action = new HousingAction(salePrice: Money::fromPounds(400_000));

        $whole = $this->comparison()->saleProceeds($this->household(null, null, $cgt), $action);
        $half = $this->comparison()->saleProceeds($this->household(Percent::fromPercent(50), null, $cgt), $action);

        // Owning half taxes half the gain, so the CGT is lower — but still reaches the forecast (not £0).
        $this->assertGreaterThan(0, $whole->capitalGainsTax->pence, 'a let former home has a chargeable gain');
        $this->assertGreaterThan(0, $half->capitalGainsTax->pence, 'the half-share still bears CGT');
        $this->assertLessThan($whole->capitalGainsTax->pence, $half->capitalGainsTax->pence, 'a smaller share taxes a smaller gain');
    }

    public function test_property_wealth_in_the_forecast_is_only_the_share_owned(): void
    {
        $flat = new AssumptionSet(
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

        $wealth = function (?Percent $share) use ($flat): int {
            $home = new Property(currentValue: Money::fromPounds(400_000), ownership: OwnershipType::Outright, ownershipShare: $share);
            $household = new Household(
                'Wealth', RegionProfile::EnglandWalesNi,
                [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
                new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(70)),
                pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(200, 0))],
                primaryResidence: $home,
            );

            return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
                ->forecast($household, $flat, new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'))
                ->years[0]->propertyWealth->pence;
        };

        $this->assertSame(Money::fromPounds(400_000)->pence, $wealth(null), 'a wholly owned home counts in full');
        $this->assertSame(Money::fromPounds(200_000)->pence, $wealth(Percent::fromPercent(50)), 'a half share counts half');
    }
}
