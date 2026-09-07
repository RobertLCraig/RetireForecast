<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Iht;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Dto\ResidenceDisposal;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Iht\IhtResult;
use RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The Inheritance Tax downsizing addition (board card 0053).
 *
 * The residence nil-rate band used to be capped at the home owned AT DEATH, so a plan that sold
 * and rented died owning no home and got a band of nil, and a plan that sold and bought cheaper
 * was capped at the cheaper home. The statute restores the lost part of the band where a
 * qualifying former residence was disposed of on or after 8 July 2015 and other assets of at
 * least equal value pass to direct descendants. This tool exists to compare staying put against
 * downsizing, so an engine without that rule penalised every downsizing option with tax the law
 * is written to prevent.
 *
 * All figures below are exact pence against the frozen bands (NRB £325,000, RNRB £175,000, taper
 * threshold £2,000,000 at £1 in £2, rate 40%).
 */
final class RnrbDownsizingAdditionTest extends TestCase
{
    private const NRB = 325_000;

    private const MAX_RNRB = 175_000;

    private function calculator(): InheritanceTaxCalculator
    {
        return new InheritanceTaxCalculator(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi));
    }

    /** One death, one set of bands, no pensions in the estate: the arithmetic and nothing else. */
    private function compute(int $estate, int $homeAtDeath, ?int $disposal): IhtResult
    {
        return $this->calculator()->compute(
            Money::fromPounds($estate),
            Money::zero(),
            false,
            Money::fromPounds($homeAtDeath),
            1,
            $disposal === null ? null : new ResidenceDisposal(Money::fromPounds($disposal), 2030),
        );
    }

    /**
     * Criterion #1, sell and rent: no home at death at all. The whole band was lost on the
     * disposal, so the whole band comes back as the addition.
     */
    public function test_a_sell_and_rent_estate_keeps_the_band_as_a_downsizing_addition(): void
    {
        // Without the disposal recorded (what the engine did before): no home, so no band.
        $deleted = $this->compute(estate: 800_000, homeAtDeath: 0, disposal: null);
        $this->assertSame(0, $deleted->residenceNilRateBandUsed->pence);
        $this->assertSame((800_000 - self::NRB) * 100, $deleted->taxableEstate->pence);
        $this->assertSame((int) ((800_000 - self::NRB) * 100 * 0.4), $deleted->tax->pence);

        // With it: the £300,000 sold home covered the whole £175,000 band, so all of it is
        // restored, and the estate is £70,000 of tax better off.
        $restored = $this->compute(estate: 800_000, homeAtDeath: 0, disposal: 300_000);
        $this->assertSame(self::MAX_RNRB * 100, $restored->downsizingAddition->pence);
        $this->assertSame(self::MAX_RNRB * 100, $restored->residenceNilRateBandUsed->pence);
        $this->assertSame((800_000 - self::NRB - self::MAX_RNRB) * 100, $restored->taxableEstate->pence);
        $this->assertSame(70_000_00, $deleted->tax->pence - $restored->tax->pence);
    }

    /**
     * Criterion #1, sell and buy cheaper: the cheaper home covers part of the band and the
     * addition restores exactly the part it does not.
     */
    public function test_a_sell_and_buy_cheaper_estate_gets_back_only_the_part_it_lost(): void
    {
        $capped = $this->compute(estate: 800_000, homeAtDeath: 100_000, disposal: null);
        $this->assertSame(100_000_00, $capped->residenceNilRateBandUsed->pence);

        $restored = $this->compute(estate: 800_000, homeAtDeath: 100_000, disposal: 300_000);
        $this->assertSame((self::MAX_RNRB - 100_000) * 100, $restored->downsizingAddition->pence, 'only the lost £75,000 is added back');
        $this->assertSame(self::MAX_RNRB * 100, $restored->residenceNilRateBandUsed->pence);
    }

    /** Criterion #1, the control: staying put disposes of nothing, so nothing is added. */
    public function test_staying_put_gets_no_addition_and_is_unchanged(): void
    {
        $stayPut = $this->compute(estate: 800_000, homeAtDeath: 500_000, disposal: null);

        $this->assertSame(0, $stayPut->downsizingAddition->pence);
        $this->assertSame(self::MAX_RNRB * 100, $stayPut->residenceNilRateBandUsed->pence);
    }

    /**
     * A disposal that was already smaller than the band restores only what it was worth: the
     * addition is the band the former home actually used, never the whole band.
     */
    public function test_a_small_former_home_restores_only_its_own_value(): void
    {
        $result = $this->compute(estate: 800_000, homeAtDeath: 0, disposal: 60_000);

        $this->assertSame(60_000_00, $result->downsizingAddition->pence);
        $this->assertSame(60_000_00, $result->residenceNilRateBandUsed->pence);
    }

    /**
     * Criterion #2: the addition is capped at the value of the non-home assets passing to direct
     * descendants. A household that sold a large home and then spent the proceeds has nothing
     * left for the band to shelter, so there is nothing to restore.
     */
    public function test_the_addition_is_capped_at_the_non_home_assets_passing_to_descendants(): void
    {
        // £300,000 of other assets is more than the £175,000 lost, so the cap does not bite.
        $uncapped = $this->compute(estate: 300_000, homeAtDeath: 0, disposal: 400_000);
        $this->assertSame(self::MAX_RNRB * 100, $uncapped->downsizingAddition->pence);

        // £100,000 of other assets is less than the £175,000 lost, so the addition stops there.
        $capped = $this->compute(estate: 100_000, homeAtDeath: 0, disposal: 400_000);
        $this->assertSame(100_000_00, $capped->downsizingAddition->pence);
        $this->assertSame(100_000_00, $capped->residenceNilRateBandUsed->pence);

        // The home itself is not part of the cap: only what passes BESIDES it counts. Here the
        // estate is a £40,000 home plus £30,000 of everything else, so the cap is £30,000.
        $withHome = $this->compute(estate: 70_000, homeAtDeath: 40_000, disposal: 400_000);
        $this->assertSame(30_000_00, $withHome->downsizingAddition->pence);
        $this->assertSame(70_000_00, $withHome->residenceNilRateBandUsed->pence);
    }

    /**
     * Criterion #3: the taper is applied to the TOTAL band after the addition, not before. A
     * £2.2m estate loses £100,000 of the £175,000 band to the taper, leaving £75,000, and that
     * £75,000 is the ceiling on the home plus the addition together. Taper the addition
     * separately and the estate would keep the whole £175,000 it is not entitled to.
     */
    public function test_the_estate_taper_caps_the_band_after_the_addition(): void
    {
        $result = $this->compute(estate: 2_200_000, homeAtDeath: 0, disposal: 500_000);

        $this->assertSame(self::MAX_RNRB * 100, $result->downsizingAddition->pence);
        $this->assertSame(75_000_00, $result->residenceNilRateBandUsed->pence, 'the tapered band is the ceiling on home + addition');
        $this->assertSame((2_200_000 - self::NRB - 75_000) * 100, $result->taxableEstate->pence);
    }

    /** An estate far enough above the threshold tapers the whole band away, addition and all. */
    public function test_the_taper_can_still_wipe_the_band_out_entirely(): void
    {
        $result = $this->compute(estate: 2_400_000, homeAtDeath: 0, disposal: 500_000);

        $this->assertSame(0, $result->residenceNilRateBandUsed->pence);
    }

    /**
     * Criterion #1 end to end: the same modest household run as "stay put", as "sell and rent"
     * and as "sell and buy cheaper" through the housing transforms the app actually uses. Before
     * this card both sell plans lost the band; now all three keep it.
     *
     * Every leg is run on ONE settings object rather than the variant's own. `rentSettings()`
     * rebuilds {@see ForecastSettings} by hand and silently drops `modelIht`, so the rent leg's
     * own settings model no Inheritance Tax at all and the criterion could not be seen through
     * them. That is a separate defect, raised as board card 0124; this card's change is to the
     * HOUSEHOLD, which is what is exercised here.
     */
    public function test_the_three_housing_plans_all_keep_the_residence_band(): void
    {
        $household = $this->modestCouple();
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelIht: true, homeToDescendants: true);
        $action = new HousingAction(
            salePrice: Money::fromPounds(450_000),
            buyPrice: Money::fromPounds(250_000),
            annualRent: Money::fromPounds(12_000),
        );

        $comparison = new HousingComparison(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
        $variants = $comparison->variantInputs($household, $settings, AssumptionSetLibrary::default(), $action);

        $additions = [];
        foreach (['stay_put', 'buy_outright', 'rent'] as $key) {
            $rentLeg = $key === 'rent' ? Money::fromPounds(12_000) : null;
            $iht = $this->forecast($variants[$key]['household'], new ForecastSettings(
                baseYear: 2026,
                baseTaxYear: '2026-27',
                annualRent: $rentLeg,
                modelIht: true,
                homeToDescendants: true,
            ))->iht;

            $this->assertNotNull($iht, "{$key}: IHT was not modelled");
            $this->assertTrue(
                $iht->secondDeath->residenceNilRateBandUsed->isPositive(),
                "{$key}: the residence nil-rate band was deleted by the housing choice",
            );
            $additions[$key] = $iht->secondDeath->downsizingAddition;
        }

        // The two sell plans get theirs from the addition; staying put does not.
        $this->assertSame(0, $additions['stay_put']->pence);
        $this->assertTrue($additions['rent']->isPositive(), 'sell-and-rent gets the whole band back as an addition');
        $this->assertTrue($additions['buy_outright']->isPositive(), 'sell-and-buy-cheaper gets back the part the cheaper home does not cover');
    }

    /**
     * A home sold MID-projection because the mortgage was called (the forced-sale path) is a
     * disposal too, recorded by the projector rather than by the housing transform.
     */
    public function test_a_forced_sale_mid_projection_is_a_qualifying_disposal(): void
    {
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelIht: true, homeToDescendants: true);

        $withoutSale = $this->modestCouple();
        $withSale = $this->modestCouple(forcedSaleIn: 2028);

        $sold = $this->forecast($withSale, $settings)->iht;
        $this->assertNotNull($sold);
        $this->assertTrue($sold->secondDeath->downsizingAddition->isPositive(), 'a forced sale is a disposal, so the band survives it');
        $this->assertTrue($sold->secondDeath->residenceNilRateBandUsed->isPositive());

        // The household that never sold keeps its band from the home itself, with no addition.
        $kept = $this->forecast($withoutSale, $settings)->iht;
        $this->assertNotNull($kept);
        $this->assertSame(0, $kept->secondDeath->downsizingAddition->pence);
    }

    /**
     * The addition rides on the same condition as the band itself: an estate that leaves nothing
     * to direct descendants gets neither.
     */
    public function test_no_addition_when_the_estate_does_not_pass_to_descendants(): void
    {
        $household = $this->modestCouple();
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelIht: true, homeToDescendants: false);

        $comparison = new HousingComparison(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
        $variants = $comparison->variantInputs($household, $settings, AssumptionSetLibrary::default(), new HousingAction(
            salePrice: Money::fromPounds(450_000),
            annualRent: Money::fromPounds(12_000),
        ));

        // Run on this test's own settings, not the rent leg's: see card 0124 in the test above.
        $iht = $this->forecast($variants['rent']['household'], $settings)->iht;

        $this->assertNotNull($iht);
        $this->assertSame(0, $iht->secondDeath->downsizingAddition->pence);
        $this->assertSame(0, $iht->secondDeath->residenceNilRateBandUsed->pence);
    }

    /**
     * A modest couple whose estate stays under the £2m taper threshold, so the band is visible.
     * Both die within a decade, mirroring InheritanceTaxForecastTest's "Modest" household.
     */
    private function modestCouple(?int $forcedSaleIn = null): Household
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
            primaryResidence: $forcedSaleIn === null
                ? new Property(Money::fromPounds(450_000), OwnershipType::Outright)
                : new Property(
                    Money::fromPounds(450_000),
                    OwnershipType::Mortgaged,
                    outstandingMortgage: Money::fromPounds(50_000),
                    mortgageRedemptionYear: $forcedSaleIn,
                    mortgageMaturityAction: MortgageMaturityAction::ForcedSale,
                ),
            relationshipStatus: RelationshipStatus::MarriedOrCivilPartnership,
        );
    }

    private function forecast(Household $h, ForecastSettings $settings): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, AssumptionSetLibrary::default(), $settings);
    }
}
