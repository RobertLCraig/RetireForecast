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
 * (proved by the round-trip test). A child may also **add** a list row (stored whole at
 * its id path, e.g. `oneOffCosts.<id>` => the row map) or **remove** one (stored as
 * `oneOffCosts.<id>` => {@see REMOVED}); {@see merge()} appends the adds and drops the
 * removals. An add is kept distinct from an *orphaned* value override (a leaf whose row
 * the base later deleted, surfaced by {@see orphans()}) because an add carries the whole
 * row while a value override is a leaf path — so orphan detection still works.
 */
final class BuilderStateDelta
{
    /** Sentinel override value marking a base list row the child removed. */
    public const REMOVED = '__removed__';

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
     * The leaf value at $path in $state, descending maps by key and row-lists by id (the
     * same addressing {@see merge()} writes with), or null if the path does not resolve.
     * Lets a consumer read the base value an override replaces, so a what-if can show
     * "what changed" (base value -> new value) rather than just the new value.
     *
     * @param  array<string, mixed>  $state
     */
    public static function valueAt(array $state, string $path): mixed
    {
        return self::getPath($state, explode('.', $path));
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
            $seen = [];
            foreach ($effective as $row) {
                $id = (string) ($row['id'] ?? '');
                if ($id === '') {
                    continue; // unkeyed row — only happens pre-normalise; nothing to target
                }
                $seen[$id] = true;
                if (! isset($baseById[$id])) {
                    // A row the base does not have: an ADDED row. Store it whole at its id path
                    // (not as separate leaves), so merge can rebuild it and it reads as one add.
                    $out[self::join($prefix, $id)] = $row;

                    continue;
                }
                self::walkDiff($baseById[$id], $row, self::join($prefix, $id), $out);
            }
            // A row the base had but the child dropped: a REMOVED row, marked by the sentinel.
            foreach ($baseById as $id => $row) {
                if (! isset($seen[$id])) {
                    $out[self::join($prefix, (string) $id)] = self::REMOVED;
                }
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
                        if ($value === self::REMOVED) {
                            array_splice($node, $i, 1); // a removed row: drop it

                            return true;
                        }
                        $node[$i] = $value;

                        return true;
                    }
                    if (! is_array($node[$i])) {
                        return false;
                    }

                    return self::setPath($node[$i], $rest, $value);
                }
            }

            // No row carries this id. Removing an already-absent row is a no-op success; an
            // ADDED row (the whole row addressed at its id path) is appended; but a *leaf*
            // override whose row the base no longer has is a genuine orphan (false).
            if ($value === self::REMOVED) {
                return true;
            }
            if ($rest === [] && is_array($value)) {
                $node[] = $value;

                return true;
            }

            return false; // orphan: a leaf override with no row to target
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

    /**
     * Read the leaf at $segments, descending maps by key and row-lists by id — the read
     * mirror of {@see setPath()}. Returns null (without error) if the path runs off the
     * shape, so a base that no longer goes this deep reads as "no prior value".
     *
     * @param  list<string>  $segments
     */
    private static function getPath(mixed $node, array $segments): mixed
    {
        if (! is_array($node) || $segments === []) {
            return null;
        }

        $segment = $segments[0];
        $rest = array_slice($segments, 1);

        if (self::isRowList($node)) {
            foreach ($node as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === $segment) {
                    return $rest === [] ? $row : self::getPath($row, $rest);
                }
            }

            return null; // no row carries this id
        }

        if (! array_key_exists($segment, $node)) {
            return null;
        }

        return $rest === [] ? $node[$segment] : self::getPath($node[$segment], $rest);
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

    private static function join(string $prefix, string $segment): string
    {
        return $prefix === '' ? $segment : "{$prefix}.{$segment}";
    }
}
