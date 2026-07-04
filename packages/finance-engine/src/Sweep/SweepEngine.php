<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * The correctness spine of the "how far can we go?" feature: sweep one lever across a grid of
 * values, measure the Monte Carlo success probability at each (with its confidence interval),
 * and find where the curve crosses a target probability.
 *
 * Load-bearing decisions (docs/PLAN-decision-support.md, Phase 0):
 *  - **S1 — the crossing is a Monte Carlo quantity.** The cheap deterministic pass runs each
 *    person at their independent MEDIAN death age, so the survivor-poverty tail (first death
 *    early, second late) — the binding risk — never appears; it locates success ≈ 50%, not the
 *    95% tail crossing, and is biased optimistic. So this engine runs a real MC per grid point.
 *  - **Pinned seed (common random numbers).** Every grid point uses the SAME seed, so the only
 *    thing that moves between points is the lever — the curve is smooth for a monotone lever and
 *    reproducible. (This holds while the lever does not change the RNG consumption; a lever that
 *    toggles care or changes the household size would desync the streams — such levers must be
 *    declared LeverDirection::Unknown and are not monotone-fit. See the plan's CRN discipline.)
 *  - **Report a band, never a point (S3).** A crossing is the bracketing grid interval plus a
 *    linear-interpolated estimate; the per-point confidence intervals show the MC uncertainty.
 *    Already-on-track / unreachable / non-monotone are distinct honest verdicts.
 *
 * Framework-free: no Eloquent, no I/O, no clock. The caller supplies the household, settings,
 * assumptions and life table; a lever ({@see SweepLever}) maps a grid value to the inputs.
 */
final class SweepEngine
{
    /** z for a 95% Wilson score interval on each point's success proportion. */
    private const Z = 1.959963984540054;

    public function __construct(private readonly TaxYearConfig $config) {}

    /**
     * Sweep $lever across $grid, running a Monte Carlo on the pinned $seed at each value and
     * recording the $metric success probability with its Wilson confidence interval.
     *
     * @param  list<float>  $grid  the lever values to measure (sorted ascending here defensively)
     */
    public function sweep(
        Household $household,
        ForecastSettings $settings,
        AssumptionSet $assumptions,
        CohortLifeTable $lifeTable,
        SweepLever $lever,
        array $grid,
        SweepMetric $metric,
        int $nPaths,
        int $seed,
    ): SweepCurve {
        sort($grid);
        $simulator = new Simulator($this->config);

        $points = [];
        foreach ($grid as $value) {
            $inputs = $lever->apply($household, $settings, $value);
            // Same seed at every grid point (common random numbers): only the lever changes.
            $result = $simulator->run($inputs->household, $inputs->settings, $assumptions, $lifeTable, $nPaths, $seed);
            $p = $metric->probability($result);
            [$low, $high] = self::wilsonInterval($p, $nPaths);
            $points[] = new SweepPoint($value, $p, $low, $high, $nPaths);
        }

        return new SweepCurve(
            points: $points,
            metric: $metric,
            direction: $lever->direction(),
            leverName: $lever->name(),
            leverUnit: $lever->unit(),
            pathsPerPoint: $nPaths,
            seed: $seed,
        );
    }

    /**
     * Find where the curve crosses $targetProbability, as a band with an honest verdict (S3).
     * A point is on the "safe" side when its success probability is at or above the target;
     * a crossing is an adjacent pair straddling that boundary. No crossing and all-safe is
     * already-on-track; no crossing and all-unsafe is unreachable; more than one crossing is
     * non-monotone (the first is reported, flagged).
     */
    public function findCrossing(SweepCurve $curve, float $targetProbability): Crossing
    {
        $points = $curve->points;
        $n = count($points);
        if ($n === 0) {
            return new Crossing(CrossingVerdict::Unreachable, $targetProbability);
        }

        $safe = static fn (SweepPoint $pt): bool => $pt->successProbability >= $targetProbability;

        // Collect every adjacent boundary (safe <-> unsafe) as a candidate crossing.
        $crossings = [];
        for ($i = 0; $i < $n - 1; $i++) {
            if ($safe($points[$i]) !== $safe($points[$i + 1])) {
                $crossings[] = $i;
            }
        }

        if ($crossings === []) {
            $verdict = $safe($points[0]) ? CrossingVerdict::AlreadyOnTrack : CrossingVerdict::Unreachable;

            return new Crossing($verdict, $targetProbability);
        }

        // Report the first crossing; flag as non-monotone when there is more than one.
        $i = $crossings[0];
        $a = $points[$i];
        $b = $points[$i + 1];
        $estimate = self::interpolate($a, $b, $targetProbability);
        $verdict = count($crossings) > 1 ? CrossingVerdict::NonMonotone : CrossingVerdict::Crosses;

        return new Crossing(
            verdict: $verdict,
            targetProbability: $targetProbability,
            lowerLever: min($a->leverValue, $b->leverValue),
            upperLever: max($a->leverValue, $b->leverValue),
            estimate: $estimate,
        );
    }

    /**
     * The lever value where the straight line between two points reaches the target probability.
     * Falls back to the midpoint when the two points share a probability (a flat segment).
     */
    private static function interpolate(SweepPoint $a, SweepPoint $b, float $target): float
    {
        $dp = $b->successProbability - $a->successProbability;
        if ($dp === 0.0) {
            return ($a->leverValue + $b->leverValue) / 2.0;
        }
        $fraction = ($target - $a->successProbability) / $dp;

        return $a->leverValue + $fraction * ($b->leverValue - $a->leverValue);
    }

    /**
     * Wilson score interval for a proportion $p observed over $n paths — robust near 0 and 1
     * (where a success curve sits) unlike the normal approximation. Clamped to [0, 1].
     *
     * @return array{0: float, 1: float} [low, high]
     */
    private static function wilsonInterval(float $p, int $n): array
    {
        if ($n <= 0) {
            return [0.0, 1.0];
        }
        $z = self::Z;
        $z2 = $z * $z;
        $denom = 1.0 + $z2 / $n;
        $centre = ($p + $z2 / (2 * $n)) / $denom;
        $margin = ($z / $denom) * sqrt($p * (1 - $p) / $n + $z2 / (4 * $n * $n));

        return [max(0.0, $centre - $margin), min(1.0, $centre + $margin)];
    }
}
