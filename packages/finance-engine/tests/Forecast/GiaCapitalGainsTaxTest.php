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
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * A5: when a GIA holding is sold to fund spending, the pro-rata gain is realised and
 * taxed as CGT (after the annual exempt amount). A holding with no embedded gain pays
 * nothing — so the tax tracks the gain, not the disposal. This is the disposal tax the
 * forecast previously omitted; understating it flattered every unwrapped-asset plan.
 */
final class GiaCapitalGainsTaxTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /**
     * A retired couple over State Pension age whose spend outruns their pensions, so they
     * must sell from p1's £300k GIA in the base year. $unrealisedGain sets the embedded gain.
     */
    private function household(Money $unrealisedGain): Household
    {
        return new Household(
            'Drawing the GIA',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(60_000), Money::zero(), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
            ],
            [new Account('p1', AccountType::Gia, Money::fromPounds(300_000), unrealisedGain: $unrealisedGain)],
        );
    }

    public function test_disposing_a_gainful_gia_incurs_cgt_where_a_no_gain_one_does_not(): void
    {
        // £200k of the £300k GIA is gain; the year-0 shortfall sells a slice and realises a
        // pro-rata gain, taxed as CGT after the £3k exempt amount.
        $withGain = $this->forecaster()->forecast($this->household(Money::fromPounds(200_000)), AssumptionSetLibrary::default(), $this->settings())->years[0];
        // Same balance, no embedded gain (basis == balance): selling realises nothing to tax.
        $noGain = $this->forecaster()->forecast($this->household(Money::zero()), AssumptionSetLibrary::default(), $this->settings())->years[0];

        $this->assertGreaterThan($noGain->totalTax->pence, $withGain->totalTax->pence, 'realised GIA gains must be taxed as CGT');
        // The difference is material CGT, not rounding: a ~£23k realised gain (less £3k AEA)
        // taxed at 18% basic is several thousand pounds.
        $this->assertGreaterThan(100_000, $withGain->totalTax->pence - $noGain->totalTax->pence);
    }

    public function test_a_gainful_gia_depletes_faster_than_a_no_gain_one(): void
    {
        // Over the run the CGT drag means the gainful holding funds fewer years / less wealth.
        $withGain = $this->forecaster()->forecast($this->household(Money::fromPounds(200_000)), AssumptionSetLibrary::default(), $this->settings());
        $noGain = $this->forecaster()->forecast($this->household(Money::zero()), AssumptionSetLibrary::default(), $this->settings());

        $this->assertLessThanOrEqual($noGain->terminalTotalWealth->pence, $withGain->terminalTotalWealth->pence);
    }
}
