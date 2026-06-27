<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;

/**
 * The per-path economic and mortality draws the projector consumes, abstracted so
 * the same projector serves both the deterministic forecast (expected values, a
 * single representative lifespan) and the Monte Carlo (sampled return sequences and
 * sampled death ages). All returns are REAL (above-inflation) fractions, e.g. 0.044.
 */
interface PathDraws
{
    /** Blended real return on invested pots (DC, ISA, GIA) for the given year index. */
    public function investmentRealReturn(int $yearIndex): float;

    /** Real return on cash holdings for the given year index. */
    public function cashRealReturn(int $yearIndex): float;

    /**
     * The NOMINAL annual income yield (dividends/interest) of a GIA portfolio — the
     * fraction of a GIA's value paid out as taxable income each year. Constant across
     * years; the remaining return is capital growth (taxed as CGT on disposal). See
     * {@see AssumptionSet::$investmentIncomeYield}.
     */
    public function investmentIncomeYield(): float;

    /** Inflation rate (fraction) for the given year index. */
    public function inflation(int $yearIndex): float;

    /** Real house-price growth for the given year index. */
    public function houseGrowthReal(int $yearIndex): float;

    /** Real salary growth for the given year index. */
    public function salaryGrowthReal(int $yearIndex): float;

    /** The age at which the given person dies on this path. */
    public function deathAge(string $personId): int;
}
