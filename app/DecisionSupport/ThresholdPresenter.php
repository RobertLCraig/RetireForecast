<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Forecast\ResultPresenter;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Sweep\Crossing;
use RetireForecast\FinanceEngine\Sweep\CrossingVerdict;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;

/**
 * Turns a decision-support threshold into the "How far can we go?" panel's view models: the
 * instant deterministic net-position line at the current slider value, a green→red meter track
 * showing how far the lever can move before the money stops lasting, the analyst's full sweep
 * S-curve + grid, and a natural-frequency pictograph headline.
 *
 * Audience split (the plan's load-bearing constraint): the headline is plain-language/visual (a
 * word verdict + a 10-dot "N of 10 futures" pictograph, year-first, never a bare percentage);
 * the exact numbers live in the collapsed drill-down. Neutral-copy guardrails are honoured here:
 * the word "safe" never appears (we say "on track" / "the money lasts"), the meter carries an
 * icon + text (never colour alone), and the death vertical is recoloured off the shortfall-red
 * band so the two reds do not collide.
 */
final class ThresholdPresenter
{
    /** Shortfall shading is red (#ef4444); recolour the death vertical to slate so they don't collide. */
    private const SHORTFALL_SAFE_DEATH_COLOUR = '#1e293b';

    /**
     * The live deterministic net-position line at the current lever value. Reuses
     * {@see ResultPresenter::burndown} (a single plan) so the line uses the SAME usable-wealth
     * definition (liquid + pension, continued below £0 by the cumulative shortfall) as the
     * cashflow ladder and Compare — it can't drift. Adds the £-abbreviating axis and recolours
     * the death vertical off the shortfall-red band.
     *
     * @return array{options: array<string, mixed>, rows: list<array{year: int, net: ?string}>, dipsNegative: bool, runsOutYear: ?int}
     */
    public static function netPosition(ForecastResult $forecast, Household $household, string $label): array
    {
        $milestones = ResultPresenter::milestones($household, $forecast, homeSold: false);
        $annotations = self::recolourDeath(ResultPresenter::milestoneAnnotations($milestones));

        $chart = ResultPresenter::burndown([['name' => $label, 'forecast' => $forecast]], $annotations);
        $chart['options']['moneyAxis'] = true; // the £-abbreviating, sign-aware axis (-£80k)

        $table = [];
        $cells = $chart['rows'][0]['cells'] ?? [];
        foreach ($chart['years'] as $year) {
            $table[] = ['year' => $year, 'net' => $cells[$year] ?? null];
        }

        return [
            'options' => $chart['options'],
            'rows' => $table,
            'dipsNegative' => $chart['dipsNegative'],
            'runsOutYear' => $forecast->depletionCalendarYear, // null = the money lasts to the end
        ];
    }

