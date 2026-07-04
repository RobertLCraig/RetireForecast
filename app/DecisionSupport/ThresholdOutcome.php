<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use RetireForecast\FinanceEngine\Sweep\Crossing;
use RetireForecast\FinanceEngine\Sweep\SweepCurve;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;

/**
 * The result of a lever-threshold computation for a scenario: the measured success curve, where it
 * crosses the target ({@see Crossing} — a banded verdict, never a false-precision point), and the
 * lever + success bar it answers. Carries the seed and paths-per-point through the curve so the
 * readout is reproducible and auditable (the provenance the plan requires).
 */
final class ThresholdOutcome
{
    public function __construct(
        public readonly LeverKey $lever,
        public readonly SweepMetric $metric,
        public readonly float $targetProbability,
        public readonly SweepCurve $curve,
        public readonly Crossing $crossing,
    ) {}
}
