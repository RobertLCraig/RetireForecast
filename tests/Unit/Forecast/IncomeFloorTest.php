<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The income-floor readout: essential spending vs secure (guaranteed-for-life, non-pot)
 * income at the mature point. Two properties matter for trust:
 *  - completeness — every secure source the household has reaches the floor, and the full
 *    essential need is shown even when nothing is secured (no silent drop);
 *  - the branch is right — a surplus reads as covered, a shortfall reads as a gap met from
 *    savings, never as a recommendation.
 */
final class IncomeFloorTest extends TestCase
{
    /** @param  array<string, mixed>  $state */
    private function forecast(array $state): ForecastResult
    {
        $household = (new HouseholdAssembler)->household($state);
        $forecaster = new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        );

        return $forecaster->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_secure_income_above_essentials_reads_as_a_covered_surplus(): void
    {
        // A retired couple over State Pension age, two full State Pensions, modest essentials.
        $floor = ResultPresenter::incomeFloor($this->forecast([
            'householdName' => 'Covered', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'dob' => '1953-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'dob' => '1953-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'expenseLines' => [['id' => 'e', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]));

        $this->assertNotNull($floor);
        $this->assertTrue($floor['fullyCovered']);
        $this->assertNull($floor['gap']);
        $this->assertNotNull($floor['surplus']);
        $this->assertGreaterThanOrEqual(100, $floor['coveragePct']);
        // The State Pension is counted as secure; only secure sources appear.
        $this->assertContains('State Pension', array_column($floor['sources'], 'label'));
    }

    public function test_with_no_guaranteed_income_the_whole_essential_is_a_gap(): void
    {
        // A couple with no pensions of any kind, funding spend from a large cash pot: no
        // secure income ever, so the full essential floor is met from savings (a gap). This
        // is the completeness guard — the essential need still reaches the readout.
        $floor = ResultPresenter::incomeFloor($this->forecast([
            'householdName' => 'Gap', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'not_working'],
                ['id' => 'p2', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'not_working'],
            ],
            'pensions' => [],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '600000']],
            'expenseLines' => [['id' => 'e', 'amount' => '20000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]));

        $this->assertNotNull($floor);
        $this->assertSame(Money::zero()->format(), $floor['secureIncome']);
        $this->assertFalse($floor['fullyCovered']);
        $this->assertNull($floor['surplus']);
        $this->assertSame(0, $floor['coveragePct']);
        $this->assertSame([], $floor['sources']);
        // With nothing secured, the whole essential need is the gap (internal consistency,
        // independent of the exact figure): gap == essential spend.
        $this->assertSame($floor['essentialSpend'], $floor['gap']);
    }

    public function test_tax_free_income_is_counted_in_the_secure_floor(): void
    {
        // A tax-free income stream (e.g. DLA) must be counted as secure — the exact class of
        // input a past bug dropped. With it, the floor is non-zero and lists tax-free income.
        $floor = ResultPresenter::incomeFloor($this->forecast([
            'householdName' => 'DLA', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'not_working']],
            'pensions' => [],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '300000']],
            'incomeStreams' => [['id' => 'inc1', 'ownerId' => 'p1', 'type' => 'other', 'grossAnnual' => '6000',
                'taxable' => false, 'inflationLinked' => true, 'startAge' => '0', 'endAge' => '']],
            'expenseLines' => [['id' => 'e', 'amount' => '20000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]));

        $this->assertNotNull($floor);
        $this->assertContains('Tax-free income', array_column($floor['sources'], 'label'));
        $this->assertGreaterThan(0, $floor['coveragePct']);
    }
}
