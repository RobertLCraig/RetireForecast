<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\Forecast\ResultPresenter;
use DateTimeImmutable;
use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\EmploymentStatus;
use RetireForecast\FinanceEngine\Dto\ExpenseProfile;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Dto\Person;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Dto\StatePensionEntitlement;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Sweep\Lever\StatePensionDeferralLever;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use Tests\TestCase;

/**
 * The headline the decision-support "defer the survivor's State Pension" lever is built to land
 * (plan Phase 4, "done when"): on a couple, deferring the likely SURVIVOR's State Pension raises
 * the income floor they lean on after the first death — a bigger, guaranteed-for-life uplift they
 * live to collect — while deferring the FIRST-DIER's does nothing for that floor, because a State
 * Pension is not inherited and they are gone before the survivor years.
 *
 * This is the completeness proof that "whose State Pension to defer" is the real question, and that
 * the lever + the delayed-claim model reach the survivor-year floor {@see ResultPresenter::incomeFloor}
 * reports.
 */
final class SurvivorStatePensionDeferralTest extends TestCase
{
    // No database — a pure engine forecast read through the app-layer income-floor presenter.

    /** Flat assumptions (no growth) so the survivor floor moves only on the deferral, not on drift. */
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
     * A couple both reaching State Pension age in 2025, where p1 dies first (at 72) and p2 survives
     * to 95 — so p2 is the survivor whose floor the cliff exposes. Both hold a full State Pension;
     * essentials are pitched so the survivor's coverage has room to move.
     */
    private function couple(): Household
    {
        return new Household(
            'Survivor SP deferral',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1959-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(72)),   // first-dier, dies ~2031
                new Person('p2', new DateTimeImmutable('1959-06-01'), Sex::Female, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge(95)), // survivor, to ~2054
            ],
            new ExpenseProfile(Money::fromPounds(24_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                new StatePensionEntitlement('p1', weeklyForecast: Money::of(230, 25)),
                new StatePensionEntitlement('p2', weeklyForecast: Money::of(230, 25)),
            ],
            relationshipStatus: RelationshipStatus::MarriedOrCivilPartnership,
        );
    }

    /**
     * The survivor-year secure-income coverage of essentials, after deferring $personId's State
     * Pension by $years (0 = the base plan). Runs the deterministic forecast through the same
     * survivor-year floor twin the results page reports.
     */
    private function survivorCoverage(string $personId, float $years): int
    {
        $household = (new StatePensionDeferralLever($personId))
            ->apply($this->couple(), $this->settings(), $years)->household;

        $forecast = (new DeterministicForecaster(TaxYearRegistry::for('2025-26', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flatAssumptions(), $this->settings());

        $floor = ResultPresenter::incomeFloor($forecast);
        $this->assertNotNull($floor);
        $this->assertNotNull($floor['survivor'], 'the couple has a survivor phase to read');

        return $floor['survivor']['coveragePct'];
    }

    private function settings(): ForecastSettings
    {
        return new ForecastSettings(baseYear: 2025, baseTaxYear: '2025-26');
    }

    public function test_deferring_the_survivors_state_pension_raises_the_survivor_floor(): void
    {
        $base = $this->survivorCoverage('p2', 0);
        $deferred = $this->survivorCoverage('p2', 3);

        $this->assertGreaterThan(
            $base,
            $deferred,
            "deferring the survivor's State Pension lifts the guaranteed income they lean on after the first death",
        );
    }

    public function test_deferring_the_first_diers_state_pension_does_not_move_the_survivor_floor(): void
    {
        $base = $this->survivorCoverage('p1', 0);
        $deferred = $this->survivorCoverage('p1', 3);

        // The first-dier is gone before the survivor years and a State Pension is not inherited, so
        // deferring theirs — however large the uplift — never reaches the survivor's floor. This is
        // the whole insight: whose State Pension you defer is what matters.
        $this->assertSame(
            $base,
            $deferred,
            "deferring the first-dier's State Pension does nothing for the survivor's floor",
        );
    }
}
