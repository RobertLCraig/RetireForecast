<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

/**
 * One grid point of a sweep: the lever value, the Monte Carlo success probability there, and
 * its confidence interval (a Wilson score interval on $paths paths). The interval is carried
 * so a crossing is reported as a band, never a false-precision point — MC noise on, say, 500
 * paths is real, and the reader must see it (the correctness spine, S3).
 */
final class SweepPoint
{
    public function __construct(
        public readonly float $leverValue,
        public readonly float $successProbability,
        public readonly float $ciLow,
        public readonly float $ciHigh,
        public readonly int $paths,
    ) {}
}
