<?php

declare(strict_types=1);

namespace App\Forecast;

/**
 * The one merge function behind delta-child what-ifs (Phase C2). A child scenario is
 * stored as a sparse *delta* of overridden leaves on top of its base, never a full
 * copy — so the base stays the single source of truth and a later base fix flows
 * through to every child instead of leaving them forked (DECISIONS 2026-06-25).
 *
 * Overrides are a flat map of dot-paths to leaf values, for example:
 *   'expense.essential'              => '31000'
 *   'housing.annualRent'             => '20000'
 *   'people.p1.grossSalary'          => '70000'
 *   'pensions.<id>.currentValue'     => '450000'
 *
 * List items (people, pensions, accounts, income streams, one-off costs, withdrawals)
 * are addressed by their **stable id**, never their array index, so an override keeps
 * targeting the right row across base edits that reorder or insert rows (gotcha N).
 *
 *   effective = base  ⊕  overrides     {@see merge()}
 *   overrides = effective  −  base      {@see diff()}
 *
 * and {@see merge()} ∘ {@see diff()} is the identity over a shared form-state shape
 * (proved by the round-trip test). Overrides only *change* existing leaves: adding or
 * removing a list row is a structural change a delta cannot represent without forking,
 * caught up front by {@see structurallyDiffers()} rather than silently dropped.
 */
final class BuilderStateDelta
{
    /**
     * The sparse override map of everything in $effective that differs from $base.
     * Only leaves present in $effective are considered, so a child never removes a
     * base key, it only re-points one (keeping the shared shape stable).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $effective
     * @return array<string, mixed> flat dot-path => leaf value
     */
    public static function diff(array $base, array $effective): array
    {
        $out = [];
        self::walkDiff($base, $effective, '', $out);

        return $out;
    }

    /**
     * Apply the overrides onto a copy of the base, returning the effective form-state.
     * An override whose path no longer resolves in the base (an orphan, because the
     * base shape changed) is skipped here and surfaced by {@see orphans()} — never
     * applied blindly.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $overrides): array
    {
        $result = $base;
        foreach ($overrides as $path => $value) {
            self::setPath($result, explode('.', (string) $path), $value);
        }

        return $result;
    }

    /**
     * The override paths that no longer resolve against $base — for example a pension
     * the base later deleted. Surfaced to the user so a stale what-if is visible, not
     * silently ignored (no silent failure).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return list<string>
     */
    public static function orphans(array $base, array $overrides): array
    {
        $orphans = [];
        foreach ($overrides as $path => $value) {
            $copy = $base;
            if (! self::setPath($copy, explode('.', (string) $path), $value)) {
                $orphans[] = (string) $path;
            }
        }

        return $orphans;
    }

    /**
     * True if $effective adds or removes a list row relative to $base (the id-set of
     * any row-list differs at any depth). Such a change cannot be stored as a leaf
     * delta, so the child save is refused with guidance rather than forking the base.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $effective
     */
    public static function structurallyDiffers(array $base, array $effective): bool
    {
        return self::walkStructural($base, $effective);
    }

    /**
     * @param  array<string, mixed>  $out
     */
    private static function walkDiff(mixed $base, mixed $effective, string $prefix, array &$out): void
    {
        if (is_array($effective) && self::isMap($effective)) {
            foreach ($effective as $key => $value) {
                $baseValue = is_array($base) ? ($base[$key] ?? null) : null;
                self::walkDiff($baseValue, $value, self::join($prefix, (string) $key), $out);
            }

            return;
        }

        if (is_array($effective) && self::isRowList($effective)) {
            $baseById = self::byId(is_array($base) ? $base : []);
            foreach ($effective as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue; // unkeyed row — only happens pre-normalise; nothing to target
                }
                self::walkDiff($baseById[$id] ?? null, $row, self::join($prefix, $id), $out);
            }

            return;
        }

        // Scalar (or an empty list treated as a leaf): record it when it differs.
        if ($base !== $effective) {
            $out[$prefix] = $effective;
        }
    }

    /**
     * Walk a dotted path, descending maps by key and row-lists by id, and set the leaf.
     * Returns false (without mutating) if the path cannot be resolved against $node.
     *
     * @param  array<string, mixed>|list<mixed>  $node
     * @param  list<string>  $segments
     */
    private static function setPath(array &$node, array $segments, mixed $value): bool
    {
        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        if (self::isRowList($node)) {
            foreach ($node as $i => $row) {
                if ((string) ($row['id'] ?? '') === $segment) {
                    if ($rest === []) {
                        $node[$i] = $value;

                        return true;
                    }
                    if (! is_array($node[$i])) {
                        return false;
                    }

                    return self::setPath($node[$i], $rest, $value);
                }
            }

            return false; // orphan: no row carries this id
        }

        if ($rest === []) {
            $node[$segment] = $value;

            return true;
        }

        if (! isset($node[$segment]) || ! is_array($node[$segment])) {
            return false; // base shape no longer goes this deep
        }

        return self::setPath($node[$segment], $rest, $value);
    }

    private static function walkStructural(mixed $base, mixed $effective): bool
    {
        if (is_array($effective) && self::isRowList($effective)) {
            $baseIds = self::ids(is_array($base) ? $base : []);
            $effectiveIds = self::ids($effective);
            if ($baseIds !== $effectiveIds) {
                return true;
            }
            $baseById = self::byId(is_array($base) ? $base : []);
            foreach ($effective as $row) {
                if (self::walkStructural($baseById[(string) ($row['id'] ?? '')] ?? null, $row)) {
                    return true;
                }
            }

            return false;
        }

        if (is_array($effective) && self::isMap($effective)) {
            foreach ($effective as $key => $value) {
                $baseValue = is_array($base) ? ($base[$key] ?? null) : null;
                if (self::walkStructural($baseValue, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A non-empty associative array (an object-shaped node, not a positional list). */
    private static function isMap(array $value): bool
    {
        return $value !== [] && ! array_is_list($value);
    }

    /** A non-empty positional list whose every element is a row carrying an id. */
    private static function isRowList(array $value): bool
    {
        if ($value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $row) {
            if (! is_array($row) || ! array_key_exists('id', $row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function byId(array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $byId[(string) $row['id']] = $row;
            }
        }

        return $byId;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<string> the row ids, sorted, so set comparison ignores order
     */
    private static function ids(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && array_key_exists('id', $row)) {
                $ids[] = (string) $row['id'];
            }
        }
        sort($ids);

        return $ids;
    }

    private static function join(string $prefix, string $segment): string
    {
        return $prefix === '' ? $segment : "{$prefix}.{$segment}";
    }
}
