<?php

declare(strict_types=1);

namespace App\Finance;

use App\Console\Commands\RefreshMortalityData;
use RetireForecast\FinanceEngine\Mortality\OnsPeriodMortalityData;
use RuntimeException;

/**
 * Loads and validates the ONS period-mortality JSON resource — the sourced *home* for the
 * engine's {@see OnsPeriodMortalityData}, which is
 * generated from it — and compares two grids cell by cell.
 *
 * The JSON is the single home for the mortality figures; the generated PHP class is a
 * projection of it. Nothing previously checked that the two agreed, so a hand-edit or a
 * half-finished refresh could silently drift the class from its source. This is that guard
 * (the data-integrity rule: one definition, one home), plus the machinery to diff a freshly
 * downloaded ONS release against what we embed. Pure bar the file read, so it is unit-tested
 * and the {@see RefreshMortalityData} command stays thin.
 */
final class MortalityDataset
{
    /** The canonical JSON resource, relative to the project root. */
    public const RESOURCE = 'packages/finance-engine/resources/mortality/ons-2024-period-qx.json';

    /**
     * Decode and structurally validate a mortality JSON file (metadata + a male/female
     * period_qx grid). Throws loudly on anything malformed rather than defaulting.
     *
     * @return array<string, mixed>
     */
    public static function load(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Mortality JSON not found: {$path}");
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json)) {
            throw new RuntimeException("Mortality JSON is not valid JSON: {$path}");
        }

        foreach (['source', 'verified_on', 'ages', 'years', 'period_qx'] as $key) {
            if (! isset($json[$key])) {
                throw new RuntimeException("Mortality JSON is missing '{$key}': {$path}");
            }
        }
        foreach (['male', 'female'] as $sex) {
            if (! isset($json['period_qx'][$sex])) {
                throw new RuntimeException("Mortality JSON is missing period_qx.{$sex}: {$path}");
            }
        }

        return $json;
    }

    /**
     * Normalise a decoded JSON's period_qx to [sex => [age(int) => [year(int) => q(x)]]] with
     * integer keys (matching the engine's grid), asserting every declared age×year cell is
     * present — a missing cell fails loudly, never silently defaults (completeness).
     *
     * @param  array<string, mixed>  $json
     * @return array{male: array<int, array<int, float>>, female: array<int, array<int, float>>}
     */
    public static function grid(array $json): array
    {
        $ages = array_map('intval', $json['ages']);
        $years = array_map('intval', $json['years']);

        $grid = ['male' => [], 'female' => []];
        foreach (['male', 'female'] as $sex) {
            foreach ($ages as $age) {
                foreach ($years as $year) {
                    $value = $json['period_qx'][$sex][(string) $age][(string) $year]
                        ?? $json['period_qx'][$sex][$age][$year]
                        ?? null;
                    if ($value === null) {
                        throw new RuntimeException("Mortality JSON is missing cell period_qx.{$sex}.{$age}.{$year}");
                    }
                    $grid[$sex][$age][$year] = (float) $value;
                }
            }
        }

        return $grid;
    }

    /**
     * Compare two normalised grids cell by cell (cells present in $base). Returns how many
     * were compared, how many moved by more than $epsilon, the largest absolute move, and a
     * sample of the moves for display.
     *
     * @param  array{male: array<int, array<int, float>>, female: array<int, array<int, float>>}  $base
     * @param  array{male: array<int, array<int, float>>, female: array<int, array<int, float>>}  $incoming
     * @return array{compared: int, changed: int, maxAbsDelta: float, samples: list<array{sex: string, age: int, year: int, from: float, to: float}>}
     */
    public static function diff(array $base, array $incoming, float $epsilon = 1e-9, int $maxSamples = 12): array
    {
        $compared = 0;
        $changed = 0;
        $maxAbsDelta = 0.0;
        $samples = [];

        foreach ($base as $sex => $ages) {
            foreach ($ages as $age => $years) {
                foreach ($years as $year => $from) {
                    $to = $incoming[$sex][$age][$year] ?? null;
                    if ($to === null) {
                        continue;
                    }
                    $compared++;
                    $delta = abs($to - $from);
                    if ($delta > $epsilon) {
                        $changed++;
                        $maxAbsDelta = max($maxAbsDelta, $delta);
                        if (count($samples) < $maxSamples) {
                            $samples[] = ['sex' => $sex, 'age' => $age, 'year' => $year, 'from' => $from, 'to' => $to];
                        }
                    }
                }
            }
        }

        return ['compared' => $compared, 'changed' => $changed, 'maxAbsDelta' => $maxAbsDelta, 'samples' => $samples];
    }
}
