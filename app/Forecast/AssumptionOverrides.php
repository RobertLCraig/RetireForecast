<?php

declare(strict_types=1);

namespace App\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The user-editable economic assumptions: a sparse delta of percentage figures the
 * user has changed away from their chosen sourced preset (FCA / DMS / OBR). Stored in
 * the builder form-state under `assumptionOverrides`, applied here onto the preset DTO
 * to derive the engine {@see AssumptionSet} the forecast actually runs against.
 *
 * The preset stays the single source for any figure the user did NOT change: an empty
 * (absent) override key means "use the preset", so a later re-source of a preset figure
 * still flows through. This mirrors the delta-child what-if pattern (base ⊕ overrides)
 * for assumptions, and {@see ScenarioForecaster::assumptions()} is the one place it is
 * applied — so the deterministic forecast, the per-variant ladder, the Monte Carlo and
 * the frozen run snapshot all see the same customised set.
 *
 * "Investment growth" is the allocation-blended REAL return, not a single field, so it is
 * applied as a uniform shift across the asset classes that lands the blend on the target
 * ({@see AssumptionSet::withRealReturnShift}); the other five map to a single set field.
 * Values are plain percentages (e.g. "3.5" = 3.5%), matching how the builder stores rates.
 */
final class AssumptionOverrides
{
    /** The override keys, in the same order the read-only assumptions panel lists them. */
    public const KEYS = ['investmentGrowth', 'inflation', 'houseGrowth', 'rentGrowth', 'salaryGrowth', 'incomeYield'];

    /**
     * Derive the effective assumption set: the preset overlaid with the user's filled
     * overrides. With no (filled) overrides the result is the preset unchanged.
     *
     * @param  array<string, mixed>  $overrides  the sparse `assumptionOverrides` map
     */
    public static function apply(AssumptionSet $base, array $overrides, PortfolioAllocation $allocation): AssumptionSet
    {
        $set = $base;

        if (self::filled($overrides, 'investmentGrowth')) {
            $targetFraction = self::number($overrides['investmentGrowth']) / 100;
            $deltaBps = (int) round(($targetFraction - $allocation->blendedRealReturn($set)) * 10_000);
            $set = $set->withRealReturnShift(Percent::fromBasisPoints($deltaBps));
        }
        if (self::filled($overrides, 'inflation')) {
            $set = $set->withInflationMean(self::percent($overrides['inflation']));
        }
        if (self::filled($overrides, 'houseGrowth')) {
            $set = $set->withHouseGrowth(self::percent($overrides['houseGrowth']));
        }
        if (self::filled($overrides, 'rentGrowth')) {
            $set = $set->withRentInflation(self::percent($overrides['rentGrowth']));
        }
        if (self::filled($overrides, 'salaryGrowth')) {
            $set = $set->withSalaryGrowth(self::percent($overrides['salaryGrowth']));
        }
        if (self::filled($overrides, 'incomeYield')) {
            $set = $set->withInvestmentIncomeYield(self::percent($overrides['incomeYield']));
        }

        return $set;
    }

    /**
     * The preset's current figures as plain percentage strings, keyed by override key —
     * used to seed the editor's placeholders so the user sees the value they would be
     * overriding (and an untouched field falls back to it).
     *
     * @return array<string, string>
     */
    public static function presetFigures(AssumptionSet $set, PortfolioAllocation $allocation): array
    {
        return [
            'investmentGrowth' => self::format($allocation->blendedRealReturn($set) * 100),
            'inflation' => self::format($set->inflationMean->asPercent()),
            'houseGrowth' => self::format($set->houseGrowth->asPercent()),
            'rentGrowth' => self::format($set->rentInflation->asPercent()),
            'salaryGrowth' => self::format($set->salaryGrowth->asPercent()),
            'incomeYield' => self::format($set->investmentIncomeYield->asPercent()),
        ];
    }

    /**
     * The override keys the user has actually filled, so the panel can mark which figures
     * are theirs rather than the preset's.
     *
     * @param  array<string, mixed>  $overrides
     * @return list<string>
     */
    public static function changedKeys(array $overrides): array
    {
        return array_values(array_filter(self::KEYS, fn (string $key): bool => self::filled($overrides, $key)));
    }

    /** Keep only known, filled override keys — the sparse map persisted in builder_state. */
    public static function sparse(array $overrides): array
    {
        $clean = [];
        foreach (self::KEYS as $key) {
            if (self::filled($overrides, $key)) {
                $clean[$key] = (string) $overrides[$key];
            }
        }

        return $clean;
    }

    private static function filled(array $overrides, string $key): bool
    {
        return isset($overrides[$key]) && $overrides[$key] !== '' && $overrides[$key] !== null;
    }

    private static function number(mixed $value): float
    {
        return (float) $value;
    }

    private static function percent(mixed $value): Percent
    {
        return Percent::fromPercent(self::number($value));
    }

    /** A percentage as a trimmed string: 3.52 -> "3.52", 2.0 -> "2", 1.50 -> "1.5". */
    private static function format(float $percent): string
    {
        return rtrim(rtrim(number_format(round($percent, 2), 2, '.', ''), '0'), '.');
    }
}
