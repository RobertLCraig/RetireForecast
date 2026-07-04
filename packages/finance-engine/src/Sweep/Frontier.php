<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

/**
 * The flagship 2-D answer (S2): the threshold of one lever as a function of a second, because a
 * single-lever curve prints a threshold as if it were unconditional ("£260k" hides "…at retirement
 * 67; £300k at 70"). The frontier is the threshold-lever crossing at each held value of the
 * condition lever — a parametric threshold, not a point.
 */
final class Frontier
{
    /**
     * @param  list<FrontierPoint>  $points  one per condition-lever value
     */
    public function __construct(
        public readonly array $points,
        public readonly string $thresholdLeverName,
        public readonly string $conditionLeverName,
        public readonly SweepMetric $metric,
        public readonly float $targetProbability,
        public readonly int $pathsPerPoint,
        public readonly int $seed,
    ) {}
}
