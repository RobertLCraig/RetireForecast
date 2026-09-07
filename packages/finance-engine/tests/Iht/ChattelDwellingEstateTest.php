<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Iht;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
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
 * A home that is a CHATTEL rather than an interest in land — the park home (board card 0059,
 * from the estate planner's findings of 2026-08-19).
 *
 * Three separate faults, all in the estate:
 *  - The residence nil-rate band needs a "qualifying residential interest", an interest in a
 *    DWELLING-HOUSE. A park-home owner owns a chattel standing on somebody else's pitch under a
 *    pitch agreement, so the band is at best unsafe and most likely unavailable. The engine passed
 *    home equity into {@see InheritanceTaxCalculator::compute()} regardless of what the home was,
 *    so a park home claimed a band it almost certainly cannot have.
 *  - The site owner takes a commission of up to 10% of the price on ANY resale, and the exit always
 *    happens eventually, so a park home's terminal value was overstated by that commission.
 *  - The first death split the home 50/50 with nothing behind the figure.
 *
 * Everything below runs on a FLAT economy (zero inflation, zero house growth), so the £450,000
 * home is worth exactly £450,000 nominal at each death and every figure is exact in pence.
 */
final class ChattelDwellingEstateTest extends TestCase
{
    private const HOME = 450_000;

    /** Zero inflation and zero house growth, so nominal == real and the home never moves. */
    private function flatEconomy(): AssumptionSet
    {
        return AssumptionSetLibrary::default()
            ->withInflationMean(Percent::fromPercent(0))
            ->withHouseGrowth(Percent::fromPercent(0));
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelIht: true, homeToDescendants: true);
    }

    /**
     * Criterion #1. A park home is not an interest in a dwelling-house, so no residence nil-rate
     * band is claimed against it — while the identical brick house next door keeps its band.
     */
    public function test_a_chattel_dwelling_gets_no_residence_nil_rate_band(): void
    {
        $house = $this->forecast($this->couple(chattel: false))->iht;
        $parkHome = $this->forecast($this->couple(chattel: true))->iht;

        $this->assertNotNull($house);
        $this->assertNotNull($parkHome);

        // Two deaths, so two £175,000 bands, capped at the home passing to descendants.
        $this->assertSame(350_000_00, $house->secondDeath->residenceNilRateBandUsed->pence);
        $this->assertSame(0, $parkHome->secondDeath->residenceNilRateBandUsed->pence, 'a chattel dwelling must claim no residence band');
    }

    /**
     * Criterion #2. The site owner's commission comes off the park home's value in the estate. The
     * two households are identical apart from what the home IS, and the home is worth exactly
     * £450,000 at the final death in this flat economy, so the whole difference is the commission.
     */
    public function test_a_chattel_dwellings_estate_is_net_of_the_site_owners_commission(): void
    {
        $house = $this->forecast($this->couple(chattel: false))->iht;
        $parkHome = $this->forecast($this->couple(chattel: true))->iht;

        $this->assertNotNull($house);
        $this->assertNotNull($parkHome);

        $expected = (int) round(self::HOME * 100 * (Property::MAX_SITE_COMMISSION_BPS / 10_000));
        $this->assertSame(45_000_00, $expected, 'the statutory maximum commission is 10% of the price');
        $this->assertSame(
            $expected,
            $house->secondDeath->totalEstate->pence - $parkHome->secondDeath->totalEstate->pence,
            'the estate must be valued net of the commission the site owner takes on the sale',
        );
    }

    /**
     * Criterion #5. The first death's share of the home is the deceased's BENEFICIAL share, not a
     * hard-coded half. Seventy per cent of a £450,000 home is £90,000 more than half of it, and
     * that is exactly what the first estate must grow by.
     */
    public function test_the_first_death_values_the_deceased_own_beneficial_share_of_the_home(): void
    {
        $equal = $this->forecast($this->couple(chattel: false))->iht;
        $uneven = $this->forecast($this->couple(chattel: false, shares: ['p1' => Percent::fromPercent(70), 'p2' => Percent::fromPercent(30)]))->iht;

        $this->assertNotNull($equal?->firstDeath);
        $this->assertNotNull($uneven?->firstDeath);

        $this->assertSame(
            (int) round(self::HOME * 100 * 0.20),
            $uneven->firstDeath->totalEstate->pence - $equal->firstDeath->totalEstate->pence,
            'a 70/30 split must put 20% more of the home in the first estate than an equal one',
        );
    }

    /**
     * The control for criterion #5: shares that ARE equal reproduce the old 50/50 exactly, so
     * nothing stored moves until somebody says the split is uneven.
     */
    public function test_equal_beneficial_shares_reproduce_the_old_half_share(): void
    {
        $default = $this->forecast($this->couple(chattel: false))->iht;
        $stated = $this->forecast($this->couple(chattel: false, shares: ['p1' => Percent::fromPercent(50), 'p2' => Percent::fromPercent(50)]))->iht;

        $this->assertNotNull($default?->firstDeath);
        $this->assertNotNull($stated?->firstDeath);
        $this->assertSame($default->firstDeath->totalEstate->pence, $stated->firstDeath->totalEstate->pence);
    }

    /**
     * A modest couple in a £450,000 home held outright, both dying at 84 — the first death in
     * 2032, the second in 2034. Their estate stays well under the £2m taper threshold, so the
     * residence band is visible.
     *
     * @param  array<string, Percent>|null  $shares
     */
    private function couple(bool $chattel, ?array $shares = null): Household
    {
        return new Household(
            'Modest',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1948-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(84)),
                new Person('p2', new DateTimeImmutable('1950-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(84)),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
            ],
            accounts: [
                new Account('p1', AccountType::Cash, Money::fromPounds(200_000)),
                new Account('p2', AccountType::Cash, Money::fromPounds(200_000)),
            ],
            primaryResidence: new Property(
                Money::fromPounds(self::HOME),
                OwnershipType::Outright,
                isChattelDwelling: $chattel,
                beneficialShares: $shares,
            ),
            relationshipStatus: RelationshipStatus::MarriedOrCivilPartnership,
        );
    }

    private function forecast(Household $h): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, $this->flatEconomy(), $this->settings());
    }
}
