<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A named, sourced set of economic assumptions the forecast runs against. This is
 * the "display choice" layer: several sets ship (FCA-derived default, DMS/EGS, OBR/
 * BoE inflation) and the user can compare them, each labelled with its source.
 *
 * The engine reads whichever set it is handed and never hard-codes a number; a
 * simulation snapshots the set it used so results stay reproducible. $assetClasses
 * and $correlationMatrix must be in the same order (the matrix is square,
 * symmetric, with 1.0 on the diagonal).
 *
 * $investmentIncomeYield is the NOMINAL annual income yield (dividends + interest) of
 * a General Investment Account portfolio. The forecast splits a GIA's total return
 * into this taxable income (taxed each year as dividends) and the remaining capital
 * growth (taxed as CGT only on disposal), so an unwrapped holding carries its real tax
 * drag. The ~2% is a modelling assumption (not a statutory figure), anchored to the
 * global-equity dividend yield (FTSE All-World ~1.3-2%); reviewed 2026-06-27 and kept.
 */
final class AssumptionSet
{
    /**
     * @param  list<AssetClassAssumption>  $assetClasses
     * @param  list<list<float>>  $correlationMatrix  same order as $assetClasses
     */
    public function __construct(
        public readonly string $name,
        public readonly string $sourceNote,
        public readonly array $assetClasses,
        public readonly array $correlationMatrix,
        public readonly Percent $inflationMean,
        public readonly Percent $inflationVolatility,
        public readonly Percent $houseGrowth,
        public readonly Percent $rentInflation,
        public readonly Percent $salaryGrowth,
        public readonly Percent $investmentIncomeYield,
        public readonly bool $isDefault = false,
    ) {}
}
