<?php

declare(strict_types=1);

namespace App\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Forecast\AllocationProfile;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;
use RetireForecast\FinanceEngine\StatePension\StatePensionUprating;

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
 * "Investment growth" is the allocation-blended REAL return, not a single field, so it does not
 * touch the AssumptionSet at all: it lands on its target by RE-WEIGHTING the asset mix
 * ({@see allocation()}), which is the only way to raise the expected return that also raises the
 * risk. It used to shift every asset class's mean and leave the volatilities and correlations
 * where they were, which sold an equity return at a cautious portfolio's spread inside a Monte
 * Carlo whose whole job is to price risk (board card 0062). Every other rate key maps to a
 * single set field. Values are plain percentages (e.g. "3.5" = 3.5%), matching how the builder
 * stores rates.
 */
final class AssumptionOverrides
{
    /** The override keys, in the same order the read-only assumptions panel lists them. */
    public const KEYS = ['investmentGrowth', 'inflation', 'houseGrowth', 'propertyVolatility', 'rentGrowth', 'salaryGrowth', 'incomeYield', 'careCostGrowth', 'investmentCharge'];

    /**
     * The overrides that are NOT percentages, so they cannot ride {@see KEYS} (which the panel,
     * the placeholders and the assistant all read as rates). Two choices today: how long the State
     * Pension triple lock is assumed to last (with the year it ends where the reader named one),
     * and how long the plan itself has to last ({@see PlanningHorizon}), plus the asset mix,
     * the mix it de-risks to and how long that takes ({@see AllocationProfile}, board card 0062).
     * Blank means the engine's own default, exactly as a blank rate does.
     */
    public const CHOICE_KEYS = ['statePensionUprating', 'statePensionUpratingUntilYear', 'planningHorizon', 'allocation', 'allocationGlideTo', 'allocationGlideYears'];

    /**
     * There is deliberately no default number of glidepath years. Every one of the standing
     * lifestyling conventions is a different length, none of them is the right answer for a
     * particular household, and a figure the engine picked for itself would change how much
     * money the plan has and be indistinguishable from one the reader chose. So the builder
     * REQUIRES the years alongside the target mix, and a glide target arriving without them
     * (which the form cannot produce) is read as no glidepath rather than as a length we made up.
     */
    private const NO_GLIDE = 0;

