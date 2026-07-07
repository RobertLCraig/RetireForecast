<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use RetireForecast\FinanceEngine\Sweep\Frontier;
use RetireForecast\FinanceEngine\Sweep\SweepMetric;

/**
 * The result of a 2-D frontier computation for a scenario (decision-support Phase 5): the
 * threshold of one lever as a function of a second — e.g. the buy-price ceiling at each held
 * retirement age — because a single-lever threshold prints as if it were unconditional. Wraps the
 * engine {@see Frontier} (per condition value: the full measured success curve + its crossing)
 * with the two app-level lever identities and the success bar it answers, mirroring
 * {@see ThresholdOutcome} for the 1-D case.
 */
final class FrontierOutcome
{
    public function __construct(
        public readonly LeverKey $thresholdLever,
        public readonly LeverKey $conditionLever,
        public readonly SweepMetric $metric,
        public readonly float $targetProbability,
        public readonly Frontier $frontier,
    ) {}
}
