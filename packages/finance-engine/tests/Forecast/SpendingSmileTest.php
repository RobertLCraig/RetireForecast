<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\SpendPath;
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
 * The age-banded spend path (the "smile") reaching the projection: the per-year spend target
 * must step at the reference person's band age, essentials must hold when only discretionary
 * bands, and the target must reconcile to essential + discretionary at every age. Flat
 * assumptions (no inflation/growth) keep nominal == real, and a couple who stay alive keeps the
 * survivor factor at 1.0, so the target equals the raw banded figure.
 */
final class SpendingSmileTest extends TestCase
{
    private function forecaster(): DeterministicForecaster
    {
        return new DeterministicForecaster(TaxYearRegistry::for('2026-27'), new CohortLifeTable);
    }

    private function flatAssumptions(): AssumptionSet
    {
        return new AssumptionSet(
            name: 'flat', sourceNote: 'test',
            assetClasses: [
                new AssetClassAssumption('Equity', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Bond', Percent::zero(), Percent::zero()),
                new AssetClassAssumption('Cash', Percent::zero(), Percent::zero()),
            ],
            correlationMatrix: [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
            inflationMean: Percent::zero(), inflationVolatility: Percent::zero(),
            houseGrowth: Percent::zero(), rentInflation: Percent::zero(),
            salaryGrowth: Percent::zero(), investmentIncomeYield: Percent::zero(),
        );
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
    }

    /** A retired couple, both born 1958 (aged 68 in 2026), with matching State Pensions. */
    private function couple(ExpenseProfile $expense): Household
    {
        $p1 = new Person('p1', new DateTimeImmutable('1958-04-01'), Sex::Female, EmploymentStatus::Retired);
        $p2 = new Person('p2', new DateTimeImmutable('1958-09-01'), Sex::Male, EmploymentStatus::Retired);

        return new Household('Test', RegionProfile::EnglandWalesNi, [$p1, $p2], $expense, [
            new StatePensionEntitlement('p1', weeklyForecast: Money::of(241, 30)),
            new StatePensionEntitlement('p2', weeklyForecast: Money::of(241, 30)),
        ]);
    }

    /**
     * @return array<int, array{target: int, essential: int, alive: int}> keyed by p1's age
     */
    private function spendByP1Age(ForecastResult $result): array
    {
        $out = [];
        foreach ($result->years as $y) {
            $out[$y->ages['p1']] = [
                'target' => $y->spendTarget->pence,
                'essential' => $y->essentialSpend->pence,
                'alive' => $y->aliveCount,
            ];
        }

        return $out;
    }

    public function test_a_discretionary_smile_steps_the_target_at_the_band_age(): void
    {
        // Essentials flat £20k; discretionary £15k to age 77, then £5k — a go-go/slow-go step.
        $profile = new ExpenseProfile(
            essentialAnnualSpend: Money::fromPounds(20_000),
            discretionaryAnnualSpend: Money::fromPounds(15_000),
            survivorSpendFactor: Percent::fromPercent(70),
            discretionarySpendPath: SpendPath::fromBands([
                ['fromAge' => 68, 'amount' => Money::fromPounds(15_000)],
                ['fromAge' => 77, 'amount' => Money::fromPounds(5_000)],
            ]),
        );

        $spend = $this->spendByP1Age($this->forecaster()->forecast($this->couple($profile), $this->flatAssumptions(), $this->settings()));

        // Both alive across the window, so the survivor factor stays 1.0 and the arithmetic is clean.
        foreach ([68, 76, 77, 80] as $age) {
            $this->assertSame(2, $spend[$age]['alive'], "expected both alive at p1 age {$age}");
        }

        // The target steps £35k -> £25k exactly at the band age; essentials never move.
        $this->assertSame(Money::fromPounds(35_000)->pence, $spend[68]['target']);
        $this->assertSame(Money::fromPounds(35_000)->pence, $spend[76]['target']); // last go-go year
        $this->assertSame(Money::fromPounds(25_000)->pence, $spend[77]['target']); // slow-go begins
        $this->assertSame(Money::fromPounds(25_000)->pence, $spend[80]['target']);

        foreach ([68, 76, 77, 80] as $age) {
            $this->assertSame(Money::fromPounds(20_000)->pence, $spend[$age]['essential'], "essentials moved at age {$age}");
        }
    }

    public function test_the_target_reconciles_to_essential_plus_discretionary_at_every_age(): void
    {
        $profile = new ExpenseProfile(
            essentialAnnualSpend: Money::fromPounds(20_000),
            discretionaryAnnualSpend: Money::fromPounds(15_000),
            survivorSpendFactor: Percent::fromPercent(70),
            discretionarySpendPath: SpendPath::fromBands([
                ['fromAge' => 68, 'amount' => Money::fromPounds(15_000)],
                ['fromAge' => 77, 'amount' => Money::fromPounds(5_000)],
            ]),
        );

        $result = $this->forecaster()->forecast($this->couple($profile), $this->flatAssumptions(), $this->settings());

        // In every year the target's decline is entirely the discretionary band stepping — the
        // implied discretionary (target - essential) is £15k before the band and £5k from it.
        foreach ($result->years as $y) {
            if ($y->aliveCount !== 2) {
                continue; // the survivor factor scales both; the reconciliation is what we pin here
            }
            $impldiscretionary = $y->spendTarget->pence - $y->essentialSpend->pence;
            $expected = $y->ages['p1'] >= 77 ? Money::fromPounds(5_000)->pence : Money::fromPounds(15_000)->pence;
            $this->assertSame($expected, $impldiscretionary, "discretionary wrong at p1 age {$y->ages['p1']}");
        }
    }

    public function test_an_essential_band_steps_the_floor(): void
    {
        // Per-line-item scope allows any line to band — here the essential floor rises later in life.
        $profile = new ExpenseProfile(
            essentialAnnualSpend: Money::fromPounds(20_000),
            discretionaryAnnualSpend: Money::zero(),
            survivorSpendFactor: Percent::fromPercent(70),
            essentialSpendPath: SpendPath::fromBands([
                ['fromAge' => 68, 'amount' => Money::fromPounds(20_000)],
                ['fromAge' => 80, 'amount' => Money::fromPounds(26_000)],
            ]),
        );

        $spend = $this->spendByP1Age($this->forecaster()->forecast($this->couple($profile), $this->flatAssumptions(), $this->settings()));

        $this->assertSame(Money::fromPounds(20_000)->pence, $spend[68]['essential']);
        $this->assertSame(Money::fromPounds(20_000)->pence, $spend[79]['essential']);
        $this->assertSame(Money::fromPounds(26_000)->pence, $spend[80]['essential']);
    }

    public function test_a_flat_plan_holds_the_same_target_at_every_age(): void
    {
        // The no-smile case: a flat profile (no path) must be constant — the equivalence anchor.
        $profile = new ExpenseProfile(
            Money::fromPounds(20_000),
            Money::fromPounds(15_000),
            Percent::fromPercent(70),
        );

        $spend = $this->spendByP1Age($this->forecaster()->forecast($this->couple($profile), $this->flatAssumptions(), $this->settings()));

        foreach ([68, 77, 85] as $age) {
            if (($spend[$age]['alive'] ?? 0) !== 2) {
                continue;
            }
            $this->assertSame(Money::fromPounds(35_000)->pence, $spend[$age]['target']);
        }
    }
}
