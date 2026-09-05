<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
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
 * Card 0033, criterion 1. A block service charge often BUYS the water and the communal (sometimes
 * the whole flat's) electricity. The whole charge is a `while_owning_home` cost, so selling the
 * flat used to delete the utilities with it — and a house, a bungalow or a park home still has to
 * be heated and plumbed. Every sell and buy plan was therefore cheaper than it can really be.
 *
 * The utilities portion is now carried across the sale as ordinary always-charged essential spend:
 * the household stops paying the *service charge* and starts paying an *energy bill*.
 */
final class ServiceChargeUtilitiesTest extends TestCase
{
    public function test_the_utilities_inside_a_service_charge_survive_a_year_0_sale(): void
    {
        // £4,000 service charge of which £1,500 is water and electricity. Selling the flat drops
        // £2,500, not £4,000 — the household keeps buying the utilities from somebody else.
        $profile = new ExpenseProfile(
            essentialAnnualSpend: Money::fromPounds(30_000),
            discretionaryAnnualSpend: Money::fromPounds(10_000),
            survivorSpendFactor: Percent::fromPercent(70),
            propertyCosts: Money::fromPounds(4_000),
            propertyCostsUtilities: Money::fromPounds(1_500),
        );
        $sold = $profile->withoutPropertyCosts();

        $this->assertSame(Money::fromPounds(27_500)->pence, $sold->essentialAnnualSpend->pence);
        $this->assertSame(Money::fromPounds(10_000)->pence, $sold->discretionaryAnnualSpend->pence);
        // The replacement is ordinary spend now, not a home-ownership cost: neither marker survives,
        // so nothing can strip it a second time and no service-charge escalator applies to it.
        $this->assertSame(0, $sold->propertyCosts()->pence);
        $this->assertSame(0, $sold->propertyCostsUtilities()->pence);
    }

    public function test_the_replacement_cannot_exceed_the_charge_it_comes_out_of(): void
    {
        // A hand-entered utilities figure larger than the charge containing it would ADD spend on a
        // sale. It is clamped to the bucket, so the worst case is the whole charge surviving.
        $profile = new ExpenseProfile(
            essentialAnnualSpend: Money::fromPounds(30_000),
            discretionaryAnnualSpend: Money::zero(),
            survivorSpendFactor: Percent::fromPercent(70),
            propertyCosts: Money::fromPounds(4_000),
            propertyCostsUtilities: Money::fromPounds(9_000),
        );

        $this->assertSame(Money::fromPounds(4_000)->pence, $profile->propertyCostsUtilities()->pence);
        $this->assertSame(Money::fromPounds(30_000)->pence, $profile->withoutPropertyCosts()->essentialAnnualSpend->pence);
    }

    public function test_both_sell_variants_keep_the_replacement_utilities(): void
    {
        $variants = (new HousingComparison(TaxYearRegistry::for('2026-27'), new CohortLifeTable))->variantInputs(
            $this->owners(Money::fromPounds(4_000), Money::fromPounds(1_500)),
            new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            AssumptionSetLibrary::default(),
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(200_000), annualRent: Money::fromPounds(14_000)),
        );

        $stay = $variants['stay_put']['household']->expenseProfile->targetAnnualSpend()->pence;
        $kept = $stay - Money::fromPounds(2_500)->pence;

        $this->assertSame($kept, $variants['rent']['household']->expenseProfile->targetAnnualSpend()->pence);
        $this->assertSame($kept, $variants['buy_outright']['household']->expenseProfile->targetAnnualSpend()->pence);
    }

    public function test_a_mid_projection_forced_sale_keeps_the_replacement_utilities(): void
    {
        // The same rule on the other route out of the home: a forced sale in 2030 stops the service
        // charge from that year, but the household still buys water and electricity afterwards.
        $spend = $this->spendByYear($this->forcedSeller(Money::fromPounds(1_500)));

        $this->assertSame(Money::fromPounds(20_000)->pence, $spend[2029], 'the whole charge while the flat is owned');
        $this->assertSame(Money::fromPounds(17_500)->pence, $spend[2031], 'only the non-utility part of the charge goes');
    }

    public function test_a_service_charge_with_no_utilities_in_it_still_dies_with_the_home(): void
    {
        // No noise, and no invented spend: a charge that buys no utilities behaves exactly as it
        // did before this card.
        $spend = $this->spendByYear($this->forcedSeller(null));

        $this->assertSame(Money::fromPounds(20_000)->pence, $spend[2029]);
        $this->assertSame(Money::fromPounds(16_000)->pence, $spend[2031]);
    }

    private function owners(Money $propertyCosts, Money $utilities): Household
    {
        return new Household(
            'Owners',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(
                Money::fromPounds(30_000),
                Money::fromPounds(5_000),
                Percent::fromPercent(70),
                propertyCosts: $propertyCosts,
                propertyCostsUtilities: $utilities,
            ),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30))],
            primaryResidence: new Property(currentValue: Money::fromPounds(400_000), ownership: OwnershipType::Outright),
        );
    }

    /**
     * A household whose home is force-sold in 2030, spending £16,000 plus a £4,000 service charge.
     * Zero inflation and an explicit zero escalator, so every year's spend is penny-exact and the
     * only thing that can move it across the sale is the bucket coming off.
     */
    private function forcedSeller(?Money $utilities): Household
    {
        return new Household(
            'ForcedSeller',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1958-01-01'), Sex::Female, EmploymentStatus::Retired)],
            new ExpenseProfile(
                Money::fromPounds(20_000),
                Money::zero(),
                Percent::fromPercent(100),
                propertyCosts: Money::fromPounds(4_000),
                propertyCostsRealGrowth: Percent::zero(),
                propertyCostsUtilities: $utilities,
            ),
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(600_000))],
            primaryResidence: new Property(
                currentValue: Money::fromPounds(300_000),
                ownership: OwnershipType::Mortgaged,
                outstandingMortgage: Money::fromPounds(50_000),
                mortgageRedemptionYear: 2030,
                mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
            ),
        );
    }

    /** @return array<int, int> calendarYear => spendTarget pence */
    private function spendByYear(Household $household): array
    {
        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast(
                $household,
                AssumptionSetLibrary::default()->withInflationMean(Percent::zero()),
                new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'),
            );

        $out = [];
        foreach ($forecast->years as $year) {
            $out[$year->calendarYear] = $year->spendTarget->pence;
        }

        return $out;
    }
}
