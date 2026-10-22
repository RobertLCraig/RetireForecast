<?php

declare(strict_types=1);

namespace Tests\Feature\Forecast;

use App\Forecast\ResultPresenter;
use App\Models\Result;
use DateTimeImmutable;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use Tests\TestCase;

/**
 * The verdict's one headline odds figure and the run-out year read as ages (board card 0216).
 */
final class VerdictHeadlineTest extends TestCase
{
    public function test_the_run_out_year_is_given_with_ages(): void
    {
        $household = new Household('h', RegionProfile::EnglandWalesNi, [
            new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired),
            new Person('p2', new DateTimeImmutable('1960-09-01'), Sex::Male, EmploymentStatus::Retired),
        ], new ExpenseProfile(Money::fromPounds(20_000), Money::zero(), Percent::fromPercent(70)));

        $built = ResultPresenter::build(collect(['rent' => $this->simResult(0.35, 2049)]), 'rent', household: $household);

        $this->assertSame('91 / 89', $built['variants']['rent']['depletionAges']);
    }

    public function test_ten_out_of_ten_is_said_only_when_no_future_runs_short(): void
    {
        $lasts = fn (float $depletion): int => ResultPresenter::build(collect(['rent' => $this->simResult($depletion, null)]), 'rent')['variants']['rent']['lastsOutOfTen'];

        $this->assertSame(10, $lasts(0.0));
        $this->assertSame(9, $lasts(0.01));
        $this->assertSame(6, $lasts(0.35));
        $this->assertSame(0, $lasts(1.0));
    }

    private function simResult(float $depletion, ?int $depletionYear): Result
    {
        $band = fn (int $p50): array => [
            'p10' => Money::fromPounds($p50 - 50_000), 'p25' => Money::fromPounds($p50 - 20_000),
            'p50' => Money::fromPounds($p50), 'p75' => Money::fromPounds($p50 + 20_000), 'p90' => Money::fromPounds($p50 + 50_000),
        ];
        $fan = [['calendarYear' => 2026, 'paths' => 100, ...$band(300_000)], ['calendarYear' => 2027, 'paths' => 100, ...$band(290_000)]];

        $sim = new SimulationResult(
            nPaths: 100,
            seed: 1,
            successProbabilityEssentials: 1.0 - $depletion,
            successProbabilityFullSpend: 0.5,
            depletionRate: $depletion,
            medianDepletionYear: $depletionYear,
            terminalWealthPercentiles: $band(300_000),
            fanChart: $fan,
            usableWealthPercentiles: $band(200_000),
            usableFanChart: $fan,
        );

        return (new Result(['variant' => 'rent']))->setSimulationResult($sim);
    }
}
