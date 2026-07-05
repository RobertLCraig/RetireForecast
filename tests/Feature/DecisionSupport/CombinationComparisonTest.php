<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\CombinationComparison;
use App\Enums\ScenarioStatus;
use App\Forecast\ResultPresenter;
use App\Forecast\ScenarioForecaster;
use App\Models\Scenario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use Tests\Support\ScenarioFixture;
use Tests\TestCase;

/**
 * Decision-support Phase 3 — the NEUTRAL combination-comparison presenter: each plan's word-band
 * chip (no decimals), its net-position sparkline, the analyst figures, and the factual
 * surprising-lever callout. Proves an unsimulated plan degrades to no chip (and flags the set
 * incomplete), the chip word-band buckets correctly and never says "safe", and the callout fires
 * only when a longer-life plan actually raises the odds — phrased as an observation, not advice.
 */
final class CombinationComparisonTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->user = User::factory()->create());
    }

    private function forecast(Scenario $scenario)
    {
        return app(ScenarioForecaster::class)->deterministicVariants($scenario)[$scenario->variant->value];
    }

    /** A Monte Carlo result with figures pinned exactly, so the presenter's mapping is deterministic. */
    private function mc(float $ess, float $full, float $depl, ?int $deplYear, int $p10Pence, int $p50Pence, array $netByYear = []): SimulationResult
    {
        $pct = fn (int $a, int $b, int $c): array => [
            'p10' => Money::fromPence($a), 'p25' => Money::fromPence(intdiv($a + $b, 2)),
            'p50' => Money::fromPence($b), 'p75' => Money::fromPence(intdiv($b + $c, 2)), 'p90' => Money::fromPence($c),
        ];

        $net = [];
        foreach ($netByYear as $year => $pence) {
            $net[] = ['calendarYear' => $year, 'paths' => 100] + $pct($pence, $pence, $pence);
        }

        return new SimulationResult(
            nPaths: 2000,
            seed: 42,
            successProbabilityEssentials: $ess,
            successProbabilityFullSpend: $full,
            depletionRate: $depl,
            medianDepletionYear: $deplYear,
            terminalWealthPercentiles: $pct($p10Pence, $p50Pence, $p50Pence + 20_000_00),
            fanChart: [],
            usableWealthPercentiles: $pct($p10Pence, $p50Pence, $p50Pence + 20_000_00),
            usableFanChart: [],
            netPositionFanChart: $net,
        );
    }

    public function test_lasts_band_buckets_probability_to_a_word_with_no_decimals(): void
    {
        $this->assertSame('strong', ResultPresenter::lastsBand(0.95)['level']);
        $this->assertSame('Very likely to last', ResultPresenter::lastsBand(0.95)['word']);
        $this->assertSame('good', ResultPresenter::lastsBand(0.80)['level']);
        $this->assertSame('borderline', ResultPresenter::lastsBand(0.60)['level']);
        $this->assertSame('weak', ResultPresenter::lastsBand(0.30)['level']);
        $this->assertSame('poor', ResultPresenter::lastsBand(0.10)['level']);

        // Guardrail: no neutral word band ever uses "safe".
        foreach ([0.99, 0.80, 0.60, 0.30, 0.05] as $p) {
            $this->assertStringNotContainsStringIgnoringCase('safe', ResultPresenter::lastsBand($p)['word']);
        }
    }

    public function test_a_simulated_plan_gets_a_chip_sparkline_and_figures(): void
    {
        $base = ScenarioFixture::rich($this->user);
        $mc = $this->mc(0.88, 0.62, 0.12, 2051, 15_000_00, 90_000_00, [2030 => 90_000_00, 2051 => -8_000_00]);

        $view = CombinationComparison::build([
            ['scenario' => $base, 'forecast' => $this->forecast($base), 'mc' => $mc],
        ]);

        $row = $view['rows'][0];
        $this->assertTrue($row['hasRun']);
        $this->assertSame('good', $row['chip']['level']);              // 0.88 → "Likely to last"
        $this->assertSame('88%', $row['figures']['successEssentials']); // decimals kept for the analyst
        $this->assertSame('62%', $row['figures']['successFullSpend']);
        $this->assertSame(2000, $row['figures']['paths']);
        $this->assertNotEmpty($row['sparkline']['options']['series'][0]['data']);
        $this->assertTrue($row['sparkline']['dipsNegative']);
        $this->assertSame(2051, $row['sparkline']['runsShortYear']);
        $this->assertFalse($view['anyMissing']);
    }

    public function test_an_unsimulated_plan_has_no_chip_and_marks_the_set_incomplete(): void
    {
        $base = ScenarioFixture::rich($this->user);

        $view = CombinationComparison::build([
            ['scenario' => $base, 'forecast' => $this->forecast($base), 'mc' => null],
        ]);

        $row = $view['rows'][0];
        $this->assertFalse($row['hasRun']);
        $this->assertNull($row['chip']);
        $this->assertNull($row['figures']);
        $this->assertNull($row['sparkline']);
        $this->assertTrue($view['anyMissing']);
    }

    public function test_it_calls_out_a_longer_life_that_raises_the_odds(): void
    {
        $base = ScenarioFixture::rich($this->user);
        $longer = $this->childLivingLonger($base);

        $view = CombinationComparison::build([
            ['scenario' => $base, 'forecast' => $this->forecast($base), 'mc' => $this->mc(0.60, 0.40, 0.40, 2048, 0, 5_000_00)],
            ['scenario' => $longer, 'forecast' => $this->forecast($longer), 'mc' => $this->mc(0.78, 0.55, 0.20, 2060, 0, 20_000_00)],
        ]);

        $this->assertNotNull($view['callout']);
        $this->assertStringContainsString($longer->name, $view['callout']);
        // The callout is an observation, never a recommendation.
        $this->assertStringNotContainsStringIgnoringCase('you should', $view['callout']);
        $this->assertStringNotContainsStringIgnoringCase('recommend', $view['callout']);
        $this->assertStringNotContainsStringIgnoringCase('safe', $view['callout']);
    }

    public function test_no_callout_when_a_longer_life_does_not_raise_the_odds(): void
    {
        $base = ScenarioFixture::rich($this->user);
        $longer = $this->childLivingLonger($base);

        $view = CombinationComparison::build([
            ['scenario' => $base, 'forecast' => $this->forecast($base), 'mc' => $this->mc(0.70, 0.50, 0.30, 2048, 0, 5_000_00)],
            ['scenario' => $longer, 'forecast' => $this->forecast($longer), 'mc' => $this->mc(0.50, 0.30, 0.50, 2060, 0, 1_000_00)],
        ]);

        $this->assertNull($view['callout']);
    }

    /** A what-if child that pushes both partners' modelled lifespans out by 20 years. */
    private function childLivingLonger(Scenario $base): Scenario
    {
        $child = new Scenario;
        $child->user_id = $this->user->id;
        $child->parent_scenario_id = $base->id;
        // List rows are addressed by their id in the delta (people p1/p2), not by position.
        $child->overrides = [
            'name' => 'Live longer',
            'people.p1.longevityMode' => 'offset_years', 'people.p1.longevityValue' => '20',
            'people.p2.longevityMode' => 'offset_years', 'people.p2.longevityValue' => '20',
        ];
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());
        $child->save();

        return $child->fresh();
    }
}
