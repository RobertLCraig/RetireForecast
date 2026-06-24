<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\MonteCarlo;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use RuntimeException;

final class SimulatorProgressTest extends TestCase
{
    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    private function household(): Household
    {
        return new Household(
            'Progress couple',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
                new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired),
            ],
            new ExpenseProfile(Money::fromPounds(18_000), Money::fromPounds(4_000), Percent::fromPercent(70)),
            [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
                new DcPension('p2', Money::fromPounds(300_000), Money::zero(), Money::zero(), 55),
            ],
            primaryResidence: new Property(Money::fromPounds(400_000), OwnershipType::Outright),
        );
    }

    public function test_simulator_reports_progress_ending_at_the_final_path(): void
    {
        $calls = [];
        (new Simulator(TaxYearRegistry::for('2026-27')))->run(
            $this->household(),
            $this->settings(),
            AssumptionSetLibrary::default(),
            new CohortLifeTable,
            nPaths: 40,
            seed: 7,
            onProgress: function (int $completed, int $total) use (&$calls): void {
                $calls[] = [$completed, $total];
            },
        );

        $this->assertNotEmpty($calls);
        $this->assertSame([40, 40], end($calls));

        $previous = 0;
        foreach ($calls as [$completed]) {
            $this->assertGreaterThanOrEqual($previous, $completed);
            $previous = $completed;
        }
    }

    public function test_throwing_from_the_progress_hook_aborts_the_run(): void
    {
        $this->expectException(RuntimeException::class);

        (new Simulator(TaxYearRegistry::for('2026-27')))->run(
            $this->household(),
            $this->settings(),
            AssumptionSetLibrary::default(),
            new CohortLifeTable,
            nPaths: 40,
            seed: 7,
            onProgress: function (int $completed): void {
                if ($completed >= 5) {
                    throw new RuntimeException('cancelled');
                }
            },
        );
    }

    public function test_housing_comparison_progress_is_monotonic_and_completes(): void
    {
        $fractions = [];
        (new HousingComparison(TaxYearRegistry::for('2026-27'), new CohortLifeTable))->compare(
            $this->household(),
            $this->settings(),
            AssumptionSetLibrary::default(),
            new HousingAction(salePrice: Money::fromPounds(400_000), buyPrice: Money::fromPounds(250_000), annualRent: Money::fromPounds(14_000)),
            nPaths: 20,
            seed: 7,
            onProgress: function (float $fraction) use (&$fractions): void {
                $fractions[] = $fraction;
            },
        );

        $this->assertNotEmpty($fractions);
        $this->assertGreaterThan(0.99, end($fractions));

        $previous = 0.0;
        foreach ($fractions as $fraction) {
            $this->assertGreaterThanOrEqual($previous - 1e-9, $fraction);
            $previous = $fraction;
        }
    }
}
