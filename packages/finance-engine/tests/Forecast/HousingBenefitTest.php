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
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Housing Benefit for a pension-age renter, and the notional costs of sale that come off
 * property capital before it is assessed. Board card 0048.
 *
 * The engine awarded Guarantee Credit and nothing else, so a household that sold and rented was
 * shown paying its rent out of its own pocket for ever — in exactly the tail where a plan is
 * judged to run short, and with no equivalent omission on the buy-outright side.
 *
 * Every household here lives in a flat economy (zero inflation, zero growth, zero investment
 * yield) with a 100% survivor spend factor, so nominal == real and every figure is exact to the
 * penny. The State Pension and the Pension Credit guarantee still rise on the triple-lock floor,
 * so every exact assertion reads YEAR 0, before any uprating.
 */
final class HousingBenefitTest extends TestCase
{
    private const RENT = 8_000;

    private const ESSENTIAL_SPEND = 10_000;

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

    /**
     * A household that owns no home, so it rents for life — the "sell and rent" leg once the
     * proceeds have been spent down.
     *
     * @param  list<int>  $weeklyStatePensions  one per member; £150 a week is far below the
     *                                          guarantee (Guarantee Credit in payment), £288 is
     *                                          £50 a week above the 2026/27 single guarantee, and
     *                                          £400 is far above it.
     * @param  int  $birthYear  1950 is well past State Pension age in 2026; 1975 is well short of it
     */
    private function renter(array $weeklyStatePensions, int $isaBalance = 0, int $birthYear = 1950): Household
    {
        $persons = [];
        $pensions = [];
        foreach ($weeklyStatePensions as $i => $weekly) {
            $id = 'p'.($i + 1);
            $persons[] = new Person(
                $id,
                new DateTimeImmutable($birthYear.'-01-01'),
                $i === 0 ? Sex::Female : Sex::Male,
                EmploymentStatus::Retired,
                longevity: LongevityAdjustment::fixedAge(95),
            );
            $pensions[] = new StatePensionEntitlement($id, weeklyForecast: Money::fromPounds($weekly));
        }

        return new Household(
            'Renter',
            RegionProfile::EnglandWalesNi,
            $persons,
            new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(self::ESSENTIAL_SPEND),
                discretionaryAnnualSpend: Money::zero(),
                survivorSpendFactor: Percent::fromPercent(100),
            ),
            pensions: $pensions,
            accounts: $isaBalance > 0
                ? [new Account('p1', AccountType::Isa, Money::fromPounds($isaBalance))]
                : [],
        );
    }

    /**
     * A household whose home is LET, so it is not the exempt main residence and its equity is
     * assessable capital for the pension-age means test. The one state in this engine where
     * property capital reaches the tariff, and so the only place the notional costs of sale can
     * be read. It earns no rent (no {@see IncomeStreamType::Rental} stream), which keeps the
     * assessable INCOME fixed and leaves the capital treatment as the only moving part.
     */
    private function letHomeOwner(int $weeklyStatePension, int $value, int $mortgage): Household
    {
        return new Household(
            'Let home',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1950-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(95))],
            new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(self::ESSENTIAL_SPEND),
                discretionaryAnnualSpend: Money::zero(),
                survivorSpendFactor: Percent::fromPercent(100),
            ),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds($weeklyStatePension))],
            primaryResidence: new Property(
                Money::fromPounds($value),
                $mortgage > 0 ? OwnershipType::Mortgaged : OwnershipType::Outright,
                outstandingMortgage: $mortgage > 0 ? Money::fromPounds($mortgage) : null,
                isLet: true,
            ),
        );
    }

    private function forecast(Household $h, ?int $annualRent = null): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, $this->flatAssumptions(), new ForecastSettings(
                baseYear: 2026,
                baseTaxYear: '2026-27',
                annualRent: $annualRent === null ? null : Money::fromPounds($annualRent),
            ));
    }

    public function test_a_pension_age_renter_on_guarantee_credit_has_the_rent_met_in_full(): void
    {
        $result = $this->forecast($this->renter([150]), self::RENT);
        $year = $result->years[0];

        $this->assertTrue($year->incomeBySource['means_tested_benefit']->isPositive(), 'the household is on Guarantee Credit');
        $this->assertSame(self::RENT * 100, $year->housingBenefit()->pence, 'Guarantee Credit passports the maximum award');
        $this->assertSame(
            self::ESSENTIAL_SPEND * 100,
            $year->spendTarget->pence,
            'no rent reaches the spending target: Housing Benefit meets all of it',
        );
    }

    public function test_housing_benefit_tapers_away_above_the_applicable_amount(): void
    {
        // £288 a week against the 2026/27 single guarantee of £238.00 is £50 a week of excess
        // income, withdrawn at 65p in the pound.
        $result = $this->forecast($this->renter([288]), self::RENT);
        $year = $result->years[0];

        $this->assertFalse($year->incomeBySource['means_tested_benefit']->isPositive(), 'no Guarantee Credit at this income');

        $taper = (int) round(50_00 * 0.65) * 52;
        $this->assertSame(self::RENT * 100 - $taper, $year->housingBenefit()->pence);
        $this->assertSame(
            self::ESSENTIAL_SPEND * 100 + $taper,
            $year->spendTarget->pence,
            'what the household still pays out of its own pocket IS the taper',
        );

        // Far enough above the guarantee, the award tapers to nothing and the whole rent is paid.
        // How far is set by the rent: at 65p in the pound it takes £153.85 a week of excess income
        // to wipe out £8,000 a year of help, and £500 a week is £262 above the guarantee.
        $rich = $this->forecast($this->renter([500]), self::RENT);
        $this->assertSame(0, $rich->years[0]->housingBenefit()->pence);
        $this->assertSame((self::ESSENTIAL_SPEND + self::RENT) * 100, $rich->years[0]->spendTarget->pence);
    }

    public function test_no_housing_benefit_above_the_capital_limit(): void
    {
        // The same low-income renter as above, holding £20,000 — over the £16,000 upper capital
        // limit that ends Housing Benefit. This is the downsizing trap: the proceeds of the sale
        // that started the tenancy are what cross the line.
        $poor = $this->forecast($this->renter([288]), self::RENT);
        $rich = $this->forecast($this->renter([288], isaBalance: 20_000), self::RENT);

        $this->assertTrue($poor->years[0]->housingBenefit()->isPositive());
        $this->assertSame(0, $rich->years[0]->housingBenefit()->pence, 'capital above the limit ends the award');
        $this->assertSame((self::ESSENTIAL_SPEND + self::RENT) * 100, $rich->years[0]->spendTarget->pence);
    }

    public function test_no_housing_benefit_before_state_pension_age(): void
    {
        // Working-age Housing Benefit is closed to new claims (Universal Credit carries the
        // housing element instead), and Universal Credit is out of scope for a pension-age tool.
        // Awarding nothing here is deliberate, and the result note says so.
        $result = $this->forecast($this->renter([150], birthYear: 1975), self::RENT);

        $this->assertSame(0, $result->years[0]->housingBenefit()->pence);
        $this->assertSame((self::ESSENTIAL_SPEND + self::RENT) * 100, $result->years[0]->spendTarget->pence);
    }

    public function test_an_owner_still_in_the_home_is_awarded_nothing(): void
    {
        // Housing Benefit meets RENT. A household on Guarantee Credit that still owns its home
        // has its mortgage interest met by Support for Mortgage Interest instead, and nothing
        // here may double up on that.
        $result = $this->forecast($this->letHomeOwner(150, 200_000, 0));

        $this->assertSame(0, $result->years[0]->housingBenefit()->pence);
    }

    public function test_property_capital_is_assessed_net_of_the_notional_costs_of_sale(): void
    {
        // A let home is not the exempt main residence, so its equity is assessed. The rules value
        // it at market value LESS 10% for the costs of selling it, and then less the mortgage.
        //
        // £115,000 less 10% (£11,500) less a £100,000 mortgage is £3,500 of capital, below the
        // £10,000 disregard, so no tariff income at all. Valued at equity alone it is £15,000,
        // which is £5,000 above the disregard: ten £500 steps, £10 a week of tariff income.
        //
        // The claimant's State Pension is £220 a week against the £238.00 single guarantee, so
        // £18 a week of Guarantee Credit is in payment and the tariff visibly eats into it.
        $result = $this->forecast($this->letHomeOwner(220, 115_000, 100_000));
        $award = $result->years[0]->incomeBySource['means_tested_benefit'];

        $this->assertSame(18_00 * 52, $award->pence, 'the costs of sale take the capital below the disregard');
    }
}
