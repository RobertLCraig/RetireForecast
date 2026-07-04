<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

/**
 * The result of sweeping one lever: the success probability (with its confidence interval) at
 * each grid value, plus the provenance a readout must carry — which lever, what success bar,
 * how many paths per point, and the pinned seed (so the curve is reproducible and auditable).
 *
 * A curve is just the measured points; asking "where does it cross my target?" is a separate
 * step ({@see SweepEngine::findCrossing}), because the same curve answers different targets
 * (90% vs 95%) and the crossing has its own honest verdict.
 */
final class SweepCurve
{
    /**
     * @param  list<SweepPoint>  $points  ordered by lever value
     */
    public function __construct(
        public readonly array $points,
        public readonly SweepMetric $metric,
        public readonly LeverDirection $direction,
        public readonly string $leverName,
        public readonly string $leverUnit,
        public readonly int $pathsPerPoint,
        public readonly int $seed,
    ) {}
}
