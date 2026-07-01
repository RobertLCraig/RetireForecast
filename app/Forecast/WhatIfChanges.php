<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\AssumptionSet;
use App\Models\Scenario;
use Illuminate\Support\Str;

/**
 * Turns a delta-child what-if's sparse `overrides` map into a human-readable list of what
 * it changed from its base: for each overridden input, a label, the base value it replaced
 * and the new value. This is what lets a what-if (and the dashboard) *highlight* its
 * difference from the base rather than reading like an independent plan.
 *
 * Override paths are the dot-paths {@see BuilderStateDelta} writes (e.g.
 * `people.p1.grossSalary`, `expenseLines.ess1.amount`, `housing.annualRent`,
 * `assumptionOverrides.inflation`); the base value is read back from the base's effective
 * form-state through {@see BuilderStateDelta::valueAt()}. Meta fields (the what-if's name,
 * the wizard step) are not substantive input changes, so they are excluded.
 */
final class WhatIfChanges
{
    /** Override paths that are not substantive input changes, so the summary skips them. */
    private const IGNORED = ['name', 'step'];

    /** Leaf field names whose value is money (pounds) — shown as £. */
    private const MONEY = [
        'grossSalary', 'currentValue', 'ongoingContribution', 'employerContribution', 'pclsTakenToDate',
        'accruedAnnualPension', 'commutationLumpSum', 'weeklyForecast', 'balance', 'unrealisedGain',
        'grossAnnual', 'amount', 'salePrice', 'buyPrice', 'annualRent', 'movingCosts',
        'outstandingMortgage', 'runningCosts',
    ];

    /** Leaf field names whose value is a rate — shown with a % suffix. */
    private const RATE = [
        'salaryGrowth', 'growthAssumptionOverride', 'yield', 'sellingCostRate', 'rentInflationReal',
        'spousePensionFraction', 'commutationFactor', 'survivorFactor', 'ownershipShare',
        'investmentGrowth', 'inflation', 'houseGrowth', 'rentGrowth', 'incomeYield',
    ];

    /** Enum-ish leaf values mapped to readable labels; anything else falls back to a headline. */
    private const ENUM_LABELS = [
        'buy_outright' => 'Sell & buy cheaper', 'rent' => 'Sell & rent', 'stay_put' => 'Stay put',
        'england_wales_ni' => 'England, Wales & NI', 'scotland' => 'Scotland',
        'employed' => 'Employed', 'self_employed' => 'Self-employed', 'retired' => 'Retired', 'not_working' => 'Not working',
        'outright' => 'Owned outright', 'mortgaged' => 'Mortgaged',
        'isa' => 'ISA', 'gia' => 'GIA', 'cash' => 'Cash', 'premium_bonds' => 'Premium Bonds',
        'rental' => 'Rental', 'annuity' => 'Annuity', 'disability_benefit' => 'Disability benefit', 'other' => 'Other',
        'essential' => 'Essential', 'discretionary' => 'Discretionary', 'self_investment' => 'Self-investment',
    ];

    /**
     * The substantive changes this what-if makes to its base, newest base read live so a
     * base edit flows through. Empty for a base (no parent) or a what-if that changed nothing.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    public static function of(Scenario $child): array
    {
        if (! $child->isChild()) {
            return [];
        }

        return self::compute($child->parent->effectiveBuilderState(), $child->overrides ?? []);
    }

    /**
     * @param  array<string, mixed>  $baseState  the base's effective form-state
     * @param  array<string, mixed>  $overrides  the child's sparse dot-path => value map
     * @return list<array{label: string, from: string, to: string}>
     */
    public static function compute(array $baseState, array $overrides): array
    {
        $changes = [];
        foreach ($overrides as $path => $value) {
            $path = (string) $path;
            if (in_array($path, self::IGNORED, true)) {
                continue;
            }

            // A whole-row ADD (the value is the row map) or a REMOVE (the sentinel): present as a
            // single "… added" / "… removed" line, not a noisy per-leaf diff.
            if ($value === BuilderStateDelta::REMOVED) {
                $changes[] = self::rowChange($path, $baseState, removed: true);

                continue;
            }
            if (is_array($value)) {
                $changes[] = self::rowChange($path, $baseState, removed: false, addedRow: $value);

                continue;
            }

            $leaf = self::leaf($path);
            $changes[] = [
                'label' => self::label($path, $baseState),
                'from' => self::formatValue($leaf, BuilderStateDelta::valueAt($baseState, $path)),
                'to' => self::formatValue($leaf, $value),
            ];
        }

        return $changes;
    }

