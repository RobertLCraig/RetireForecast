<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

/**
 * One point of a 2-D frontier: the value a *condition* lever is held at, and where the *threshold*
 * lever then crosses the target ({@see Crossing}). E.g. "at retirement age 70, the buy-price
 * ceiling is ~£300k" is one FrontierPoint (condition = retirement age 70, crossing = the buy-price
 * threshold there).
 *
 * $curve is the full swept success curve the crossing was read off — one {@see SweepPoint} per
 * threshold-grid value at this held condition. Kept on the point (not discarded) because the
 * frontier's honest rendering is a heatmap of every measured cell with the crossing band marked,
 * not a bare iso-line: the crossing is derived from the curve, so carrying both cannot drift.
 */
final class FrontierPoint
{
    public function __construct(
        public readonly float $conditionValue,
        public readonly Crossing $crossing,
        public readonly SweepCurve $curve,
    ) {}
}
