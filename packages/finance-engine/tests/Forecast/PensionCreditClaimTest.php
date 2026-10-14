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
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
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
 * Board card 0110. The means test entitles a household to Guarantee Credit whether or not it has
 * applied; only a claim puts it in payment. An unclaimed award must reach neither the income nor
 * the Support for Mortgage Interest it passports, and must be reported as what claiming is worth.
 *
 * The household is the SupportForMortgageInterestTest claimant: a single pensioner on £150 a week
 * of State Pension, with a mortgaged leasehold flat, in a flat economy.
 */
final class PensionCreditClaimTest extends TestCase
{
    public static function claimant(bool $claimed): Household
    {
        return new Household(
            'Pension Credit claimant',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1950-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(90))],
            new ExpenseProfile(
                essentialAnnualSpend: Money::fromPounds(30_000),
                discretionaryAnnualSpend: Money::zero(),
                survivorSpendFactor: Percent::fromPercent(100),
                propertyCosts: Money::fromPounds(2_400),
                mortgageCosts: Money::fromPounds(9_000),
                propertyCostsRealGrowth: Percent::zero(),
            ),
            pensions: [new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(150))],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(2_000))],
            primaryResidence: new Property(
                Money::fromPounds(300_000),
                OwnershipType::Outright,
                outstandingMortgage: Money::fromPounds(150_000),
                mortgageMaturityAction: MortgageMaturityAction::Refinance,
            ),
            pensionCreditClaimed: $claimed,
        );
    }

    public static function forecast(Household $h): ForecastResult
    {
        return (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($h, AssumptionSetLibrary::default()
                ->withInflationMean(Percent::fromPercent(0))
                ->withHouseGrowth(Percent::fromPercent(0)), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_an_unclaimed_award_reaches_neither_income_nor_support_for_mortgage_interest(): void
    {
        // The gate: claimed, this household is on Guarantee Credit and SMI meets part of its
        // mortgage, or the unclaimed run below would be proving nothing.
        $claimed = self::forecast(self::claimant(claimed: true));
        $this->assertTrue($claimed->years[0]->incomeBySource['means_tested_benefit']->isPositive());
        $this->assertTrue($claimed->years[0]->smiBalance()->isPositive());

        $unclaimed = self::forecast(self::claimant(claimed: false));
        foreach ($unclaimed->years as $year) {
            $this->assertTrue($year->incomeBySource['means_tested_benefit']->isZero(), "{$year->calendarYear} pays an unclaimed award");
            $this->assertTrue($year->smiBalance()->isZero(), "{$year->calendarYear} meets a mortgage on an unclaimed award");
        }
        // The bill SMI would have met stays in the spend: the household pays its whole essential spend.
        $this->assertSame(30_000_00, $unclaimed->years[0]->spendTarget->pence);
    }
}
