<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;

/**
 * One-click "compare buy vs rent": generates the alternative housing strategies for a base
 * plan as ordinary delta-child what-ifs, so stay-put / buy-cheaper / sell-and-rent can be
 * read side by side in Compare as deliberate, nameable, independently-editable plans rather
 * than three variants baked into every report.
 *
 * Each generated child overrides only the top-level `variant` (computed through
 * {@see BuilderStateDelta::diff()} against the base's effective state, so it is the minimal,
 * structurally-identical delta a hand-built what-if would be). Only the strategies whose
 * inputs are actually present are offered — buy needs a buy price, rent needs an annual rent;
 * stay-put always applies — and the base's own strategy is skipped (it is already a column).
 */
final class BuyVsRentCompare
{
    /** Each housing strategy and the name its generated what-if takes. */
    public const STRATEGY_NAMES = [
        'stay_put' => 'Stay put',
        'buy_outright' => 'Buy somewhere cheaper',
        'rent' => 'Sell & rent',
    ];

    /**
     * The variant what-if children to generate for $base: one per meaningful alternative
     * strategy, excluding the base's own. Each entry is the variant, the name, and the sparse
     * override delta (variant only).
     *
     * @return list<array{variant: string, name: string, overrides: array<string, mixed>}>
     */
    public static function children(Scenario $base): array
    {
        $state = $base->effectiveBuilderState();
        $housing = is_array($state['housing'] ?? null) ? $state['housing'] : [];
        $baseVariant = (string) ($state['variant'] ?? '');

        $meaningful = [
            'stay_put' => true,
            'buy_outright' => self::positive($housing['buyPrice'] ?? null),
            'rent' => self::positive($housing['annualRent'] ?? null),
        ];

        $children = [];
        foreach (self::STRATEGY_NAMES as $variant => $name) {
            if ($variant === $baseVariant || ! $meaningful[$variant]) {
                continue;
            }

            $overrides = BuilderStateDelta::diff($state, ['variant' => $variant] + $state);
            if ($overrides !== []) {
                $children[] = ['variant' => $variant, 'name' => $name, 'overrides' => $overrides];
            }
        }

        return $children;
    }

    private static function positive(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }
}
