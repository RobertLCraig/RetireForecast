<?php

declare(strict_types=1);

namespace App\Finance\Mapping;

use App\DecisionSupport\FrontierOutcome;
use App\DecisionSupport\LeverKey;
use App\DecisionSupport\ThresholdOutcome;
use RetireForecast\FinanceEngine\Sweep\Crossing;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;
use RetireForecast\FinanceEngine\Sweep\Frontier;
use RetireForecast\FinanceEngine\Sweep\FrontierPoint;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepCurve;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;
use RetireForecast\FinanceEngine\Sweep\SweepPoint;

/**
 * Maps a decision-support {@see ThresholdOutcome} (the swept success curve + its crossing) — or a
 * 2-D {@see FrontierOutcome} (per held condition value: the full curve + its crossing) — to and
 * from the array stored as a ThresholdResult's encrypted payload. Which shape a payload holds is
 * decided by the row's `condition_lever_key` column (null = 1-D threshold), not sniffed from the
 * payload.
 *
 * The sweep works in float lever-space (a lever value is a buy price, an age, an annual spend
 * — a plain float by the engine's design, not a Money value object), and probabilities and
 * confidence bounds are floats, so — unlike the money-bearing SimulationResult payload — there
 * is no pence conversion here. Floats are cast back to float on hydrate so a JSON 1.0 that
 * decodes as int 1 does not drift the shape. The two non-backed engine enums (lever direction,
 * crossing verdict) are stored by case name and rehydrated by name.
 */
final class ThresholdOutcomeMapper
{
    public static function toArray(ThresholdOutcome $outcome): array
    {
        return [
            'lever' => $outcome->lever->value,
            'metric' => $outcome->metric->value,
            'targetProbability' => $outcome->targetProbability,
            'curve' => self::curveToArray($outcome->curve),
            'crossing' => self::crossingToArray($outcome->crossing),
        ];
    }

    public static function fromArray(array $data): ThresholdOutcome
    {
        return new ThresholdOutcome(
            lever: LeverKey::from($data['lever']),
            metric: SweepMetric::from($data['metric']),
            targetProbability: (float) $data['targetProbability'],
            curve: self::curveFromArray($data['curve']),
            crossing: self::crossingFromArray($data['crossing']),
        );
    }

    public static function frontierToArray(FrontierOutcome $outcome): array
    {
        $frontier = $outcome->frontier;

        return [
            'thresholdLever' => $outcome->thresholdLever->value,
            'conditionLever' => $outcome->conditionLever->value,
            'metric' => $outcome->metric->value,
            'targetProbability' => $outcome->targetProbability,
            'frontier' => [
                'thresholdLeverName' => $frontier->thresholdLeverName,
                'conditionLeverName' => $frontier->conditionLeverName,
                'metric' => $frontier->metric->value,
                'targetProbability' => $frontier->targetProbability,
                'pathsPerPoint' => $frontier->pathsPerPoint,
                'seed' => $frontier->seed,
                'points' => array_map(static fn (FrontierPoint $p): array => [
                    'conditionValue' => $p->conditionValue,
                    'crossing' => self::crossingToArray($p->crossing),
                    'curve' => self::curveToArray($p->curve),
                ], $frontier->points),
            ],
        ];
    }

    public static function frontierFromArray(array $data): FrontierOutcome
    {
        $f = $data['frontier'];

        return new FrontierOutcome(
            thresholdLever: LeverKey::from($data['thresholdLever']),
            conditionLever: LeverKey::from($data['conditionLever']),
            metric: SweepMetric::from($data['metric']),
            targetProbability: (float) $data['targetProbability'],
            frontier: new Frontier(
                points: array_map(static fn (array $p): FrontierPoint => new FrontierPoint(
                    conditionValue: (float) $p['conditionValue'],
                    crossing: self::crossingFromArray($p['crossing']),
                    curve: self::curveFromArray($p['curve']),
                ), $f['points']),
                thresholdLeverName: (string) $f['thresholdLeverName'],
                conditionLeverName: (string) $f['conditionLeverName'],
                metric: SweepMetric::from($f['metric']),
                targetProbability: (float) $f['targetProbability'],
                pathsPerPoint: (int) $f['pathsPerPoint'],
                seed: (int) $f['seed'],
            ),
        );
    }

    private static function curveToArray(SweepCurve $curve): array
    {
        return [
            'metric' => $curve->metric->value,
            'direction' => $curve->direction->name,
            'leverName' => $curve->leverName,
            'leverUnit' => $curve->leverUnit,
            'pathsPerPoint' => $curve->pathsPerPoint,
            'seed' => $curve->seed,
            'points' => array_map(static fn (SweepPoint $p): array => [
                'leverValue' => $p->leverValue,
                'successProbability' => $p->successProbability,
                'ciLow' => $p->ciLow,
                'ciHigh' => $p->ciHigh,
                'paths' => $p->paths,
            ], $curve->points),
        ];
    }

    private static function curveFromArray(array $data): SweepCurve
    {
        return new SweepCurve(
            points: array_map(static fn (array $p): SweepPoint => new SweepPoint(
                leverValue: (float) $p['leverValue'],
                successProbability: (float) $p['successProbability'],
                ciLow: (float) $p['ciLow'],
                ciHigh: (float) $p['ciHigh'],
                paths: (int) $p['paths'],
            ), $data['points']),
            metric: SweepMetric::from($data['metric']),
            direction: self::direction($data['direction']),
            leverName: (string) $data['leverName'],
            leverUnit: (string) $data['leverUnit'],
            pathsPerPoint: (int) $data['pathsPerPoint'],
            seed: (int) $data['seed'],
        );
    }

    private static function crossingToArray(Crossing $crossing): array
    {
        return [
            'verdict' => $crossing->verdict->name,
            'targetProbability' => $crossing->targetProbability,
            'lowerLever' => $crossing->lowerLever,
            'upperLever' => $crossing->upperLever,
            'estimate' => $crossing->estimate,
        ];
    }

    private static function crossingFromArray(array $data): Crossing
    {
        return new Crossing(
            verdict: self::verdict($data['verdict']),
            targetProbability: (float) $data['targetProbability'],
            lowerLever: isset($data['lowerLever']) ? (float) $data['lowerLever'] : null,
            upperLever: isset($data['upperLever']) ? (float) $data['upperLever'] : null,
            estimate: isset($data['estimate']) ? (float) $data['estimate'] : null,
        );
    }

    private static function direction(string $name): LeverDirection
    {
        return constant(LeverDirection::class."::{$name}");
    }

    private static function verdict(string $name): CrossingVerdict
    {
        return constant(CrossingVerdict::class."::{$name}");
    }
}