    /** The display type for each list row, used to name an added/removed row. */
    private const ROW_TYPE = [
        'people' => 'Person', 'pensions' => 'Pension', 'accounts' => 'Account',
        'incomeStreams' => 'Income', 'oneOffCosts' => 'One-off cost', 'expenseLines' => 'Spending line',
        'withdrawals' => 'Pension withdrawal',
    ];

    /**
     * A change line for a whole row the what-if added to, or removed from, the base. The row
     * id is the last path segment and its collection the one before it (so a nested pension
     * withdrawal reads as a withdrawal, not a pension).
     *
     * @param  array<string, mixed>  $baseState
     * @param  array<string, mixed>  $addedRow
     * @return array{label: string, from: string, to: string}
     */
    private static function rowChange(string $path, array $baseState, bool $removed, array $addedRow = []): array
    {
        $segments = explode('.', $path);
        $rowId = (string) array_pop($segments);
        $collection = (string) (end($segments) ?: '');
        $type = self::ROW_TYPE[$collection] ?? Str::headline(Str::singular($collection));

        if ($removed) {
            $baseRow = BuilderStateDelta::valueAt($baseState, $path);

            return ['label' => "{$type} removed", 'from' => self::rowLabel($collection, is_array($baseRow) ? $baseRow : null, $rowId), 'to' => '—'];
        }

        return ['label' => "{$type} added", 'from' => '—', 'to' => self::rowLabel($collection, $addedRow, $rowId)];
    }

    /** The last path segment, the field whose type drives formatting. */
    private static function leaf(string $path): string
    {
        $segments = explode('.', $path);

        return (string) end($segments);
    }

    /**
     * A readable label for an override path: a top-level field, an assumption/housing/
     * property figure, or a list row addressed by id ("<row name> · <field>").
     *
     * @param  array<string, mixed>  $baseState
     */
    private static function label(string $path, array $baseState): string
    {
        $segments = explode('.', $path);
        $head = $segments[0];

        $topLevel = [
            'variant' => 'Primary option', 'region' => 'Tax region', 'baseTaxYear' => 'Base tax year',
            'ihtModelled' => 'Model inheritance tax', 'householdName' => 'Household name',
            'assumptionSetId' => 'Assumption set', 'hasProperty' => 'Owns a property',
        ];
        if (count($segments) === 1) {
            return $topLevel[$head] ?? Str::headline($head);
        }

        // Selling-cost components live at housing.sellingCosts.<key>.<value|basis>; name the
        // line by its own label read from the base, so an override reads "Selling cost — Estate agent".
        if ($head === 'housing' && ($segments[1] ?? '') === 'sellingCosts') {
            $key = $segments[2] ?? '';
            $componentLabel = (string) ($baseState['housing']['sellingCosts'][$key]['label'] ?? Str::headline($key));

            return 'Selling cost — '.$componentLabel.(self::leaf($path) === 'basis' ? ' (basis)' : '');
        }

        $sectionLabels = [
            'assumptionOverrides' => [
                'investmentGrowth' => 'Investment growth', 'inflation' => 'Inflation (CPI)',
                'houseGrowth' => 'House price growth', 'rentGrowth' => 'Rent growth',
                'salaryGrowth' => 'Salary growth', 'incomeYield' => 'Investment income yield',
            ],
            'housing' => [
                'salePrice' => 'Sale price', 'buyPrice' => 'Cheaper home to buy', 'annualRent' => 'Rent if you sell & rent',
                'rentInflationReal' => 'Rent growth', 'movingCosts' => 'Moving costs', 'sellingCostRate' => 'Selling cost rate',
            ],
            'expense' => ['survivorFactor' => 'Survivor spending factor'],
            'property' => [
                'currentValue' => 'Home value', 'ownership' => 'Home ownership', 'outstandingMortgage' => 'Mortgage owed',
                'runningCosts' => 'Home running costs', 'growthAssumptionOverride' => 'Home growth', 'ownershipShare' => 'Ownership share',
            ],
        ];
        if (isset($sectionLabels[$head])) {
            $field = $segments[1] ?? '';
            $prefix = $head === 'property' ? 'Home: ' : '';

            return $prefix.($sectionLabels[$head][$field] ?? Str::headline($field));
        }

        // A list row: "<row name> · <field>" (or a deeper field like a pension withdrawal).
        $rowName = self::rowName($baseState, $head, $segments[1] ?? '');
        $field = self::leaf($path);

        return $rowName.' · '.Str::lower(Str::headline($field));
    }

