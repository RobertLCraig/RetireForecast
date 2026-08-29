<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Enums\ScenarioVariant;
use App\Forecast\AssumptionOverrides;
use App\Forecast\BuilderStateDelta;
use App\Forecast\WhatIfChanges;
use App\Models\Scenario;

/**
 * Guardrail C2 — the closed set of fields the assistant may change on a plan. The app builds
 * this menu FROM the base's own effective form-state, so every target is a real path that
 * already resolves there with a known type and a visible current value; the model is handed
 * the menu and may only pick from it. It can therefore never fabricate a path, and never
 * reach a field this menu does not offer.
 *
 * Phase 1 breadth (PLAN-assistant-scenario-editing §5.1): the value edits a reader actually
 * asks for out loud — a working person's salary and planned retirement age, a pension pot or
 * account balance, a spending line, the economic assumptions, and which housing plan is on
 * display. Structural adds and removes are Phase 2 and are NOT offered; a field the chat
 * cannot reach is fine (chat is a subset of the builder), a wrongly-typed one is not.
 *
 * A path is offered only when it already resolves in the base state, because an override on a
 * path the base does not carry cannot be merged back (it would be dropped as an orphan) — so
 * an assumption the reader has never overridden in the builder is not on the menu.
 */
final class ScenarioEditVocabulary
{
    /** Employment statuses for which a salary and a planned retirement age are real inputs. */
    private const WORKING = ['employed', 'self_employed'];

    /**
     * The editable targets for $base, in the order the panel lists them.
     *
     * @return list<EditTarget>
     */
    public static function for(Scenario $base): array
    {
        $state = $base->baseScenario()->effectiveBuilderState();
        $targets = [];

        if (isset($state['variant'])) {
            $targets[] = self::target($state, 'variant', 'enum', array_column(ScenarioVariant::cases(), 'value'));
        }

        foreach (self::rows($state, 'people') as $person) {
            if (! in_array((string) ($person['employmentStatus'] ?? ''), self::WORKING, true)) {
                continue;   // a retired person has no salary or retirement age to move
            }
            $id = (string) $person['id'];
            $targets[] = self::target($state, "people.{$id}.grossSalary", 'money');
            $targets[] = self::target($state, "people.{$id}.plannedRetirementAge", 'int');
        }

        foreach ([['pensions', 'currentValue'], ['accounts', 'balance'], ['expenseLines', 'amount']] as [$collection, $field]) {
            foreach (self::rows($state, $collection) as $row) {
                if (! is_numeric($row[$field] ?? null)) {
                    continue;   // e.g. a DB pension has no pot value; a state pension has no balance
                }
                $targets[] = self::target($state, "{$collection}.{$row['id']}.{$field}", 'money');
            }
        }

        foreach (AssumptionOverrides::KEYS as $key) {
            if (isset($state['assumptionOverrides'][$key]) && $state['assumptionOverrides'][$key] !== '') {
                $targets[] = self::target($state, "assumptionOverrides.{$key}", 'rate');
            }
        }

        return array_values(array_filter($targets));
    }

    /**
     * The menu the model is shown, one target per line.
     *
     * @param  list<EditTarget>  $targets
     */
    public static function menu(array $targets): string
    {
        return implode("\n", array_map(fn (EditTarget $t): string => $t->menuLine(), $targets));
    }

    /**
     * The target carrying $path, or null when the menu does not offer it (guardrail C2).
     *
     * @param  list<EditTarget>  $targets
     */
    public static function find(array $targets, string $path): ?EditTarget
    {
        foreach ($targets as $target) {
            if ($target->path === $path) {
                return $target;
            }
        }

        return null;
    }

    /**
     * A target read straight out of the base state, so its label, type and current value all
     * come from the one source. Null when the path does not resolve (never offered).
     *
     * @param  array<string, mixed>  $state
     * @param  list<string>  $options
     */
    private static function target(array $state, string $path, string $type, array $options = []): ?EditTarget
    {
        $current = BuilderStateDelta::valueAt($state, $path);
        if ($current === null || is_array($current)) {
            return null;
        }

        return new EditTarget($path, WhatIfChanges::label($path, $state), $type, (string) $current, $options);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private static function rows(array $state, string $collection): array
    {
        return array_values(array_filter(
            is_array($state[$collection] ?? null) ? $state[$collection] : [],
            fn ($row): bool => is_array($row) && isset($row['id']),
        ));
    }
}