    /**
     * The green→red meter track: the lever's explored domain, where it crosses the target
     * (the on-track boundary), which side is on-track (from the lever's monotone direction),
     * and whether the current slider value is on the on-track side. Positions are 0..1 fractions
     * of the domain so the blade can place the boundary and the marker without re-deriving them.
     *
     * @return array<string, mixed>
     */
    public static function meter(ThresholdOutcome $outcome, LeverKey $lever, float $currentValue): array
    {
        $curve = $outcome->curve;
        $crossing = $outcome->crossing;
        $values = array_map(static fn ($p): float => $p->leverValue, $curve->points);
        $min = $values === [] ? 0.0 : min($values);
        $max = $values === [] ? 0.0 : max($values);
        $span = $max - $min;

        // Increasing lever (retirement age): higher values are on-track, so the green zone is the
        // upper end. Decreasing (buy price, essential spend): lower values are on-track.
        $increasing = $curve->direction === LeverDirection::Increasing;

        $frac = static fn (?float $v): ?float => $v === null || $span <= 0.0 ? null : max(0.0, min(1.0, ($v - $min) / $span));

        $onTrack = match ($crossing->verdict) {
            CrossingVerdict::AlreadyOnTrack => true,
            CrossingVerdict::Unreachable => false,
            default => $crossing->estimate === null
                ? null
                : ($increasing ? $currentValue >= $crossing->estimate : $currentValue <= $crossing->estimate),
        };

        return [
            'verdict' => $crossing->verdict->name,
            'increasing' => $increasing,
            'min' => $min,
            'max' => $max,
            'minLabel' => self::formatLeverValue($lever, $min),
            'maxLabel' => self::formatLeverValue($lever, $max),
            'crossing' => $crossing->estimate,
            'crossingLabel' => $crossing->estimate === null ? null : self::formatLeverValue($lever, $crossing->estimate),
            'crossingFrac' => $frac($crossing->estimate),
            'currentFrac' => $frac($currentValue) ?? 0.0,
            'onTrack' => $onTrack,
            'bandLow' => $crossing->lowerLever === null ? null : self::formatLeverValue($lever, $crossing->lowerLever),
            'bandHigh' => $crossing->upperLever === null ? null : self::formatLeverValue($lever, $crossing->upperLever),
            'caption' => self::meterCaption($lever, $crossing, $increasing),
        ];
    }

    /**
     * The analyst's full sweep: the success-probability S-curve (lever value vs chance the money
     * lasts) with the target line and the crossing marked, plus the grid table (each point with
     * its Monte Carlo confidence interval). This is the disclosed "show the full sweep" view — a
     * percentage axis is fine here; the guardrail against a bare-percentage HEADLINE lives above.
     *
     * @return array{options: array<string, mixed>, rows: list<array{value: string, p: float, ciLow: float, ciHigh: float, paths: int}>}
     */
    public static function sCurve(ThresholdOutcome $outcome, LeverKey $lever): array
    {
        $curve = $outcome->curve;
        $crossing = $outcome->crossing;
        $target = round($outcome->targetProbability * 100, 1);

        $line = array_map(static fn ($p): array => [
            'x' => round($p->leverValue, 2),
            'y' => round($p->successProbability * 100, 1),
        ], $curve->points);

        $xaxisAnnotations = [];
        if ($crossing->estimate !== null) {
            $xaxisAnnotations[] = [
                'x' => round($crossing->estimate, 2),
                'borderColor' => '#dc2626',
                'strokeDashArray' => 4,
                'label' => [
                    'text' => 'the limit',
                    'orientation' => 'vertical',
                    'style' => ['fontSize' => '9px', 'color' => '#ffffff', 'background' => '#dc2626'],
                ],
            ];
        }

        $options = [
            'chart' => ['type' => 'line', 'height' => 320, 'toolbar' => ['show' => false]],
            'colors' => ['#1e3a8a'],
            'series' => [['name' => 'Chance the money lasts', 'data' => $line]],
            'stroke' => ['curve' => 'straight', 'width' => 3],
            'markers' => ['size' => 4],
            'dataLabels' => ['enabled' => false],
            'xaxis' => [
                'type' => 'numeric',
                'tickAmount' => 8,
                'decimalsInFloat' => 0,
                'title' => ['text' => ucfirst($curve->leverName).' ('.$curve->leverUnit.')'],
            ],
            'yaxis' => ['min' => 0, 'max' => 100, 'title' => ['text' => 'Chance the money lasts (%)']],
            'annotations' => [
                'yaxis' => [[
                    'y' => $target,
                    'borderColor' => '#16a34a',
                    'strokeDashArray' => 4,
                    'label' => ['text' => 'your target ('.rtrim(rtrim((string) $target, '0'), '.').'%)', 'style' => ['fontSize' => '9px', 'color' => '#ffffff', 'background' => '#16a34a']],
                ]],
                'xaxis' => $xaxisAnnotations,
            ],
            'legend' => ['show' => false],
        ];

        $rows = array_map(static fn ($p): array => [
            'value' => self::formatLeverValue($lever, $p->leverValue),
            'p' => round($p->successProbability * 100, 1),
            'ciLow' => round($p->ciLow * 100, 1),
            'ciHigh' => round($p->ciHigh * 100, 1),
            'paths' => $p->paths,
        ], $curve->points);

        return ['options' => $options, 'rows' => $rows];
    }