    /**
     * Derive the effective assumption set: the preset overlaid with the user's filled
     * overrides. With no (filled) overrides the result is the preset unchanged.
     *
     * @param  array<string, mixed>  $overrides  the sparse `assumptionOverrides` map
     */
    public static function apply(AssumptionSet $base, array $overrides): AssumptionSet
    {
        $set = $base;

        // NOTE: investmentGrowth is deliberately absent. It moves the asset MIX, not the asset
        // classes; see {@see allocation()} and the class docblock.
        if (self::filled($overrides, 'inflation')) {
            $set = $set->withInflationMean(self::percent($overrides['inflation']));
        }
        if (self::filled($overrides, 'houseGrowth')) {
            $set = $set->withHouseGrowth(self::percent($overrides['houseGrowth']));
        }
        if (self::filled($overrides, 'propertyVolatility')) {
            $set = $set->withSinglePropertyVolatility(self::percent($overrides['propertyVolatility']));
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
        if (self::filled($overrides, 'careCostGrowth')) {
            $set = $set->withCareCostRealGrowth(self::percent($overrides['careCostGrowth']));
        }
        if (self::filled($overrides, 'investmentCharge')) {
            $set = $set->withInvestmentCharge(self::percent($overrides['investmentCharge']));
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
            // The single-property figure, which is the index volatility widened when the reader has
            // not given one of their own. Read from the set, so it tracks a re-sourced index.
            'propertyVolatility' => self::format($set->singlePropertyVolatility()?->asPercent() ?? 0.0),
            'rentGrowth' => self::format($set->rentInflation->asPercent()),
            'salaryGrowth' => self::format($set->salaryGrowth->asPercent()),
            'incomeYield' => self::format($set->investmentIncomeYield->asPercent()),
            'careCostGrowth' => self::format($set->careCostRealGrowth()->asPercent()),
            'investmentCharge' => self::format($set->investmentCharge()->asPercent()),
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
        foreach ([...self::KEYS, ...self::CHOICE_KEYS] as $key) {
            if (self::filled($overrides, $key)) {
                $clean[$key] = (string) $overrides[$key];
            }
        }

        return $clean;
    }

    /**
     * How long the State Pension triple lock is assumed to last, and the year it ends. A blank or
     * unknown choice is the engine's own default (the full lock), which is what every scenario
     * stored before board card 0038 was run on, so an old scenario reproduces unchanged.
     *
     * The year is only meaningful for {@see StatePensionUprating::TripleLockUntil} and is passed
     * through as null otherwise, so a reader who picks an end year and then changes their mind
     * back to the full lock does not leave a stale year behind changing the answer.
     *
     * @param  array<string, mixed>  $overrides  the sparse `assumptionOverrides` map
     * @return array{0: StatePensionUprating, 1: ?int}
     */
    public static function statePensionUprating(array $overrides): array
    {
        $basis = self::filled($overrides, 'statePensionUprating')
            ? StatePensionUprating::tryFrom((string) $overrides['statePensionUprating'])
            : null;
        $basis ??= StatePensionUprating::TripleLock;

        $year = $basis === StatePensionUprating::TripleLockUntil && self::filled($overrides, 'statePensionUpratingUntilYear')
            ? (int) $overrides['statePensionUpratingUntilYear']
            : null;

        return [$basis, $year];
    }

    /**
     * How long the plan has to last: a named percentile of the last survivor's age at death.
     * A blank or unknown choice is the engine's own cautious default, so a scenario stored
     * before board card 0061 reads as the default rather than as the median it used to run on.
     *
     * @param  array<string, mixed>  $overrides  the sparse `assumptionOverrides` map
     */
    public static function planningHorizon(array $overrides): PlanningHorizon
    {
        return (self::filled($overrides, 'planningHorizon')
            ? PlanningHorizon::tryFrom((string) $overrides['planningHorizon'])
            : null) ?? PlanningHorizon::DEFAULT;
    }

    /**
     * The asset mix the forecast runs on: the reader's chosen profile, de-risked along their
     * chosen glidepath, and re-weighted where they asked for a particular blended return.
     * NULL when they have said none of those things, so {@see ForecastSettings::allocationIsAssumed}
     * keeps reporting the engine's own cautious mix as a figure they never chose.
     *
     * An investment-growth target no mix of these asset classes can reach is CLAMPED to the
     * nearest mix that exists, and said out loud by {@see unreachableGrowthTarget()}. The builder
     * refuses such a figure outright, so the only way to hold one is a scenario stored before this
     * card; clamping moves the risk in the direction the reader asked for rather than quietly
     * running a mix they did not pick, and the disclosure names both figures.
     *
     * @param  array<string, mixed>  $overrides  the sparse `assumptionOverrides` map
     */
    public static function allocation(array $overrides, AssumptionSet $set): ?PortfolioAllocation
    {
        $profile = self::filled($overrides, 'allocation')
            ? AllocationProfile::tryFrom((string) $overrides['allocation'])
            : null;
        $glideTo = self::filled($overrides, 'allocationGlideTo')
            ? AllocationProfile::tryFrom((string) $overrides['allocationGlideTo'])
            : null;
        $growth = self::filled($overrides, 'investmentGrowth') ? self::number($overrides['investmentGrowth']) / 100 : null;

        if ($profile === null && $glideTo === null && $growth === null) {
            return null;
        }

        $allocation = ($profile ?? AllocationProfile::DEFAULT)->allocation();

        if ($growth !== null) {
            [$min, $max] = PortfolioAllocation::reachableRealReturnRange($set, $allocation);
            $allocation = PortfolioAllocation::forBlendedRealReturn($set, min($max, max($min, $growth)), $allocation)
                ?? $allocation;
        }

        // glidingTo() with no years is the fixed mix, which is how a target arriving without its
        // length is read; see the note on {@see NO_GLIDE}.
        if ($glideTo !== null) {
            $allocation = $allocation->glidingTo($glideTo->allocation(), self::glideYears($overrides));
        }

        return $allocation;
    }

    /**
     * The blended real return the reader asked for where no mix of the set's asset classes can
     * produce it, with the range that can: the refusal the builder shows, and the disclosure a
     * scenario stored before board card 0062 carries. Null when there is nothing to refuse.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{target: float, min: float, max: float}|null
     */
    public static function unreachableGrowthTarget(array $overrides, AssumptionSet $set): ?array
    {
        if (! self::filled($overrides, 'investmentGrowth')) {
            return null;
        }

        $from = (self::filled($overrides, 'allocation')
            ? AllocationProfile::tryFrom((string) $overrides['allocation'])
            : null) ?? AllocationProfile::DEFAULT;

        $target = self::number($overrides['investmentGrowth']) / 100;
        [$min, $max] = PortfolioAllocation::reachableRealReturnRange($set, $from->allocation());

        if ($target >= $min - 1e-9 && $target <= $max + 1e-9) {
            return null;
        }

        return ['target' => $target, 'min' => $min, 'max' => $max];
    }

    /** How long the glidepath takes: the reader's own figure, or none at all ({@see NO_GLIDE}). */
    public static function glideYears(array $overrides): int
    {
        return self::filled($overrides, 'allocationGlideYears')
            ? max(0, (int) $overrides['allocationGlideYears'])
            : self::NO_GLIDE;
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
