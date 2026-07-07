<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Assistant\AssistantFact;
use App\Enums\SimulationStatus;
use App\Models\Scenario;
use App\Models\ThresholdResult;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;

/**
 * Decision-support Phase 6: the scenario's computed lever limits as assistant facts. The
 * assistant STATES a threshold in plain English; it never calculates one — so a limit only
 * reaches the context if it was actually computed for EXACTLY the scenario's current inputs.
 *
 * Staleness gate (the load-bearing rule): a row qualifies only when its stored `inputs_hash`
 * still matches a hash recomputed from the row's own parameters against the scenario's CURRENT
 * effective form-state (+ engine version + seed). Builder edits already delete threshold rows
 * (Phase 1's invalidation); this re-check is the belt-and-braces the plan requires before the
 * assistant may voice a figure — G1 then can't restate a stale limit, because a stale limit is
 * never in the grounding allow-list at all.
 *
 * Wording: the 1-D sentence is {@see ThresholdPresenter::meterCaption} — the SAME copy the
 * meter shows — and the 2-D summary is {@see FrontierPresenter}'s, so the assistant and the
 * panels can never disagree, and the neutral-phrasing guardrails (no "safe"/"should"; a limit
 * is always an "about" band) hold in one home.
 */
final class ThresholdFacts
{
    public function __construct(private readonly ThresholdRunner $runner) {}

