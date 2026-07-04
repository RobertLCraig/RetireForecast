<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

/**
 * Where a sweep's success curve meets the target probability — a BAND, never a point, because
 * the curve is a noisy Monte Carlo estimate on a finite grid (S3). $lowerLever and $upperLever
 * bracket the crossing (the adjacent grid values on either side of the target); $estimate is a
 * linear interpolation between them (null when there is no crossing). $targetProbability records
 * what bar was used. The verdict says how to read it — a genuine threshold, already-on-track,
 * unreachable, or a non-monotone first-crossing that may hide others.
 */
final class Crossing
{
    public function __construct(
        public readonly CrossingVerdict $verdict,
        public readonly float $targetProbability,
        public readonly ?float $lowerLever = null,
        public readonly ?float $upperLever = null,
        public readonly ?float $estimate = null,
    ) {}

    public function hasThreshold(): bool
    {
        return $this->estimate !== null;
    }
}