    /** A display name for a list row, read from the base state by its stable id. */
    private static function rowName(array $baseState, string $collection, string $rowId): string
    {
        $row = null;
        foreach ($baseState[$collection] ?? [] as $candidate) {
            if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $rowId) {
                $row = $candidate;
                break;
            }
        }

        return self::rowLabel($collection, is_array($row) ? $row : null, $rowId);
    }

    /**
     * A display name for a list row from the row map itself (so an added row, which the base
     * does not hold, can still be named), falling back to the row type when the row or its
     * identifying field is absent.
     *
     * @param  array<string, mixed>|null  $row
     */
    private static function rowLabel(string $collection, ?array $row, string $rowId): string
    {
        $fallback = self::ROW_TYPE[$collection] ?? Str::headline(Str::singular($collection));
        if ($row === null) {
            return $fallback;
        }

        return match ($collection) {
            'people' => (string) ($row['name'] ?? '') ?: Str::upper($rowId),
            'expenseLines', 'oneOffCosts' => (string) ($row['label'] ?? '') ?: $fallback,
            'pensions' => match ((string) ($row['subtype'] ?? '')) {
                'state' => 'State Pension', 'dc' => 'DC pension', 'db' => 'DB pension', default => $fallback,
            },
            'accounts' => (self::ENUM_LABELS[(string) ($row['type'] ?? '')] ?? $fallback).' account',
            'incomeStreams' => (self::ENUM_LABELS[(string) ($row['type'] ?? '')] ?? $fallback).' income',
            default => $fallback,
        };
    }

    /**
     * Format a form-state value for display by its leaf field name: money as £, rates with %,
     * enums/bools readable, '—' when absent. Shared so the results-page changes and the
     * builder's "base value" hint format the same value the same way.
     */
    public static function formatValue(string $leaf, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if ($leaf === 'basis') {
            return $value === 'fixed' ? 'flat fee' : 'percentage of sale';
        }
        if ($leaf === 'condition') {
            return match ($value) {
                'while_owning_home' => 'only while owning the home',
                'while_working' => 'only while working',
                'always' => 'always',
                default => (string) $value,
            };
        }

        if ($leaf === 'assumptionSetId') {
            return AssumptionSet::find($value)?->name ?? 'set #'.$value;
        }
        if (in_array($leaf, self::MONEY, true) && is_numeric($value)) {
            return '£'.number_format((float) $value);
        }
        if (in_array($leaf, self::RATE, true) && is_numeric($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.').'%';
        }

        $string = (string) $value;

        return self::ENUM_LABELS[$string] ?? $string;
    }
}
