<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Housing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Housing\HousingProceeds;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0059 criterion #2, the half the first build missed: the site owner's commission is
 * paid when a park home is actually SOLD, so it has to come off every modelled sale, not only off
 * the value at death and in the care means test. Both sale paths go through
 * {@see HousingProceeds::compute()}: the year-0 sell variants ({@see HousingComparison::saleProceeds})
 * and the in-projection forced sale.
 */
final class ChattelDwellingSaleTest extends TestCase
{
    private const HOME = 400_000;

    /** The commission on the whole price, READ from the constant that owns the rate. */
    private function commission(): int
    {
        return Money::fromPounds(self::HOME)->applyRate(Percent::fromBasisPoints(Property::MAX_SITE_COMMISSION_BPS))->pence;
    }

    private function household(bool $chattel, MortgageMaturityAction $action = MortgageMaturityAction::Refinance): Household
    {
        // Bare on purpose: one person, cash only, no income and no home costs, so nothing but the
        // sale differs between a park home and a brick house.
        return new Household(
            'Pitch', RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Male, EmploymentStatus::Retired)],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(400_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(self::HOME),
                ownership: OwnershipType::Outright,
                mortgageRedemptionYear: 2030,
                mortgageMaturityAction: $action,
                isChattelDwelling: $chattel,
            ),
        );
    }

    /** A year-0 sale of a park home pays the commission, itemised, and the sale still reconciles. */
    public function test_selling_a_park_home_pays_the_site_owners_commission(): void
    {
        $comparison = new HousingComparison(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
        $action = new HousingAction(salePrice: Money::fromPounds(self::HOME));

        $house = $comparison->saleProceeds($this->household(chattel: false), $action);
        $parkHome = $comparison->saleProceeds($this->household(chattel: true), $action);

        $this->assertSame(
            $this->commission(),
            $house->netProceeds->pence - $parkHome->netProceeds->pence,
            'a park home sold at year 0 must keep the price less the site owner\'s commission',
        );

        $labels = array_column($parkHome->sellingCostBreakdown, 'label');
        $this->assertContains(HousingProceeds::SITE_COMMISSION_LABEL, $labels, 'the commission is itemised, not folded into the agent');
        $this->assertNotContains(HousingProceeds::SITE_COMMISSION_LABEL, array_column($house->sellingCostBreakdown, 'label'));

        $this->assertSame(
            $parkHome->salePrice->pence,
            $parkHome->netProceeds->pence + $parkHome->outstandingMortgage->pence + $parkHome->sellingCosts->pence + $parkHome->capitalGainsTax->pence,
            'the commission is a selling cost, so the waterfall still sums to the price',
        );
    }

    /**
     * A forced sale mid-projection pays it too. Flat economy, no income, identical spend, so the
     * whole difference in liquid wealth after the sale is the commission.
     */
    public function test_a_forced_sale_of_a_park_home_pays_the_site_owners_commission(): void
    {
        $house = $this->afterSale($this->household(chattel: false, action: MortgageMaturityAction::ForcedSale));
        $parkHome = $this->afterSale($this->household(chattel: true, action: MortgageMaturityAction::ForcedSale));

        $this->assertSame(0, $parkHome->propertyWealth->pence, 'the fixture must actually sell the home');
        $this->assertSame(
            $this->commission(),
            $house->liquidWealth->pence - $parkHome->liquidWealth->pence,
            'a forced sale of a park home must bank the price less the site owner\'s commission',
        );
    }

    private function afterSale(Household $household): YearResult
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
        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $flat, new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        foreach ($forecast->years as $year) {
            if ($year->calendarYear === 2031) {
                return $year;
            }
        }
        $this->fail('the forecast never reached 2031');
    }
}