    /**
     * The facts for every fresh computed limit — or, when none qualify, a single honest
     * "none computed yet" fact so the model says so instead of improvising.
     *
     * @return list<AssistantFact>
     */
    public function for(Scenario $scenario): array
    {
        $facts = [];
        $seen = [];

        $rows = ThresholdResult::query()
            ->where('scenario_id', $scenario->id)
            ->where('status', SimulationStatus::Done)
            ->latest()
            ->get();

        foreach ($rows as $row) {
            if (! $this->matchesCurrentInputs($scenario, $row)) {
                continue; // stale: computed against inputs the scenario no longer has
            }

            // Latest per question (lever + person + condition + metric + target): an older
            // duplicate answer to the same question adds noise, not information.
            $key = implode('|', [
                $row->lever_key, $row->lever_param ?? '', $row->condition_lever_key ?? '',
                $row->metric, (string) $row->target_probability,
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $fact = $this->factFor($scenario, $row);
            if ($fact !== null) {
                $facts[] = $fact;
            }
        }

        if ($facts === []) {
            return [new AssistantFact(
                'How far can we go — computed limits',
                'None computed for the current inputs yet. The "How far can we go?" panel on the results page computes them (each is a full Monte Carlo sweep); once computed, the limits appear here.',
            )];
        }

        return $facts;
    }

    /**
     * Whether $row was computed for exactly the scenario's current inputs: recompute the hash
     * from the row's own stored parameters against the CURRENT effective form-state and compare.
     */
    private function matchesCurrentInputs(Scenario $scenario, ThresholdResult $row): bool
    {
        $expected = $this->runner->inputsHash(
            $scenario,
            $row->leverKey(),
            $row->metricEnum(),
            $row->target_probability,
            $row->grid,
            $row->n_paths,
            leverParam: $row->lever_param,
            conditionLever: $row->conditionLeverKey(),
            conditionGrid: $row->condition_grid,
        );

        return hash_equals($expected, $row->inputs_hash);
    }

    /** One fresh row as one labelled fact (frontier, care before/after, or a 1-D limit). */
    private function factFor(Scenario $scenario, ThresholdResult $row): ?AssistantFact
    {
        if ($row->isFrontier()) {
            return $this->frontierFact($row);
        }

        $outcome = $row->thresholdOutcome();
        if ($outcome === null) {
            return null; // defensive: a Done row with no payload has nothing to state
        }

        if ($outcome->lever === LeverKey::Care) {
            return $this->careFact($outcome);
        }

        return $this->limitFact($scenario, $row, $outcome);
    }

    /** The 2-D trade-off map: the both-levers-pinned summary + each column's banded chip. */
    private function frontierFact(ThresholdResult $row): ?AssistantFact
    {
        $outcome = $row->frontierOutcome();
        if ($outcome === null) {
            return null;
        }

        $view = FrontierPresenter::view($outcome);
        $columns = implode('; ', array_map(
            static fn (array $c): string => "{$c['condition']}: {$c['chip']}",
            $view['columns'],
        ));

        return new AssistantFact(
            "How far can we go — the trade-off between {$view['thresholdLabel']} and {$view['conditionLabel']}",
            "{$view['summary']} Column by column — {$columns}. (Success bar: a {$view['targetPct']} chance the essentials are covered; each limit is a Monte Carlo band, not a hard line.)",
        );
    }

    /** The care toggle: a pinned before/after (two independent runs), never an interpolated limit. */
    private function careFact(ThresholdOutcome $outcome): ?AssistantFact
    {
        $comparison = ThresholdPresenter::careComparison($outcome);
        if ($comparison === null) {
            return null;
        }

        [$off, $on] = $comparison['states'];
        $pct = static fn (float $p): string => round($p * 100, 1).'%';

        return new AssistantFact(
            'How far can we go — whether late-life care fees are modelled (a pinned before/after, not a limit)',
            "With care fees not modelled, the chance the essentials are covered for life is {$pct($off['p'])} "
            ."(95% range {$pct($off['ciLow'])} to {$pct($off['ciHigh'])}); with care fees modelled it is {$pct($on['p'])} "
            ."({$pct($on['ciLow'])} to {$pct($on['ciHigh'])}). {$comparison['deltaCaption']}",
        );
    }

    /** A continuous lever's limit: the meter's own sentence + the crossing band + the success bar. */
    private function limitFact(Scenario $scenario, ThresholdResult $row, ThresholdOutcome $outcome): AssistantFact
    {
        $lever = $outcome->lever;
        $crossing = $outcome->crossing;
        $caption = ThresholdPresenter::meterCaption(
            $lever, $crossing, $outcome->curve->direction === LeverDirection::Increasing,
        );

        $value = $caption;
        if ($crossing->lowerLever !== null && $crossing->upperLever !== null) {
            $low = ThresholdPresenter::formatLeverValue($lever, $crossing->lowerLever);
            $high = ThresholdPresenter::formatLeverValue($lever, $crossing->upperLever);
            $value .= " The exact crossing sits between {$low} and {$high} (a Monte Carlo band, not a hard line).";
        }
        $target = rtrim(rtrim(number_format($outcome->targetProbability * 100, 1), '0'), '.');
        $value .= " Success bar: a {$target}% chance ".($outcome->metric->value === 'essentials'
            ? 'the essential spending is covered for life.'
            : 'the full (essential + discretionary) spending is covered for life.');

        return new AssistantFact('How far can we go — '.$this->leverLabel($scenario, $row), $value);
    }

    /**
     * The lever's human label, person-aware for a parameterised lever ("how long Alex lives"),
     * matching the explorer's menu naming so the assistant and the panel call it the same thing.
     * Two per-person rows must never share a label — the person is the point.
     */
    private function leverLabel(Scenario $scenario, ThresholdResult $row): string
    {
        $key = $row->leverKey();
        $param = $row->lever_param;
        if ($param === null || ! in_array($key, [LeverKey::PersonLongevity, LeverKey::StatePensionDeferral], true)) {
            return $key->label();
        }

        foreach ($scenario->toHousehold()->persons as $i => $person) {
            if ($person->id === $param) {
                $name = $person->name !== null && $person->name !== '' ? $person->name : 'Person '.($i + 1);

                return $key === LeverKey::PersonLongevity
                    ? "how long {$name} lives"
                    : "how long {$name} defers their State Pension";
            }
        }

        return $key->label();
    }
}
