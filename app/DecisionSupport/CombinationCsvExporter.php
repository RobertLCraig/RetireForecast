<?php

declare(strict_types=1);

namespace App\DecisionSupport;

use App\Export\ExportDisclaimer;
use App\Forecast\WhatIfChanges;

/**
 * Builds the CSV rows for the combination-comparison surface (Phase 3): the guidance-only
 * disclaimer, then one row per compared plan with its Monte Carlo figures — the chance the
 * essentials and the full spend last, how often the money runs short (and the typical year),
 * and the spread of usable wealth left. The analyst's exact numbers, carrying the decimals the
 * on-screen chips deliberately hide.
 *
 * The rows are always in plan order (base first); ordering is advice and is never baked into a
 * downloaded file. Kept separate from the HTTP layer so the exact rows can be unit-tested; the
 * controller only streams them.
 */
final class CombinationCsvExporter
{
    /**
     * @param  array{rows: list<array<string, mixed>>, anyMissing: bool, callout: ?string}  $comparison  the {@see CombinationComparison::build()} output
     * @return list<list<int|string>>
     */
    public static function rows(array $comparison): array
    {
        $rows = array_map(static fn (string $line): array => [$line], ExportDisclaimer::LINES);
        $rows[] = [];
        $rows[] = ['How your plans compare across your simulated futures (Monte Carlo, today\'s money).'];
        $rows[] = [];

        $rows[] = [
            'Plan',
            'Base plan',
            'Changes from base',
            'Chance essentials last',
            'Chance full spend last',
            'Runs short',
            'Typical run-short year',
            'Usable wealth left (p10)',
            'Usable wealth left (median)',
            'Simulated paths',
        ];

        foreach ($comparison['rows'] as $row) {
            $figures = $row['figures'] ?? null;
            if ($figures === null) {
                $rows[] = [
                    $row['name'],
                    $row['isBase'] ? 'Yes' : 'No',
                    self::changes($row['changes']),
                    'Not simulated yet', '', '', '', '', '', '',
                ];

                continue;
            }

            $rows[] = [
                $row['name'],
                $row['isBase'] ? 'Yes' : 'No',
                self::changes($row['changes']),
                $figures['successEssentials'],
                $figures['successFullSpend'],
                $figures['runsShort'],
                $figures['runsShortYear'] ?? 'n/a (lasts)',
                $figures['p10Usable'] ?? '',
                $figures['medianUsable'] ?? '',
                $figures['paths'],
            ];
        }

        return $rows;
    }

    /**
     * The what-if's changes as one cell ("Essentials · amount: £60,000; Retirement age: 68"),
     * or empty for the base. Reads the same {@see WhatIfChanges} shape the on-screen
     * change tags use, so the download names a change exactly as the screen does.
     *
     * @param  list<array{label: string, from: string, to: string}>  $changes
     */
    private static function changes(array $changes): string
    {
        return implode('; ', array_map(static fn (array $c): string => "{$c['label']}: {$c['to']}", $changes));
    }
}
