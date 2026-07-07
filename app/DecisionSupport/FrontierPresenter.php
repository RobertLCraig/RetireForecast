<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;
use RetireForecast\FinanceEngine\Sweep\FrontierPoint;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;

/**
 * Turns a computed 2-D frontier into the "trade-off map" view models (decision-support Phase 5):
 * a plain-language summary that pins BOTH levers (a single-lever threshold reads as unconditional
 * — "£260k" hides "at retirement 67; £300k at 70"), a per-column ceiling chip, and the full
 * success heatmap (every measured cell, tinted above/below target, with the iso-line emerging as
 * the tint boundary).
 *
 * The same guardrails as the 1-D panel: the word "safe" never appears (we say "on track"), every
 * cell carries its percentage as text (colour never carries meaning alone), and a ceiling is
 * always "about" a value — the crossing is a Monte Carlo band, not a hard line.
 */
final class FrontierPresenter
{
    /**
     * @return array{
     *     thresholdLabel: string, conditionLabel: string, targetPct: string, pathsPerCell: int,
     *     summary: string,
     *     columns: list<array{condition: string, verdict: string, ceiling: ?string, bandLow: ?string, bandHigh: ?string, chip: string}>,
     *     grid: array{conditionLabels: list<string>, rows: list<array{label: string, cells: list<array{pct: string, above: bool}>}>}
     * }
     */
    public static function view(FrontierOutcome $outcome): array
    {
        return [
            'thresholdLabel' => $outcome->thresholdLever->label(),
            'conditionLabel' => $outcome->conditionLever->label(),
            'targetPct' => self::pct($outcome->targetProbability),
            'pathsPerCell' => $outcome->frontier->pathsPerPoint,
            'summary' => self::summary($outcome),
            'columns' => array_map(
                static fn (FrontierPoint $p): array => self::column($outcome, $p),
                $outcome->frontier->points,
            ),
            'grid' => self::grid($outcome),
        ];
    }

    /**
     * The simple-view sentence: both levers pinned, or the honest no-crossing story. Bespoke
     * wording for the headline buy-price × retirement-age pair; a lever-labelled generic
     * otherwise.
     */
    private static function summary(FrontierOutcome $outcome): string
    {
        $points = $outcome->frontier->points;
        $crossed = array_values(array_filter($points, static fn (FrontierPoint $p): bool => $p->crossing->hasThreshold()));
        $verdicts = array_map(static fn (FrontierPoint $p): CrossingVerdict => $p->crossing->verdict, $points);

        if ($crossed === []) {
            if (! in_array(CrossingVerdict::Unreachable, $verdicts, true)) {
                return 'Across every pairing explored the money already stays on track — neither of these levers is the constraint on these figures.';
            }
            if (! in_array(CrossingVerdict::AlreadyOnTrack, $verdicts, true)) {
                return 'No pairing in the explored ranges reaches your target on these figures — moving these two levers alone does not get you there.';
            }

            // A mix of on-track and unreachable columns with no measurable crossing between grid
            // cells: the map still tells the story column by column.
            return 'Some pairings stay on track across the whole explored range and others fall short throughout — read the map below column by column.';
        }

        $first = $crossed[0];
        $last = $crossed[count($crossed) - 1];
        $ceilingA = ThresholdPresenter::formatLeverValue($outcome->thresholdLever, $first->crossing->estimate);
        $condA = ThresholdPresenter::formatLeverValue($outcome->conditionLever, $first->conditionValue);

        $headlinePair = $outcome->thresholdLever === LeverKey::BuyPrice && $outcome->conditionLever === LeverKey::RetirementAge;

        if (count($crossed) === 1) {
            return $headlinePair
                ? "On these figures the money stays on track up to about {$ceilingA} on the new home with retirement at {$condA}; the other retirement ages explored have no crossing inside the price range — read their columns below."
                : "On these figures the limit sits at about {$ceilingA} when the second lever is held at {$condA}; the other held values have no crossing inside the explored range — read their columns below.";
        }

        $ceilingB = ThresholdPresenter::formatLeverValue($outcome->thresholdLever, $last->crossing->estimate);
        $condB = ThresholdPresenter::formatLeverValue($outcome->conditionLever, $last->conditionValue);

        return $headlinePair
            ? "On these figures the price ceiling moves with the retirement age: the money stays on track up to about {$ceilingA} on the new home with retirement at {$condA}, and up to about {$ceilingB} with retirement at {$condB}."
            : "On these figures the limit moves with the pairing: about {$ceilingA} at {$condA}, and about {$ceilingB} at {$condB}.";
    }

