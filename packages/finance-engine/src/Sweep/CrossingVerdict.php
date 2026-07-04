<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

/**
 * The shape of where a sweep's success curve meets the target probability (S3 crossing
 * semantics). A single threshold is only meaningful when the curve actually crosses the
 * target once; the other cases must be reported honestly, not forced into a number.
 *
 *  - AlreadyOnTrack: the curve is at/above the target across the whole grid — no change needed
 *    in the swept range (e.g. any buy price in range keeps the money lasting).
 *  - Unreachable:    the curve is below the target across the whole grid — the target is not
 *    reachable by this lever alone in the swept range.
 *  - Crosses:        the curve crosses the target exactly once — a genuine threshold, reported
 *    as a band (the bracketing grid interval) with the interpolated estimate.
 *  - NonMonotone:    the curve crosses more than once — the first crossing is reported but
 *    flagged, because a single "threshold" would mislead (the lever is not monotone).
 */
enum CrossingVerdict
{
    case AlreadyOnTrack;
    case Unreachable;
    case Crosses;
    case NonMonotone;
}