    /**
     * The natural-frequency pictograph: N of 10 filled dots ("about N of 10 futures your money
     * lasts"), year-first for the shortfall ("runs short around 2045"), never a bare percentage.
     * Reads the current plan's Monte Carlo success probability (essentials) + median run-out year.
     *
     * @return array{filled: int, empty: int, runsOutYear: ?int}
     */
    public static function pictograph(float $successProbability, ?int $runsOutYear): array
    {
        $filled = (int) round(max(0.0, min(1.0, $successProbability)) * 10);

        return [
            'filled' => $filled,
            'empty' => 10 - $filled,
            'runsOutYear' => $runsOutYear,
        ];
    }

    /**
     * A lever value formatted for people: a price or spend as whole pounds (a house price or an
     * annual spend reads in whole £, not pence — the lever sweeps in float pound-space), a
     * retirement age as an age.
     */
    public static function formatLeverValue(LeverKey $lever, float $value): string
    {
        return match ($lever) {
            LeverKey::RetirementAge => 'age '.(int) round($value),
            LeverKey::BuyPrice, LeverKey::EssentialSpend => '£'.number_format(round($value), 0),
            LeverKey::SurvivorDbFraction => (int) round($value).'%',
        };
    }

    private static function meterCaption(LeverKey $lever, Crossing $crossing, bool $increasing): string
    {
        if ($crossing->verdict === CrossingVerdict::AlreadyOnTrack) {
            return 'The money stays on track right across the range you explored — this lever is not the constraint here.';
        }
        if ($crossing->verdict === CrossingVerdict::Unreachable) {
            return 'Moving this lever alone does not get you to your target anywhere in the range explored — it needs pairing with another change.';
        }

        $at = $crossing->estimate === null ? '' : self::formatLeverValue($lever, $crossing->estimate);
        $preamble = $crossing->verdict === CrossingVerdict::NonMonotone
            ? 'This lever is not simple — it crosses your target more than once, so treat the first crossing as a signal, not a hard line. '
            : '';

        return $preamble.match ($lever) {
            LeverKey::BuyPrice => "On these figures the money stays on track up to about {$at} on the new home; spend more and the odds slip below your target.",
            LeverKey::EssentialSpend => "On these figures the money stays on track up to about {$at} of essential spending a year; spend more and the odds slip below your target.",
            LeverKey::RetirementAge => "On these figures the money reaches your target if the working partner retires at about {$at} or later; retiring earlier slips below it.",
            LeverKey::SurvivorDbFraction => "On these figures the money reaches your target if the survivor keeps about {$at} of the DB pension or more; a smaller survivor's pension slips below it.",
        };
    }

    /**
     * Recolour any death vertical off the shortfall-red band so the two reds don't read as one
     * (the plan's guardrail). Leaves every other milestone colour untouched.
     *
     * @param  list<array<string, mixed>>  $annotations
     * @return list<array<string, mixed>>
     */
    private static function recolourDeath(array $annotations): array
    {
        return array_map(static function (array $a): array {
            if (($a['borderColor'] ?? null) === '#dc2626') {
                $a['borderColor'] = self::SHORTFALL_SAFE_DEATH_COLOUR;
                if (isset($a['label']['borderColor'])) {
                    $a['label']['borderColor'] = self::SHORTFALL_SAFE_DEATH_COLOUR;
                }
                if (isset($a['label']['style']['background'])) {
                    $a['label']['style']['background'] = self::SHORTFALL_SAFE_DEATH_COLOUR;
                }
            }

            return $a;
        }, $annotations);
    }
}
