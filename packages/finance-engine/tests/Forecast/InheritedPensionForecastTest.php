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
use RetireForecast\FinanceEngine\Dto\PensionBeneficiary;
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
 * Board card 0057, end to end through the projector: the beneficiary's income tax reaches the
 * forecast result, and the spouse exemption on a pension follows the NOMINATION rather than
 * marital status.
 */
final class InheritedPensionForecastTest extends TestCase
{
    /** Both deaths are past 75 and past April 2027, so both charges are in play. */
    private function couple(?PensionBeneficiary $nomination): Household
    {
        return new Household(
            'Estate',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1955-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(80), hasWill: true),
                new Person('p2', new DateTimeImmutable('1957-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(88), hasWill: true),
            ],
            new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(200)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::fromPounds(200)),
                new DcPension('p1', Money::fromPounds(400_000), Money::zero(), Money::zero(), earliestAccessAge: 57, nominatedBeneficiary: $nomination),
                new DcPension('p2', Money::fromPounds(400_000), Money::zero(), Money::zero(), earliestAccessAge: 57, nominatedBeneficiary: $nomination),
            ],
            accounts: [
                new Account('p1', AccountType::Cash, Money::fromPounds(500_000)),
                new Account('p2', AccountType::Cash, Money::fromPounds(500_000)),
            ],
            primaryResidence: new Property(Money::fromPounds(600_000), OwnershipType::Outright),
            relationshipStatus: RelationshipStatus::MarriedOrCivilPartnership,
        );
    }

    private function forecast(Household $h, ?Percent $beneficiaryRate = null): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, AssumptionSetLibrary::default(), new ForecastSettings(
                baseYear: 2026,
                baseTaxYear: '2026-27',
                modelIht: true,
                beneficiaryMarginalRate: $beneficiaryRate,
            ));
    }

    public function test_the_forecast_reports_the_beneficiarys_income_tax_on_the_inherited_pots(): void
    {
        $iht = $this->forecast($this->couple(PensionBeneficiary::SpouseOrCivilPartner))->iht;

        $this->assertNotNull($iht);
        $this->assertTrue(
            $iht->beneficiaryIncomeTax->isPositive(),
            'a pot left on a death at 88 is taxed on whoever inherits it, and that charge must reach the result',
        );
        $this->assertContains(
            WarningCode::IHT_INHERITED_PENSION_INCOME_TAX,
            array_map(static fn ($w) => $w->code, $iht->secondDeath->warnings),
        );
        // It is NOT rolled into the Inheritance Tax total: the two fall on different people.
        $this->assertSame($iht->secondDeath->tax->pence, $iht->total->pence);
    }

    public function test_a_lower_assumed_beneficiary_rate_lowers_the_charge(): void
    {
        $higher = $this->forecast($this->couple(PensionBeneficiary::SpouseOrCivilPartner));
        $basic = $this->forecast($this->couple(PensionBeneficiary::SpouseOrCivilPartner), Percent::fromPercent(20));

        $this->assertLessThan(
            $higher->iht->beneficiaryIncomeTax->pence,
            $basic->iht->beneficiaryIncomeTax->pence,
            'the assumed rate must actually drive the figure, so editing it is worth something',
        );
    }

    public function test_a_pension_nominated_away_from_the_spouse_costs_the_first_death(): void
    {
        // Same couple, same wills, same marriage. The only difference is the expression of wish:
        // a pot nominated to a child is not spouse-exempt, so the first death is chargeable on it
        // where a pot nominated to the spouse is not.
        $toSpouse = $this->forecast($this->couple(PensionBeneficiary::SpouseOrCivilPartner))->iht;
        $elsewhere = $this->forecast($this->couple(PensionBeneficiary::SomeoneElse))->iht;

        $this->assertSame(0, $toSpouse->firstDeath->tax->pence, 'a spouse-nominated pot is exempt on the first death');
        $this->assertTrue(
            $elsewhere->firstDeath->tax->isPositive(),
            'a pot nominated to a child passes outside the will and is chargeable, married or not',
        );
        $this->assertGreaterThan($toSpouse->total->pence, $elsewhere->total->pence);
    }

    public function test_an_unanswered_nomination_takes_the_adverse_answer(): void
    {
        // Nobody was asked, so the pot is NOT assumed to go to the spouse: the tool must not hand
        // out an exemption on a form it has never seen. Disclosed as an assumed figure elsewhere.
        $unasked = $this->forecast($this->couple(null))->iht;
        $elsewhere = $this->forecast($this->couple(PensionBeneficiary::SomeoneElse))->iht;

        $this->assertSame($elsewhere->firstDeath->tax->pence, $unasked->firstDeath->tax->pence);
    }
}
