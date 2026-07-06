<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Export\ExportDisclaimer;
use App\Models\ThresholdResult;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;

/**
 * Builds the CSV rows for a computed lever threshold: the guidance-only disclaimer, the sweep
 * provenance (lever, success bar, target, paths per point, seed, engine + tax-year versions),
 * the honest crossing verdict, then the full swept grid (each point with its Monte Carlo
 * confidence interval). Kept separate from the HTTP layer so the exact rows can be unit-tested;
 * the controller only streams them.
 *
 * Every downloaded figure travels with its disclaimer and its provenance — the plan's rule that
 * a threshold never leaves the app as a bare number.
 */
final class ThresholdCsvExporter
{
    /**
     * @return list<list<int|float|string>>
     */
    public static function rows(ThresholdResult $run): array
    {
        $outcome = $run->thresholdOutcome();
        if ($outcome === null) {
            // A threshold that has not finished has no figures to export; still ship the
            // disclaimer + a status line rather than an empty file (no silent nothing).
            return [
                ...array_map(static fn (string $line): array => [$line], ExportDisclaimer::LINES),
                [],
                ['This threshold has not finished computing; no figures to export yet.'],
            ];
        }

        $curve = $outcome->curve;
        $crossing = $outcome->crossing;
        $lever = $run->leverKey();
        // Care is a binary pinned before/after, not a sweep — an interpolated crossing between "off"
        // and "on" is meaningless, so the CSV states the two states plainly and omits the threshold.
        $isCare = $lever === LeverKey::Care;

        $rows = array_map(static fn (string $line): array => [$line], ExportDisclaimer::LINES);
        $rows[] = [];

        // Provenance: what this sweep answered and how, so a downloaded grid is self-describing.
        $rows[] = ['Lever', $lever->label()];
        $rows[] = ['Success measured', ucfirst($run->metricEnum()->label())];
        $rows[] = ['Target probability', self::pct($outcome->targetProbability)];
        $rows[] = ['Paths per point', $curve->pathsPerPoint];
        $rows[] = ['Seed', $run->seed];
        $rows[] = ['Engine version', $run->engine_version];
        $rows[] = ['Tax-year config', $run->taxyear_config_version];
        $rows[] = [];

        if ($isCare) {
            // No crossing — the two states are independently-seeded runs, not a like-for-like pair,
            // so the difference is read by comparing each state's confidence interval, not a limit.
            $rows[] = ['Comparison', 'Care off vs care on — a pinned before/after, not a threshold (the two runs are not directly comparable path-for-path).'];
        } else {
            // The crossing verdict — a band, never a bare point (S3).
            $rows[] = ['Verdict', self::verdictLabel($crossing->verdict)];
            if ($crossing->hasThreshold()) {
                $rows[] = ['Threshold estimate', self::leverValue($crossing->estimate)];
                $rows[] = ['Threshold band (from)', self::leverValue($crossing->lowerLever)];
                $rows[] = ['Threshold band (to)', self::leverValue($crossing->upperLever)];
            }
        }
        $rows[] = [];

        // The full swept grid with each point's Monte Carlo confidence interval. For care the two
        // rows carry readable state names rather than a bare 0/1.
        $rows[] = [$isCare ? 'State' : 'Lever value', 'Success probability', 'CI low', 'CI high', 'Paths'];
        foreach ($curve->points as $point) {
            $rows[] = [
                $isCare ? ThresholdPresenter::formatLeverValue($lever, $point->leverValue) : self::leverValue($point->leverValue),
                self::pct($point->successProbability),
                self::pct($point->ciLow),
                self::pct($point->ciHigh),
                $point->paths,
            ];
        }

        return $rows;
    }

    private static function verdictLabel(CrossingVerdict $verdict): string
    {
        return match ($verdict) {
            CrossingVerdict::AlreadyOnTrack => 'Already on track across the whole range',
            CrossingVerdict::Unreachable => 'The target is not reached anywhere in the range',
            CrossingVerdict::Crosses => 'A single threshold (crosses the target once)',
            CrossingVerdict::NonMonotone => 'Crosses more than once — first crossing shown (not monotone)',
        };
    }

    private static function pct(float $p): string
    {
        return number_format($p * 100, 1).'%';
    }

    /** A lever value is a plain number in lever-space (a price, an age, an annual spend); keep it exact-ish. */
    private static function leverValue(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
