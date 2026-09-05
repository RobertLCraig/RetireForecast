<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Forecast;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
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
 * DB commutation: taking a tax-free lump sum at retirement in exchange for a permanently lower
 * pension. Before the fix commutationLumpSum/commutationFactor were collected but consumed by no
 * engine code (a silent-drop). These tests pin that a commutation reaches the forecast both ways —
 * the annual pension is reduced by lumpSum ÷ factor, and the lump sum is paid tax-free in the year
 * the member reaches normal retirement age — that a null factor defaults to 12, and that a pension
 * with no commutation is unchanged.
 */
final class DbCommutationTest extends TestCase
{
    /** Flat assumptions (no inflation, no growth) so figures stay clean nominal == real amounts. */
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

    /**
     * A retired person aged 60 in 2026 (reaches the DB normal retirement age of 65 in 2031),
     * holding a £20k DB pension with the given commutation. Returns income-by-source per year.
     *
     * @return array<int, array{db: int, lumpSum: int}> calendarYear => defined_benefit + lump-sum pence
     */
    private function bySource(?Money $commutationLumpSum, ?float $commutationFactor): array
    {
        $household = new Household(
            'Commutation',
            RegionProfile::EnglandWalesNi,
            [new Person('p1', new DateTimeImmutable('1966-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(90))],
            new ExpenseProfile(Money::fromPounds(15_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(150, 0)),
                new DbPension('p1', Money::fromPounds(20_000), normalRetirementAge: 65,
                    commutationLumpSum: $commutationLumpSum, commutationFactor: $commutationFactor),
            ],
            accounts: [],
        );

        $result = (new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flatAssumptions(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        $out = [];
        foreach ($result->years as $y) {
            $out[$y->calendarYear] = [
                'db' => $y->incomeBySource['defined_benefit']->pence,
                'lumpSum' => $y->incomeBySource['pension_lump_sum']->pence,
            ];
        }

        return $out;
    }

    public function test_commutation_reduces_the_pension_and_pays_a_tax_free_lump_sum_at_retirement(): void
    {
        // £24k lump sum at factor 12 gives up £2,000/yr → an £18,000 pension from age 65 (2031).
        // 2031 itself is a PART year (the pension starts on the January birthday, board card 0036),
        // so the annual rate is read from 2032, the first whole year.
        $s = $this->bySource(Money::fromPounds(24_000), 12.0);

        $this->assertSame(0, $s[2030]['db'], 'no DB income before normal retirement age');
        $this->assertSame(Money::fromPounds(18_000)->pence, $s[2032]['db'], 'the pension is permanently reduced by the commutation');
        $this->assertSame(Money::fromPounds(18_000)->pence, $s[2035]['db']);

        // The tax-free lump sum lands once, in the year the member turns 65.
        $this->assertSame(0, $s[2030]['lumpSum']);
        $this->assertSame(Money::fromPounds(24_000)->pence, $s[2031]['lumpSum'], 'the lump sum is paid tax-free at retirement');
        $this->assertSame(0, $s[2032]['lumpSum'], 'and only once');
    }

    public function test_a_null_factor_defaults_to_twelve(): void
    {
        // Same £24k lump sum, no explicit factor → defaults to 12 → the same £18,000 reduced pension.
        $s = $this->bySource(Money::fromPounds(24_000), null);

        $this->assertSame(Money::fromPounds(18_000)->pence, $s[2032]['db']);
        $this->assertSame(Money::fromPounds(24_000)->pence, $s[2031]['lumpSum']);
    }

    public function test_no_commutation_leaves_the_full_pension_and_no_lump_sum(): void
    {
        $s = $this->bySource(null, null);

        $this->assertSame(Money::fromPounds(20_000)->pence, $s[2032]['db'], 'the full pension without a commutation');
        $this->assertSame(0, $s[2031]['lumpSum']);
        $this->assertSame(0, $s[2035]['lumpSum']);
    }
}
