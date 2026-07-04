<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\DcPension;
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
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The IHT toggle now bites: with it on, the deterministic forecast values the estate at each
 * death and computes the Inheritance Tax due (relationship-status aware), surfaced on
 * {@see ForecastResult::$iht}. This closes a collected-but-unconsumed input — before this the
 * toggle was stored and shown but no forecast read it, so turning it on changed nothing.
 */
final class InheritanceTaxForecastTest extends TestCase
{
    /** A wealthy couple: low spend, large liquid + pensions + a home, so a big estate is left. */
    private function couple(RelationshipStatus $status): Household
    {
        return new Household(
            'Estate',
            RegionProfile::EnglandWalesNi,
            [
                // P1 dies first (2035), P2 the final survivor (2045) — both deaths past April 2027.
                new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(80)),
                new Person('p2', new DateTimeImmutable('1957-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(88)),
            ],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
                new DcPension('p1', Money::fromPounds(400_000), Money::zero(), Money::zero(), earliestAccessAge: 57, withdrawalPlan: []),
                new DcPension('p2', Money::fromPounds(400_000), Money::zero(), Money::zero(), earliestAccessAge: 57, withdrawalPlan: []),
            ],
            accounts: [
                new Account('p1', AccountType::Cash, Money::fromPounds(500_000)),
                new Account('p2', AccountType::Cash, Money::fromPounds(500_000)),
            ],
            primaryResidence: new Property(Money::fromPounds(600_000), OwnershipType::Outright),
            relationshipStatus: $status,
        );
    }

    private function forecast(Household $h, bool $modelIht, bool $homeToDescendants = true): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, AssumptionSetLibrary::default(), new ForecastSettings(
                baseYear: 2026,
                baseTaxYear: '2026-27',
                modelIht: $modelIht,
                homeToDescendants: $homeToDescendants,
            ));
    }

    public function test_the_toggle_bites_off_is_null_on_produces_iht(): void
    {
        $household = $this->couple(RelationshipStatus::MarriedOrCivilPartnership);

        // Off: no IHT computed at all (the pre-existing behaviour — the collected-but-unconsumed drop).
        $this->assertNull($this->forecast($household, false)->iht);

        // On: a large estate produces a non-zero IHT (the completeness invariant — the toggle now
        // demonstrably reaches the result).
        $iht = $this->forecast($household, true)->iht;
        $this->assertNotNull($iht);
        $this->assertTrue($iht->total->isPositive(), 'a £2m+ estate should incur IHT when modelled');
    }

    public function test_relationship_status_changes_the_iht(): void
    {
        $married = $this->forecast($this->couple(RelationshipStatus::MarriedOrCivilPartnership), true)->iht;
        $cohabiting = $this->forecast($this->couple(RelationshipStatus::Cohabiting), true)->iht;

        $this->assertNotNull($married);
        $this->assertNotNull($cohabiting);

        // Married: the first death is spousally exempt (£0, flagged), and the total is only the
        // second death's tax — where both partners' nil-rate bands are available.
        $this->assertNotNull($married->firstDeath);
        $this->assertSame(0, $married->firstDeath->tax->pence, 'a married first death is spousally exempt');
        $this->assertContains(
            WarningCode::IHT_SPOUSE_EXEMPTION,
            array_map(static fn ($w) => $w->code, $married->firstDeath->warnings),
        );
        $this->assertSame($married->secondDeath->tax->pence, $married->total->pence);

        // Cohabiting: the first death is a chargeable transfer (no spouse exemption), and each
        // death has only one set of bands — so the same estate pays materially MORE IHT.
        $this->assertNotNull($cohabiting->firstDeath);
        $this->assertTrue($cohabiting->firstDeath->tax->isPositive(), 'a cohabiting first death is chargeable');
        $this->assertGreaterThan(
            $married->total->pence,
            $cohabiting->total->pence,
            'a cohabiting couple, with no spouse exemption and no transferable band, pays more IHT',
        );
    }

    public function test_leaving_the_home_to_descendants_unlocks_the_residence_band(): void
    {
        // A MODEST estate that stays under the £2m residence-band taper threshold (the large
        // couple() estate is tapered away entirely — correct behaviour, but it hides the band).
        // Both die soon, so the nominal estate at the final death does not grow past £2m.
        $household = new Household(
            'Modest',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1948-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(84)),   // dies ~2032
                new Person('p2', new DateTimeImmutable('1950-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(84)), // dies ~2034 (final)
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
            primaryResidence: new Property(Money::fromPounds(450_000), OwnershipType::Outright),
            relationshipStatus: RelationshipStatus::MarriedOrCivilPartnership,
        );

        $toDescendants = $this->forecast($household, true, homeToDescendants: true)->iht;
        $notToDescendants = $this->forecast($household, true, homeToDescendants: false)->iht;

        $this->assertNotNull($toDescendants);
        $this->assertNotNull($notToDescendants);

        // The residence nil-rate band applies only when the home passes to direct descendants, so
        // leaving it to them shelters more of the estate and the final death pays LESS IHT.
        $this->assertTrue($toDescendants->secondDeath->residenceNilRateBandUsed->isPositive());
        $this->assertSame(0, $notToDescendants->secondDeath->residenceNilRateBandUsed->pence);
        $this->assertLessThan(
            $notToDescendants->total->pence,
            $toDescendants->total->pence,
            'leaving the home to descendants unlocks the residence band, so less IHT is due',
        );
    }

    public function test_a_single_person_household_has_only_a_final_death(): void
    {
        $single = new Household(
            'Single',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1950-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(85))],
            new ExpenseProfile(Money::fromPounds(18_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200))],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(600_000))],
            relationshipStatus: RelationshipStatus::MarriedOrCivilPartnership, // ignored for one person
        );

        $iht = $this->forecast($single, true)->iht;
        $this->assertNotNull($iht);
        $this->assertNull($iht->firstDeath, 'a single person has no first death');
        // A £600k liquid estate exceeds the £325k nil-rate band (no home → no residence band), so tax is due.
        $this->assertTrue($iht->secondDeath->tax->isPositive());
        $this->assertSame($iht->secondDeath->tax->pence, $iht->total->pence);
    }
}
