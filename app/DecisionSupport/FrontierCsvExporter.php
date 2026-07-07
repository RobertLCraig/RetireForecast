<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Export\ExportDisclaimer;
use App\Models\ThresholdResult;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;

/**
 * Builds the CSV rows for a computed 2-D frontier: the guidance-only disclaimer, the provenance
 * (both levers, success bar, paths per cell, seed, engine + tax-year versions), the per-column
 * crossing verdicts (each a band, never a point), then every measured cell in long format
 * (condition value, lever value, success probability, confidence interval, paths). Mirrors
 * {@see ThresholdCsvExporter} — a figure never leaves the app as a bare number.
 */
final class FrontierCsvExporter
{
    /**
     * @return list<list<int|float|string>>
     */
    public static function rows(ThresholdResult $run): array
    {
        $outcome = $run->frontierOutcome();
        if ($outcome === null) {
            return [
                ...array_map(static fn (string $line): array => [$line], ExportDisclaimer::LINES),
                [],
                ['This trade-off map has not finished computing; no figures to export yet.'],
            ];
        }

        $frontier = $outcome->frontier;

        $rows = array_map(static fn (string $line): array => [$line], ExportDisclaimer::LINES);
        $rows[] = [];

        // Provenance: what this frontier answered and how, so a downloaded map is self-describing.
        $rows[] = ['Lever swept', $outcome->thresholdLever->label()];
        $rows[] = ['Lever held', $outcome->conditionLever->label()];
        $rows[] = ['Success measured', ucfirst($run->metricEnum()->label())];
        $rows[] = ['Target probability', self::pct($outcome->targetProbability)];
        $rows[] = ['Paths per cell', $frontier->pathsPerPoint];
        $rows[] = ['Seed', $run->seed];
        $rows[] = ['Engine version', $run->engine_version];
        $rows[] = ['Tax-year config', $run->taxyear_config_version];
        $rows[] = [];

        // The iso-line: each held value's crossing verdict, banded (S3).
        $rows[] = ['Held value', 'Verdict', 'Threshold estimate', 'Band (from)', 'Band (to)'];
        foreach ($frontier->points as $point) {
            $rows[] = [
                self::value($point->conditionValue),
                self::verdictLabel($point->crossing->verdict),
                $point->crossing->estimate === null ? '' : self::value($point->crossing->estimate),
                $point->crossing->lowerLever === null ? '' : self::value($point->crossing->lowerLever),
                $point->crossing->upperLever === null ? '' : self::value($point->crossing->upperLever),
            ];
        }
        $rows[] = [];

        // Every measured cell, long format — the full grid with each cell's Monte Carlo interval.
        $rows[] = ['Held value', 'Lever value', 'Success probability', 'CI low', 'CI high', 'Paths'];
        foreach ($frontier->points as $point) {
            foreach ($point->curve->points as $cell) {
                $rows[] = [
                    self::value($point->conditionValue),
                    self::value($cell->leverValue),
                    self::pct($cell->successProbability),
                    self::pct($cell->ciLow),
                    self::pct($cell->ciHigh),
                    $cell->paths,
                ];
            }
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
    private static function value(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