    /**
     * One column of the map: the held condition value and where the threshold lever crosses the
     * target there, as a chip that reads on its own ("up to about £260,000").
     *
     * @return array{condition: string, verdict: string, ceiling: ?string, bandLow: ?string, bandHigh: ?string, chip: string}
     */
    private static function column(FrontierOutcome $outcome, FrontierPoint $point): array
    {
        $crossing = $point->crossing;
        $ceiling = $crossing->estimate === null
            ? null
            : ThresholdPresenter::formatLeverValue($outcome->thresholdLever, $crossing->estimate);

        // Which way the chip reads: a Decreasing threshold lever (buy price, essential spend) has
        // an on-track side BELOW the crossing ("up to about X"); an Increasing one (retirement
        // age) is on track above it ("from about X").
        $decreasing = $point->curve->direction === LeverDirection::Decreasing;

        $chip = match ($crossing->verdict) {
            CrossingVerdict::AlreadyOnTrack => 'on track across the range',
            CrossingVerdict::Unreachable => 'below target across the range',
            CrossingVerdict::Crosses => $decreasing ? "up to about {$ceiling}" : "from about {$ceiling}",
            CrossingVerdict::NonMonotone => "first crossing about {$ceiling} — crosses more than once",
        };

        return [
            'condition' => ThresholdPresenter::formatLeverValue($outcome->conditionLever, $point->conditionValue),
            'verdict' => $crossing->verdict->name,
            'ceiling' => $ceiling,
            'bandLow' => $crossing->lowerLever === null ? null : ThresholdPresenter::formatLeverValue($outcome->thresholdLever, $crossing->lowerLever),
            'bandHigh' => $crossing->upperLever === null ? null : ThresholdPresenter::formatLeverValue($outcome->thresholdLever, $crossing->upperLever),
            'chip' => $chip,
        ];
    }

    /**
     * The heatmap: one row per threshold-grid value (highest first, so "spend more" reads
     * upwards), one cell per held condition value, each cell the measured Monte Carlo success
     * with an above/below-target flag. The iso-line is the boundary where a column's cells flip —
     * derived from the same cells it is drawn over, so the two cannot disagree.
     *
     * @return array{conditionLabels: list<string>, rows: list<array{label: string, cells: list<array{pct: string, above: bool}>}>}
     */
    private static function grid(FrontierOutcome $outcome): array
    {
        $points = $outcome->frontier->points;
        $thresholdValues = array_map(
            static fn ($pt): float => $pt->leverValue,
            $points === [] ? [] : $points[0]->curve->points,
        );

        $rows = [];
        for ($i = count($thresholdValues) - 1; $i >= 0; $i--) {
            $cells = [];
            foreach ($points as $column) {
                $cell = $column->curve->points[$i] ?? null;
                $cells[] = $cell === null
                    ? ['pct' => '—', 'above' => false]
                    : [
                        'pct' => (string) round($cell->successProbability * 100).'%',
                        'above' => $cell->successProbability >= $outcome->targetProbability,
                    ];
            }
            $rows[] = [
                'label' => ThresholdPresenter::formatLeverValue($outcome->thresholdLever, $thresholdValues[$i]),
                'cells' => $cells,
            ];
        }

        return [
            'conditionLabels' => array_map(
                static fn (FrontierPoint $p): string => ThresholdPresenter::formatLeverValue($outcome->conditionLever, $p->conditionValue),
                $points,
            ),
            'rows' => $rows,
        ];
    }

    private static function pct(float $p): string
    {
        return rtrim(rtrim(number_format($p * 100, 1), '0'), '.').'%';
    }
}
