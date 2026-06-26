<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * The PLSA Retirement Living Standards benchmark on the results page. The trust-critical
 * property is the same one the data-layer rule demands everywhere: the figure benchmarked
 * is exactly the spend the forecast runs on, put on the PLSA basis — lifestyle spend
 * (essential + discretionary, never the *saved* self-investment) plus owned-home running
 * costs, with rent/mortgage excluded. If that reconciliation slips, the benchmark lies.
 */
final class PlsaBenchmarkTest extends TestCase
{
    /** @param  array<string, mixed>  $state */
    private function household(array $state): Household
    {
        return (new HouseholdAssembler)->household($state);
    }

    public function test_comparable_spend_is_lifestyle_spend_plus_home_running_costs(): void
    {
        // Couple, owning a home: essential £20,000 + discretionary £10,000 + running costs
        // £3,000 = £33,000 on the PLSA basis (rent/mortgage excluded, running costs in).
        $plsa = ResultPresenter::plsaBenchmark($this->household([
            'householdName' => 'Owners', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'expenseLines' => [
                ['id' => 'e1', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'd1', 'amount' => '10000', 'category' => 'discretionary'],
            ],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '400000', 'ownership' => 'outright', 'runningCosts' => '3000'],
        ]));

        $this->assertNotNull($plsa);
        $this->assertSame(Money::fromPounds(33_000)->format(), $plsa['comparableSpend']);
        $this->assertTrue($plsa['couple']);
        $this->assertSame('couple', $plsa['composition']);
        $this->assertTrue($plsa['runningCostsIncluded']);

        // Couple, outside London: Minimum £22,500 (met), Moderate £45,400 (not).
        $this->assertSame('minimum', $plsa['tierReached']);
        $this->assertSame('Minimum', $plsa['tierReachedLabel']);
        $this->assertFalse($plsa['belowMinimum']);
        $this->assertSame('moderate', $plsa['nextTier']);
        $this->assertSame(Money::fromPounds(12_400)->format(), $plsa['gapToNext']); // £45,400 - £33,000
    }

    public function test_saved_self_investment_is_not_counted_as_spend(): void
    {
        // A *saved* self-investment line builds net worth, not spend — exactly as the
        // forecast's ExpenseProfile excludes it. The benchmark must use the same figure:
        // comparable spend = essential £20,000 only, NOT £30,000 (gotcha O, one home per pound).
        $plsa = ResultPresenter::plsaBenchmark($this->household([
            'householdName' => 'Saver', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'e1', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 's1', 'amount' => '10000', 'category' => 'self_investment', 'savedAsAsset' => true],
            ],
            'expense' => ['survivorFactor' => '70'],
        ]));

        $this->assertNotNull($plsa);
        $this->assertSame(Money::fromPounds(20_000)->format(), $plsa['comparableSpend']);
        $this->assertFalse($plsa['runningCostsIncluded']);
    }

    public function test_single_person_with_no_home_excludes_housing_and_reaches_its_tier(): void
    {
        // Single, no owned home (a renter — rent is never part of the household, so it is
        // excluded by construction). Lifestyle spend £40,000 → single Moderate (£32,700) met,
        // Comfortable (£45,400) not.
        $plsa = ResultPresenter::plsaBenchmark($this->household([
            'householdName' => 'Renter', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [['id' => 'e1', 'amount' => '40000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]));

        $this->assertNotNull($plsa);
        $this->assertSame('single person', $plsa['composition']);
        $this->assertFalse($plsa['couple']);
        $this->assertFalse($plsa['runningCostsIncluded']);
        $this->assertSame(Money::fromPounds(40_000)->format(), $plsa['comparableSpend']);
        $this->assertSame('moderate', $plsa['tierReached']);
        $this->assertSame('comfortable', $plsa['nextTier']);
    }

    public function test_spend_below_the_minimum_reaches_no_tier(): void
    {
        $plsa = ResultPresenter::plsaBenchmark($this->household([
            'householdName' => 'Modest', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => [['id' => 'e1', 'amount' => '10000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]));

        $this->assertNotNull($plsa);
        $this->assertNull($plsa['tierReached']);
        $this->assertTrue($plsa['belowMinimum']);
        $this->assertSame('minimum', $plsa['nextTier']);
    }

    public function test_the_three_tier_rows_carry_figures_met_flags_and_provenance(): void
    {
        $plsa = ResultPresenter::plsaBenchmark($this->household([
            'householdName' => 'Provenance', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'expenseLines' => [['id' => 'e1', 'amount' => '33000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]));

        $this->assertNotNull($plsa);
        $this->assertSame(['minimum', 'moderate', 'comfortable'], array_column($plsa['tiers'], 'key'));
        // Single £33,000: Minimum (£13,900) and Moderate (£32,700) met, Comfortable (£45,400) not.
        $this->assertSame([true, true, false], array_column($plsa['tiers'], 'met'));

        // No magic numbers: the readout carries the figures' source and verified-on date.
        $this->assertStringContainsString('retirementlivingstandards.org.uk', $plsa['source']);
        $this->assertSame('2026-06-26', $plsa['verifiedOn']);
        $this->assertNotSame('', $plsa['edition']);
    }
}
