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
 * Deferring the State Pension delays the CLAIM, it is not a free uplift. The person forgoes the
 * payments for the deferral period, then draws an uplifted rate for life from the later start —
 * so an early death after deferring is a net lifetime loss. Before this fix the projection paid
 * the uplifted rate from State Pension age with no forgone income, which made deferral a free
 * lunch (always beneficial) and would have made the decision-support "defer the survivor's SP"
 * lever meaningless. These tests pin the trade-off: forgone income during the window, the uplift
 * from the later start, and the early-death loss that gives the lever its non-monotone shape.
 */
final class StatePensionDeferralTest extends TestCase
{
    /** Flat assumptions (no growth, no inflation) so figures stay clean; the State Pension still rises by the triple-lock proxy. */
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
     * A lone retired person born 1959 (State Pension age 66, reached 2025), with a full new State
     * Pension, deferring $deferralWeeks and living to $deathAge. Returns their State Pension income
     * by calendar year.
     *
     * @return array<int, int> calendarYear => state_pension pence
     */
    private function spByYear(int $deferralWeeks, int $deathAge): array
    {
        $household = new Household(
            'Deferral',
            RegionProfile::EnglandWalesNi,
            [
                new Person('p1', new DateTimeImmutable('1959-01-01'), Sex::Male, EmploymentStatus::Retired, longevity: LongevityAdjustment::fixedAge($deathAge)),
            ],
            new ExpenseProfile(Money::fromPounds(12_000), Money::zero(), Percent::fromPercent(70)),
            pensions: [
                (new StatePensionEntitlement('p1', weeklyForecast: Money::of(230, 25)))->withDeferralWeeks($deferralWeeks),
            ],
        );

        $result = (new DeterministicForecaster(TaxYearRegistry::for('2025-26', RegionProfile::EnglandWalesNi), new CohortLifeTable))
            ->forecast($household, $this->flatAssumptions(), new ForecastSettings(baseYear: 2025, baseTaxYear: '2025-26'));

        $sp = [];
        foreach ($result->years as $y) {
            $sp[$y->calendarYear] = $y->incomeBySource['state_pension']->pence;
        }

        return $sp;
    }

    public function test_deferring_forgoes_the_pension_until_the_later_claim(): void
    {
        $undeferred = $this->spByYear(deferralWeeks: 0, deathAge: 95);
        $deferred = $this->spByYear(deferralWeeks: 104, deathAge: 95); // 2 years → claim 2027

        // Undeferred: the pension is in payment from State Pension age (2025).
        $this->assertGreaterThan(0, $undeferred[2025], 'an undeferred State Pension is paid from State Pension age');
        $this->assertGreaterThan(0, $undeferred[2026]);

        // Deferred by 2 years: nothing is received during the deferral window — that forgone income
        // is the cost the old free-uplift model ignored.
        $this->assertSame(0, $deferred[2025], 'a deferred State Pension pays nothing in the deferral window');
        $this->assertSame(0, $deferred[2026]);
        $this->assertGreaterThan(0, $deferred[2027], 'the deferred pension starts at the later claim year');
    }

    public function test_the_uplift_applies_from_the_later_start(): void
    {
        $undeferred = $this->spByYear(deferralWeeks: 0, deathAge: 95);
        $deferred = $this->spByYear(deferralWeeks: 104, deathAge: 95);

        // 2027 is the deferred claim year and a PART year (the claim starts on the January
        // entitlement date, board card 0036), so the annual RATE is read from 2028, the first whole
        // year for both. There the deferred pension is the uplifted rate (2 years ≈ 11.5% more), so
        // it is materially higher than the same year's undeferred figure (same triple-lock uprating).
        $this->assertGreaterThan($undeferred[2028], $deferred[2028], 'the deferred pension is uplifted once it starts');
        $ratio = $deferred[2028] / $undeferred[2028];
        $this->assertGreaterThan(1.10, $ratio, 'roughly the 1%-per-9-weeks uplift over 104 weeks');
        $this->assertLessThan(1.13, $ratio);
    }

    public function test_an_early_death_after_deferring_is_a_net_lifetime_loss(): void
    {
        // Dies at 69 (2028), a year after a 2-year deferral would start paying: the deferrer collects
        // one uplifted year, the person who claimed on time collected four base years. The forgone
        // income is not recouped — this is exactly why the lever is not monotone in the deferral.
        $undeferred = array_sum($this->spByYear(deferralWeeks: 0, deathAge: 69));
        $deferred = array_sum($this->spByYear(deferralWeeks: 104, deathAge: 69));

        $this->assertGreaterThan($deferred, $undeferred, 'deferring then dying early collects less lifetime State Pension');
    }
}
