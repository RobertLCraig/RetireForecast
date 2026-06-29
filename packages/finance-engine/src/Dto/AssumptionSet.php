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

    /**
     * A copy with every asset class's expected real return shifted by $delta (basis
     * points may be negative). Because the blended return is an allocation-weighted sum
     * over the asset classes and the weights sum to 1, a uniform shift of $delta moves
     * the blended return by exactly $delta too — so a user editing "investment growth"
     * to a target moves the deterministic blend and the per-class Monte Carlo draws by
     * the same amount, with no divergence. Volatility and correlations are untouched (the
     * user edits the expected return, not the risk).
     */
    public function withRealReturnShift(Percent $delta): self
    {
        $shifted = array_map(
            fn (AssetClassAssumption $a): AssetClassAssumption => new AssetClassAssumption(
                $a->name,
                Percent::fromBasisPoints($a->expectedRealReturn->basisPoints + $delta->basisPoints),
                $a->volatility,
            ),
            $this->assetClasses,
        );

        return $this->copy(assetClasses: $shifted);
    }

    public function withInflationMean(Percent $value): self
    {
        return $this->copy(inflationMean: $value);
    }

    public function withHouseGrowth(Percent $value): self
    {
        return $this->copy(houseGrowth: $value);
    }

    public function withRentInflation(Percent $value): self
    {
        return $this->copy(rentInflation: $value);
    }

    public function withSalaryGrowth(Percent $value): self
    {
        return $this->copy(salaryGrowth: $value);
    }

    public function withInvestmentIncomeYield(Percent $value): self
    {
        return $this->copy(investmentIncomeYield: $value);
    }

    /**
     * Clone with selected fields replaced (null = keep current). The non-replaceable
     * fields (name, source, volatilities, correlations, isDefault) carry through so a
     * derived "custom" set keeps its provenance and risk structure.
     *
     * @param  list<AssetClassAssumption>|null  $assetClasses
     */
    private function copy(
        ?array $assetClasses = null,
        ?Percent $inflationMean = null,
        ?Percent $houseGrowth = null,
        ?Percent $rentInflation = null,
        ?Percent $salaryGrowth = null,
        ?Percent $investmentIncomeYield = null,
    ): self {
        return new self(
            $this->name,
            $this->sourceNote,
            $assetClasses ?? $this->assetClasses,
            $this->correlationMatrix,
            $inflationMean ?? $this->inflationMean,
            $this->inflationVolatility,
            $houseGrowth ?? $this->houseGrowth,
            $rentInflation ?? $this->rentInflation,
            $salaryGrowth ?? $this->salaryGrowth,
            $investmentIncomeYield ?? $this->investmentIncomeYield,
            $this->isDefault,
        );
    }
}
