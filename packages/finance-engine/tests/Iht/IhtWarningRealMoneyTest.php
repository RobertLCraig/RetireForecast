<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Iht;

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
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\ResidenceDisposal;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Iht\IhtResult;
use RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Support\WarningCode;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Board card 0183: the Inheritance Tax warnings are written by the calculator in NOMINAL pounds
 * at the death year, but the projector deflates every money figure on the result to today's
 * money. A warning that names an amount must name the same real amount the panel shows beside it,
 * not a figure twenty years of inflation larger.
 */
final class IhtWarningRealMoneyTest extends TestCase
{
    public function test_iht_warning_amounts_are_in_real_money(): void
    {
        // One person, aged 80, dying at 100: twenty years of the default inflation between today
        // and the death year. The home was sold before the plan (downsizing addition), the pension
        // is never drawn (in the estate from April 2027), and a death past 75 taxes the beneficiary.
        $household = new Household(
            'Late death',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1946-01-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(100))],
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::fromPounds(230)),
                new DcPension('p1', Money::fromPounds(300_000), Money::zero(), Money::zero(), earliestAccessAge: 57),
            ],
            accounts: [new Account('p1', AccountType::Cash, Money::fromPounds(600_000))],
            formerResidenceDisposal: new ResidenceDisposal(Money::fromPounds(400_000), 2024),
        );
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27', modelIht: true, homeToDescendants: true);

        $iht = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, AssumptionSetLibrary::default(), $settings)
            ->iht;

        $this->assertNotNull($iht);
        $death = $iht->secondDeath;

        // Each warning must name the real figure on the result it sits beside.
        $this->assertTrue($death->downsizingAddition->isPositive());
        $this->assertStringContainsString($death->downsizingAddition->format(), $this->message($death, WarningCode::IHT_DOWNSIZING_ADDITION));

        $this->assertTrue($death->unusedPensionPassing->isPositive());
        $this->assertStringContainsString($death->unusedPensionPassing->format(), $this->message($death, WarningCode::IHT_PENSIONS_IN_ESTATE));

        $beneficiary = $this->message($death, WarningCode::IHT_INHERITED_PENSION_INCOME_TAX);
        $this->assertStringContainsString($death->unusedPensionPassing->format(), $beneficiary);
        $this->assertStringContainsString($death->beneficiaryIncomeTax->format(), $beneficiary);

        // A sum fixed in law (the statutory legacy, the capped spouse exemption) keeps its legal
        // figure but names its real value beside it. Halved money makes the arithmetic visible.
        $first = (new InheritanceTaxCalculator(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi)))->computeFirstDeath(
            Money::fromPounds(800_000),
            Money::zero(),
            false,
            spouseSurvives: true,
            deceasedLeftAWill: false,
            issueTakeUnderIntestacy: true,
            survivorIsUkLongTermResident: false,
            realFactor: 0.5,
        );
        $this->assertStringContainsString('£322,000.00 (£161,000.00 in today\'s money)', $this->message($first, WarningCode::IHT_INTESTACY));
        $this->assertStringContainsString('£119,500.00 here', $this->message($first, WarningCode::IHT_INTESTACY), 'the children take half of £478,000, halved');
        $this->assertStringContainsString('£325,000.00 (£162,500.00 in today\'s money)', $this->message($first, WarningCode::IHT_SPOUSE_EXEMPTION_CAPPED));
    }

    private function message(IhtResult $result, string $code): string
    {
        foreach ($result->warnings as $warning) {
            if ($warning->code === $code) {
                return $warning->message;
            }
        }

        $this->fail("no {$code} warning on the result");
    }
}
