<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Sweep;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\AnnuityPurchase;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\LongevityMode;
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
use RetireForecast\FinanceEngine\Sweep\Lever\BuyPriceLever;
use RetireForecast\FinanceEngine\Sweep\Lever\EssentialSpendLever;
use RetireForecast\FinanceEngine\Sweep\Lever\PersonLongevityLever;
use RetireForecast\FinanceEngine\Sweep\Lever\RetirementAgeLever;
use RetireForecast\FinanceEngine\Sweep\Lever\SurvivorAnnuityFractionLever;
use RetireForecast\FinanceEngine\Sweep\Lever\SurvivorDbFractionLever;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepEngine;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The real-world sweep levers (the ones a scenario actually varies): retirement age, essential
 * spend, buy price. The transformation each makes is checked directly (cheap, deterministic), and
 * the two that move the money most are shown to move the success curve the right way through a
 * small Monte Carlo sweep.
 */
final class SweepLeversTest extends TestCase
{
    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    private function engine(): SweepEngine
    {
        return new SweepEngine(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi));
    }

    public function test_the_retirement_age_lever_moves_earners_and_clamps(): void
    {
        $household = $this->workingCouple();
        $lever = new RetirementAgeLever;

        $at70 = $lever->apply($household, $this->settings(), 70)->household;
        $this->assertSame(70, $at70->persons[0]->plannedRetirementAge, 'the earner moves to the swept age');
        $this->assertNull($at70->persons[1]->plannedRetirementAge, 'the retired partner is untouched');

        // Clamped to the 50–80 band the builder allows.
        $this->assertSame(80, $lever->apply($household, $this->settings(), 95)->household->persons[0]->plannedRetirementAge);
        $this->assertSame(50, $lever->apply($household, $this->settings(), 40)->household->persons[0]->plannedRetirementAge);

        $this->assertSame(LeverDirection::Increasing, $lever->direction());
    }

    public function test_the_essential_spend_lever_sets_the_floor_and_keeps_the_rest(): void
    {
        $household = $this->workingCouple();
        $applied = (new EssentialSpendLever)->apply($household, $this->settings(), 25_000)->household;

        $this->assertSame(25_000_00, $applied->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(
            $household->expenseProfile->discretionaryAnnualSpend->pence,
            $applied->expenseProfile->discretionaryAnnualSpend->pence,
            'discretionary spend is preserved',
        );
        $this->assertSame(LeverDirection::Decreasing, (new EssentialSpendLever)->direction());
    }

    public function test_retiring_later_raises_the_success_curve(): void
    {
        $curve = $this->engine()->sweep(
            $this->workingCouple(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable,
            new RetirementAgeLever, [62.0, 72.0], SweepMetric::Essentials, nPaths: 250, seed: 9,
        );

        // Working ten years longer only helps: the later-retirement point is at least as safe.
        $this->assertGreaterThanOrEqual(
            $curve->points[0]->successProbability,
            $curve->points[1]->successProbability,
            'retiring later should not lower the chance the money lasts',
        );
    }

    public function test_buying_a_more_expensive_home_lowers_success(): void
    {
        $household = $this->homeOwningCouple();
        $comparison = new HousingComparison(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
        $action = new HousingAction(salePrice: Money::fromPounds(500_000));
        $lever = new BuyPriceLever($comparison, AssumptionSetLibrary::default(), $action);

        $curve = $this->engine()->sweep(
            $household, $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable,
            $lever, [150_000.0, 450_000.0], SweepMetric::Essentials, nPaths: 250, seed: 9,
        );

        // A dearer home leaves less surplus to invest, so the money is no more likely to last.
        $this->assertGreaterThanOrEqual(
            $curve->points[1]->successProbability,
            $curve->points[0]->successProbability,
            'buying cheaper (more invested surplus) should not lower success',
        );
        $this->assertSame(LeverDirection::Decreasing, $lever->direction());
    }

    public function test_the_survivor_db_fraction_lever_moves_only_schemes_that_offer_a_survivor_pension(): void
    {
        $household = $this->dbCouple();
        $lever = new SurvivorDbFractionLever;

        $at75 = $lever->apply($household, $this->settings(), 75)->household;
        $this->assertSame(75.0, $this->db($at75, 'p1')->spousePensionFraction->asPercent(), 'the scheme with a survivor pension is set to the swept fraction');
        $this->assertNull($this->db($at75, 'p2')->spousePensionFraction, 'a scheme that offers no survivor pension is left untouched (the lever never invents one)');

        // Clamped to a sane 0–100%.
        $this->assertSame(100.0, $this->db($lever->apply($household, $this->settings(), 130)->household, 'p1')->spousePensionFraction->asPercent());
        $this->assertSame(0.0, $this->db($lever->apply($household, $this->settings(), -20)->household, 'p1')->spousePensionFraction->asPercent());

        // The DC pot is carried through unchanged (only DB survivor fractions move).
        $this->assertSame(
            $household->pensions[2]->currentValue->pence,
            $at75->pensions[2]->currentValue->pence,
            'the DC pot is preserved',
        );

        $this->assertSame(LeverDirection::Increasing, $lever->direction());
    }

    public function test_a_bigger_survivor_pension_does_not_lower_success(): void
    {
        $curve = $this->engine()->sweep(
            $this->dbSurvivorCliffCouple(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable,
            new SurvivorDbFractionLever, [0.0, 100.0], SweepMetric::Essentials, nPaths: 250, seed: 9,
        );

        // More guaranteed survivor income can only help the money last through the survivor cliff.
        $this->assertGreaterThanOrEqual(
            $curve->points[0]->successProbability,
            $curve->points[1]->successProbability,
            'a larger survivor pension should not lower the chance the money lasts',
        );
    }

    public function test_the_person_longevity_lever_offsets_only_the_named_person(): void
    {
        $household = $this->dbCouple();
        $lever = new PersonLongevityLever('p2');

        $applied = $lever->apply($household, $this->settings(), 8.4)->household;

        $this->assertSame(LongevityMode::OffsetYears, $applied->persons[1]->longevity->mode, 'the named person lives an offset from peer');
        $this->assertSame(8.0, $applied->persons[1]->longevity->value, 'the swept value is the ± year offset (rounded to a whole year)');
        $this->assertNull($applied->persons[0]->longevity, 'the other partner is untouched (their lifespan is not this lever)');

        // Whose longevity is the insight, so the sweep is genuinely not monotone: extending the
        // better-provided partner helps, extending the survivor hurts. It must NOT be monotone-fit.
        $this->assertSame(LeverDirection::Unknown, $lever->direction());
    }

    public function test_a_person_longevity_offset_reaches_the_forecast(): void
    {
        // Completeness: the swept offset must actually change the modelled lifespan the forecast
        // runs on (not sit inert on the DTO). A deterministic forecast reads the same longevity
        // adjustment the Monte Carlo sampler does, so it is the cheap, stable proof the lever bites.
        $household = $this->workingCouple();
        $forecaster = new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
        $assumptions = AssumptionSetLibrary::default();
        $lever = new PersonLongevityLever('p1');

        $peer = $lever->apply($household, $this->settings(), 0)->household;
        $longer = $lever->apply($household, $this->settings(), 12)->household;

        $peerDeath = $forecaster->forecast($peer, $assumptions, $this->settings())->deathCalendarYears['p1'];
        $longerDeath = $forecaster->forecast($longer, $assumptions, $this->settings())->deathCalendarYears['p1'];

        $this->assertGreaterThan($peerDeath, $longerDeath, 'living 12 years longer pushes the modelled death year later in the forecast');
    }

    /** The Defined Benefit pension owned by $ownerId in $household. */
    private function db(Household $household, string $ownerId): DbPension
    {
        foreach ($household->pensions as $pension) {
            if ($pension instanceof DbPension && $pension->ownerId === $ownerId) {
                return $pension;
            }
        }
        $this->fail("no DB pension for {$ownerId}");
    }

    /** A couple with two DB schemes — one that provides a survivor pension, one that does not — plus a DC pot. */
    private function dbCouple(): Household
    {
        return new Household(
            'DB couple',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-04-01'), Sex::Male, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1957-09-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(28_000), Money::zero(), Percent::fromPercent(80)),
            [
                new DbPension('p1', Money::fromPounds(15_000), normalRetirementAge: 65, spousePensionFraction: Percent::fromPercent(50)),
                new DbPension('p2', Money::fromPounds(8_000), normalRetirementAge: 65), // no survivor fraction
                new DcPension('p1', Money::fromPounds(90_000), Money::zero(), Money::zero(), earliestAccessAge: 57),
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
            ],
        );
    }

    /** A couple leaning on one partner's DB pension, tight enough that losing the survivor's share bites. */
    private function dbSurvivorCliffCouple(): Household
    {
        return new Household(
            'DB survivor cliff',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1948-01-01'), Sex::Male, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1952-01-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(22_000), Money::zero(), Percent::fromPercent(85)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(180)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(120)),
                new DbPension('p1', Money::fromPounds(18_000), normalRetirementAge: 65, spousePensionFraction: Percent::fromPercent(50)),
                new DcPension('p2', Money::fromPounds(60_000), Money::zero(), Money::zero(), earliestAccessAge: 57),
            ],
        );
    }

    public function test_the_survivor_annuity_fraction_lever_moves_only_joint_life_annuities(): void
    {
        $household = $this->annuityCouple();
        $lever = new SurvivorAnnuityFractionLever;

        $at75 = $lever->apply($household, $this->settings(), 75)->household;
        $this->assertSame(75.0, $at75->pensions[0]->annuityPurchase->survivorFraction->asPercent(), 'the joint-life annuity is set to the swept fraction');
        $this->assertNull($at75->pensions[1]->annuityPurchase->survivorFraction, 'a single-life annuity is left single-life (never turned joint-life at a single-life rate)');
        $this->assertNull($at75->pensions[2]->annuityPurchase, 'a pot with no annuity is untouched');

        // Clamped to a sane 0–100%.
        $this->assertSame(100.0, $lever->apply($household, $this->settings(), 130)->household->pensions[0]->annuityPurchase->survivorFraction->asPercent());
        $this->assertSame(0.0, $lever->apply($household, $this->settings(), -20)->household->pensions[0]->annuityPurchase->survivorFraction->asPercent());

        // The annuitant's own income is held fixed (same purchase amount + rate).
        $this->assertSame(
            $household->pensions[0]->annuityPurchase->amount->pence,
            $at75->pensions[0]->annuityPurchase->amount->pence,
            'only the survivor fraction moves',
        );

        $this->assertSame(LeverDirection::Increasing, $lever->direction());
    }

    public function test_a_bigger_annuity_survivor_income_does_not_lower_success(): void
    {
        $curve = $this->engine()->sweep(
            $this->annuitySurvivorCliffCouple(), $this->settings(), AssumptionSetLibrary::default(), new CohortLifeTable,
            new SurvivorAnnuityFractionLever, [0.0, 100.0], SweepMetric::Essentials, nPaths: 250, seed: 9,
        );

        // More of the annuity carried on to the survivor can only help the money last past the cliff.
        $this->assertGreaterThanOrEqual(
            $curve->points[0]->successProbability,
            $curve->points[1]->successProbability,
            'a bigger annuity survivor income should not lower the chance the money lasts',
        );
    }

    /** A couple with a joint-life annuity, a single-life annuity and a plain pot — to check the lever's reach. */
    private function annuityCouple(): Household
    {
        return new Household(
            'Annuity couple',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-04-01'), Sex::Male, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1957-09-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(28_000), Money::zero(), Percent::fromPercent(80)),
            [
                new DcPension('p1', Money::fromPounds(150_000), Money::zero(), Money::zero(), earliestAccessAge: 57, annuityPurchase: new AnnuityPurchase(atAge: 71, amount: Money::fromPounds(100_000), rate: Percent::fromPercent(6), survivorFraction: Percent::fromPercent(50))),
                new DcPension('p2', Money::fromPounds(120_000), Money::zero(), Money::zero(), earliestAccessAge: 57, annuityPurchase: new AnnuityPurchase(atAge: 69, amount: Money::fromPounds(80_000), rate: Percent::fromPercent(6))), // single-life
                new DcPension('p1', Money::fromPounds(50_000), Money::zero(), Money::zero(), earliestAccessAge: 57), // no annuity
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
            ],
        );
    }

    /** A couple leaning on one partner's joint-life annuity, tight enough that the survivor share bites. */
    private function annuitySurvivorCliffCouple(): Household
    {
        return new Household(
            'Annuity survivor cliff',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1950-01-01'), Sex::Male, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1953-01-01'), Sex::Female, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(24_000), Money::zero(), Percent::fromPercent(85)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(180)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(120)),
                new DcPension('p1', Money::fromPounds(280_000), Money::zero(), Money::zero(), earliestAccessAge: 57, annuityPurchase: new AnnuityPurchase(atAge: 76, amount: Money::fromPounds(250_000), rate: Percent::fromPercent(6), survivorFraction: Percent::fromPercent(50))),
            ],
        );
    }

    /** A couple with one earner still working towards retirement, tight enough that the levers bite. */
    private function workingCouple(): Household
    {
        return new Household(
            'Working',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1963-04-01'), Sex::Female, EmploymentStatus::Employed, grossSalary: Money::fromPounds(38_000), plannedRetirementAge: 66),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(34_000), Money::fromPounds(2_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
                new DcPension('p1', Money::fromPounds(120_000), Money::fromPounds(9_000), Money::fromPounds(4_000), earliestAccessAge: 57, withdrawalPlan: []),
            ],
        );
    }

    /** A couple owning a £500k home to sell, so the buy-price lever has a sale to work from. */
    private function homeOwningCouple(): Household
    {
        return new Household(
            'Owners',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1957-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1957-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(30_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
            ],
            primaryResidence: new Property(Money::fromPounds(500_000), OwnershipType::Outright),
        );
    }
}
