<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;

/**
 * One-click what-ifs for the common questions a reader asks of a forecast ("what if I
 * retire later?", "what if I live longer?"). Each preset edits the base's people and
 * returns the resulting sparse override delta, so a quick what-if is an ordinary
 * delta-child — the same shape a hand-built one is, just generated.
 *
 * The delta is computed through {@see BuilderStateDelta::diff()} against the base's
 * effective form-state, so it is minimal (only changed leaves) and structurally identical
 * to the base (it only retunes existing people, never adds or removes a row). A preset that
 * would change nothing for this household returns null, so an empty what-if is never made.
 */
final class QuickWhatIf
{
    /** Preset key => the button label and the what-if's name. */
    public const PRESETS = [
        'retire_2_years_later' => 'Retire 2 years later',
        'live_10_years_longer' => 'Live 10 years longer',
    ];

    /**
     * The name + override delta for applying $preset to $base (a base plan), or null when
     * it would change nothing for this household.
     *
     * @return array{name: string, overrides: array<string, mixed>}|null
     */
    public static function build(Scenario $base, string $preset): ?array
    {
        if (! array_key_exists($preset, self::PRESETS)) {
            return null;
        }

        $baseState = $base->effectiveBuilderState();
        $people = is_array($baseState['people'] ?? null) ? $baseState['people'] : [];

        $edited = $baseState;
        $edited['people'] = match ($preset) {
            'retire_2_years_later' => self::retireLater($people, 2),
            'live_10_years_longer' => self::liveLonger($people, 10),
        };

        $overrides = BuilderStateDelta::diff($baseState, $edited);
        if ($overrides === []) {
            return null;
        }

        return ['name' => self::PRESETS[$preset], 'overrides' => $overrides];
    }

    /**
     * Push each still-working person's planned retirement age out by $years, clamped to the
     * builder's accepted 50–80. People without a retirement age (or already retired) are left
     * alone, so the what-if only moves what it can actually move.
     *
     * @param  list<array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private static function retireLater(array $people, int $years): array
    {
        return array_map(function (array $person) use ($years): array {
            $working = in_array((string) ($person['employmentStatus'] ?? ''), ['employed', 'self_employed'], true);
            $age = $person['plannedRetirementAge'] ?? '';
            if ($working && is_numeric($age)) {
                $person['plannedRetirementAge'] = (string) max(50, min(80, (int) $age + $years));
            }

            return $person;
        }, $people);
    }

    /**
     * Extend each person's modelled lifespan by $years through the offset-years longevity
     * lever, relative to whatever the base already models: lengthen an existing offset or
     * fixed age, or move "peer average" onto a +N-year offset (clamped to the lever's range).
     *
     * @param  list<array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private static function liveLonger(array $people, int $years): array
    {
        return array_map(function (array $person) use ($years): array {
            $mode = (string) ($person['longevityMode'] ?? 'peer');
            $value = $person['longevityValue'] ?? '';
            $current = is_numeric($value) ? (int) $value : 0;

            [$person['longevityMode'], $newValue] = match ($mode) {
                'offset_years' => ['offset_years', min(110, $current + $years)],
                'fixed_age' => ['fixed_age', min(110, $current + $years)],
                default => ['offset_years', $years],
            };
            $person['longevityValue'] = (string) $newValue;

            return $person;
        }, $people);
    }
}
