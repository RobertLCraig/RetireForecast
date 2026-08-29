<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Assistant\ScenarioEditCapture;
use App\Enums\ScenarioStatus;
use App\Models\Scenario;
use Throwable;

/**
 * The one place a delta-child what-if is written. Every producer of one — the quick presets
 * ({@see QuickWhatIf}) and the assistant's Change tab ({@see ScenarioEditCapture})
 * — hands over a name plus a sparse `overrides` delta and gets back a saved child. So a
 * generated what-if is indistinguishable from a hand-built one afterwards: the same delta over
 * the same base, `builder_state` left empty, the structural columns projected from the
 * effective state.
 *
 * The child is ASSEMBLED before it is saved, so a bad edit throws here and nothing is
 * persisted, rather than leaving a broken what-if behind (no silent failure).
 */
final class WhatIfWriter
{
    /**
     * Persist a delta-child of $base carrying $overrides, named uniquely within the family.
     *
     * @param  array<string, mixed>  $overrides  sparse dot-path => leaf value
     *
     * @throws Throwable when the edited inputs do not assemble into a household
     */
    public static function create(Scenario $base, string $name, array $overrides): Scenario
    {
        $child = new Scenario;
        $child->user_id = $base->user_id;
        $child->parent_scenario_id = $base->id;
        $child->setRelation('parent', $base);
        $child->overrides = ['name' => self::uniqueName($base, $name)] + $overrides;
        $child->builder_state = [];
        $child->status = ScenarioStatus::Ready;
        $child->projectFrom($child->effectiveBuilderState());

        $child->toHousehold();   // assemble first: a bad edit throws BEFORE anything is persisted

        $child->save();

        return $child;
    }

    /** Keep repeated what-ifs distinct: "Retire 2 years later", then "… (2)", "… (3)". */
    private static function uniqueName(Scenario $base, string $name): string
    {
        $existing = $base->children()->pluck('name')->all();
        if (! in_array($name, $existing, true)) {
            return $name;
        }

        $n = 2;
        while (in_array("{$name} ({$n})", $existing, true)) {
            $n++;
        }

        return "{$name} ({$n})";
    }
}
