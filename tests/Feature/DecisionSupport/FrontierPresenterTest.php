<?php

declare(strict_types=1);

namespace Tests\Feature\DecisionSupport;

use App\DecisionSupport\FrontierOutcome;
use App\DecisionSupport\FrontierPresenter;
use App\DecisionSupport\LeverKey;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Sweep\Crossing;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;
use RetireForecast\FinanceEngine\Sweep\Frontier;
use RetireForecast\FinanceEngine\Sweep\FrontierPoint;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepCurve;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use RetireForecast\FinanceEngine\Sweep\SweepPoint;

/**
 * The trade-off-map view models (Phase 5): the simple summary pins BOTH levers (never an
 * unconditional single number), each column carries an honest banded chip, the heatmap keeps
 * every measured cell with its percentage as text (colour never carries meaning alone), and the
 * neutral-copy guardrails hold ("safe" never appears).
 */
final class FrontierPresenterTest extends TestCase
{
    /**
     * A buy-price × retirement-age frontier column: cells over $probs (keyed by buy price,
     * ascending) and the crossing verdict the caller wants.
     *
     * @param  array<int|float, float>  $probs
     */
    private function column(float $age, array $probs, Crossing $crossing): FrontierPoint
    {
        $points = [];
        foreach ($probs as $price => $p) {
            $points[] = new SweepPoint((float) $price, $p, max(0.0, $p - 0.02), min(1.0, $p + 0.02), 500);
        }

        return new FrontierPoint($age, $crossing, new SweepCurve(
            $points, SweepMetric::Essentials, LeverDirection::Decreasing, 'buy price', '£', 500, 1,
        ));
    }

    /**
     * @param  list<FrontierPoint>  $points
     */
    private function outcome(array $points): FrontierOutcome
    {
        return new FrontierOutcome(
            thresholdLever: LeverKey::BuyPrice,
            conditionLever: LeverKey::RetirementAge,
            metric: SweepMetric::Essentials,
            targetProbability: 0.90,
            frontier: new Frontier($points, 'buy price', 'retirement age', SweepMetric::Essentials, 0.90, 500, 1),
        );
    }

    private function crossingAt(float $estimate): Crossing
    {
        return new Crossing(CrossingVerdict::Crosses, 0.90, $estimate - 25_000.0, $estimate + 25_000.0, $estimate);
    }

    public function test_the_summary_pins_both_levers_and_stays_neutral(): void
    {
        $view = FrontierPresenter::view($this->outcome([
            $this->column(62.0, [200_000 => 0.95, 300_000 => 0.80], $this->crossingAt(250_000.0)),
            $this->column(70.0, [200_000 => 0.98, 300_000 => 0.88], $this->crossingAt(310_000.0)),
        ]));

        // The one-liner names BOTH ends of the trade-off: each ceiling with its retirement age.
        $this->assertStringContainsString('£250,000', $view['summary']);
        $this->assertStringContainsString('age 62', $view['summary']);
        $this->assertStringContainsString('£310,000', $view['summary']);
        $this->assertStringContainsString('age 70', $view['summary']);
        $this->assertStringContainsString('about', $view['summary']); // a band, never a hard line

        // Neutral copy: "on track", never "safe" (the Phase-2 guardrail applies here too).
        $this->assertStringContainsString('on track', $view['summary']);
        $this->assertStringNotContainsStringIgnoringCase('safe', $view['summary']);
    }

    public function test_each_column_reads_as_an_honest_banded_chip(): void
    {
        $view = FrontierPresenter::view($this->outcome([
            $this->column(60.0, [200_000 => 0.70, 300_000 => 0.50], new Crossing(CrossingVerdict::Unreachable, 0.90)),
            $this->column(65.0, [200_000 => 0.95, 300_000 => 0.80], $this->crossingAt(250_000.0)),
            $this->column(75.0, [200_000 => 0.99, 300_000 => 0.97], new Crossing(CrossingVerdict::AlreadyOnTrack, 0.90)),
        ]));

        [$unreachable, $crosses, $onTrack] = $view['columns'];

        $this->assertSame('age 60', $unreachable['condition']);
        $this->assertSame('below target across the range', $unreachable['chip']);
        $this->assertNull($unreachable['ceiling']);

        // A Decreasing threshold lever (buy price) is on track BELOW the crossing: "up to about".
        $this->assertSame('up to about £250,000', $crosses['chip']);
        $this->assertSame('£225,000', $crosses['bandLow']);
        $this->assertSame('£275,000', $crosses['bandHigh']);

        $this->assertSame('on track across the range', $onTrack['chip']);
    }

    public function test_the_heatmap_keeps_every_cell_with_text_percentages_reading_downwards(): void
    {
        $view = FrontierPresenter::view($this->outcome([
            $this->column(62.0, [200_000 => 0.95, 300_000 => 0.80], $this->crossingAt(250_000.0)),
            $this->column(70.0, [200_000 => 0.98, 300_000 => 0.88], $this->crossingAt(310_000.0)),
        ]));

        $grid = $view['grid'];
        $this->assertSame(['age 62', 'age 70'], $grid['conditionLabels']);

        // Rows run highest threshold value first ("spend more" reads upwards)…
        $this->assertSame(['£300,000', '£200,000'], array_column($grid['rows'], 'label'));

        // …and every cell carries its percentage as text plus an above/below-target flag.
        [$top, $bottom] = $grid['rows'];
        $this->assertSame(['80%', '88%'], array_column($top['cells'], 'pct'));
        $this->assertSame([false, false], array_column($top['cells'], 'above'));
        $this->assertSame(['95%', '98%'], array_column($bottom['cells'], 'pct'));
        $this->assertSame([true, true], array_column($bottom['cells'], 'above'));
    }

    public function test_no_crossing_anywhere_reads_as_the_honest_all_or_nothing_story(): void
    {
        $allOn = FrontierPresenter::view($this->outcome([
            $this->column(62.0, [200_000 => 0.97, 300_000 => 0.95], new Crossing(CrossingVerdict::AlreadyOnTrack, 0.90)),
            $this->column(70.0, [200_000 => 0.99, 300_000 => 0.97], new Crossing(CrossingVerdict::AlreadyOnTrack, 0.90)),
        ]));
        $this->assertStringContainsString('already stays on track', $allOn['summary']);

        $allOff = FrontierPresenter::view($this->outcome([
            $this->column(62.0, [200_000 => 0.60, 300_000 => 0.40], new Crossing(CrossingVerdict::Unreachable, 0.90)),
            $this->column(70.0, [200_000 => 0.70, 300_000 => 0.55], new Crossing(CrossingVerdict::Unreachable, 0.90)),
        ]));
        $this->assertStringContainsString('does not get you there', $allOff['summary']);
        $this->assertStringNotContainsStringIgnoringCase('safe', $allOff['summary']);
    }
}
