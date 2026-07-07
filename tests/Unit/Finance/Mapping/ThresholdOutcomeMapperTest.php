<?php

declare(strict_types=1);

namespace Tests\Unit\Finance\Mapping;

use App\DecisionSupport\FrontierOutcome;
use App\DecisionSupport\LeverKey;
use App\DecisionSupport\ThresholdOutcome;
use App\Finance\Mapping\ThresholdOutcomeMapper;
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
 * The persisted-threshold payload mapper. A computed curve + crossing must survive a JSON
 * round-trip byte-for-byte (through the encrypted array store), including the two non-backed
 * engine enums (lever direction, crossing verdict) and the float-not-int shape.
 */
final class ThresholdOutcomeMapperTest extends TestCase
{
    private function outcome(): ThresholdOutcome
    {
        return new ThresholdOutcome(
            lever: LeverKey::BuyPrice,
            metric: SweepMetric::Essentials,
            targetProbability: 0.95,
            curve: new SweepCurve(
                points: [
                    new SweepPoint(200_000.0, 0.99, 0.97, 1.0, 500),
                    new SweepPoint(300_000.0, 0.77, 0.73, 0.81, 500),
                ],
                metric: SweepMetric::Essentials,
                direction: LeverDirection::Decreasing,
                leverName: 'Buy price',
                leverUnit: '£',
                pathsPerPoint: 500,
                seed: 305_419_896,
            ),
            crossing: new Crossing(
                verdict: CrossingVerdict::Crosses,
                targetProbability: 0.95,
                lowerLever: 260_000.0,
                upperLever: 300_000.0,
                estimate: 275_000.0,
            ),
        );
    }

    public function test_a_threshold_outcome_round_trips_through_a_json_cycle(): void
    {
        $original = $this->outcome();

        // Force the array through a real JSON encode/decode, as the encrypted store does.
        $decoded = json_decode(json_encode(ThresholdOutcomeMapper::toArray($original)), true);
        $round = ThresholdOutcomeMapper::fromArray($decoded);

        $this->assertSame(LeverKey::BuyPrice, $round->lever);
        $this->assertSame(SweepMetric::Essentials, $round->metric);
        $this->assertSame(0.95, $round->targetProbability);

        // Curve: provenance + every point, with floats staying floats.
        $this->assertSame(LeverDirection::Decreasing, $round->curve->direction);
        $this->assertSame('Buy price', $round->curve->leverName);
        $this->assertSame(500, $round->curve->pathsPerPoint);
        $this->assertSame(305_419_896, $round->curve->seed);
        $this->assertCount(2, $round->curve->points);
        $this->assertSame(200_000.0, $round->curve->points[0]->leverValue);
        $this->assertSame(0.99, $round->curve->points[0]->successProbability);
        $this->assertSame(0.97, $round->curve->points[0]->ciLow);
        $this->assertSame(500, $round->curve->points[0]->paths);

        // Crossing: the banded verdict survives, including the non-backed enum.
        $this->assertSame(CrossingVerdict::Crosses, $round->crossing->verdict);
        $this->assertSame(260_000.0, $round->crossing->lowerLever);
        $this->assertSame(300_000.0, $round->crossing->upperLever);
        $this->assertSame(275_000.0, $round->crossing->estimate);
    }

    public function test_a_frontier_outcome_round_trips_through_a_json_cycle(): void
    {
        $column = static fn (float $condition, float $p1, float $p2): FrontierPoint => new FrontierPoint(
            conditionValue: $condition,
            crossing: new Crossing(CrossingVerdict::Crosses, 0.95, 200_000.0, 300_000.0, 250_000.0),
            curve: new SweepCurve(
                points: [
                    new SweepPoint(200_000.0, $p1, $p1 - 0.02, $p1 + 0.02, 400),
                    new SweepPoint(300_000.0, $p2, $p2 - 0.02, $p2 + 0.02, 400),
                ],
                metric: SweepMetric::Essentials,
                direction: LeverDirection::Decreasing,
                leverName: 'Buy price',
                leverUnit: '£',
                pathsPerPoint: 400,
                seed: 305_419_896,
            ),
        );

        $original = new FrontierOutcome(
            thresholdLever: LeverKey::BuyPrice,
            conditionLever: LeverKey::RetirementAge,
            metric: SweepMetric::Essentials,
            targetProbability: 0.95,
            frontier: new Frontier(
                points: [$column(62.0, 0.98, 0.80), $column(70.0, 0.99, 0.90)],
                thresholdLeverName: 'Buy price',
                conditionLeverName: 'Retirement age',
                metric: SweepMetric::Essentials,
                targetProbability: 0.95,
                pathsPerPoint: 400,
                seed: 305_419_896,
            ),
        );

        $round = ThresholdOutcomeMapper::frontierFromArray(
            json_decode(json_encode(ThresholdOutcomeMapper::frontierToArray($original)), true)
        );

        // The two lever identities, the bar, and the frontier provenance all survive.
        $this->assertSame(LeverKey::BuyPrice, $round->thresholdLever);
        $this->assertSame(LeverKey::RetirementAge, $round->conditionLever);
        $this->assertSame(0.95, $round->targetProbability);
        $this->assertSame('Retirement age', $round->frontier->conditionLeverName);
        $this->assertSame(400, $round->frontier->pathsPerPoint);
        $this->assertSame(305_419_896, $round->frontier->seed);

        // Every column: the held value, its crossing band, and its full measured curve (the cells).
        $this->assertCount(2, $round->frontier->points);
        $this->assertSame(62.0, $round->frontier->points[0]->conditionValue);
        $this->assertSame(CrossingVerdict::Crosses, $round->frontier->points[0]->crossing->verdict);
        $this->assertSame(250_000.0, $round->frontier->points[0]->crossing->estimate);
        $this->assertSame(0.98, $round->frontier->points[0]->curve->points[0]->successProbability);
        $this->assertSame(0.90, $round->frontier->points[1]->curve->points[1]->successProbability);
        $this->assertSame(400, $round->frontier->points[1]->curve->points[1]->paths);
    }

    public function test_a_no_crossing_verdict_round_trips_with_null_band(): void
    {
        $original = new ThresholdOutcome(
            lever: LeverKey::RetirementAge,
            metric: SweepMetric::FullSpend,
            targetProbability: 0.90,
            curve: new SweepCurve([], SweepMetric::FullSpend, LeverDirection::Increasing, 'Retirement age', 'years', 200, 42),
            crossing: new Crossing(CrossingVerdict::AlreadyOnTrack, 0.90),
        );

        $round = ThresholdOutcomeMapper::fromArray(
            json_decode(json_encode(ThresholdOutcomeMapper::toArray($original)), true)
        );

        $this->assertSame(CrossingVerdict::AlreadyOnTrack, $round->crossing->verdict);
        $this->assertNull($round->crossing->lowerLever);
        $this->assertNull($round->crossing->estimate);
        $this->assertFalse($round->crossing->hasThreshold());
        $this->assertSame([], $round->curve->points);
    }
}
