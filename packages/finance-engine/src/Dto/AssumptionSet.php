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
 * symmetric, with 1.0 on the diagonal); the FIRST asset class is global equities
 * (index 0), which the house-price factor correlates to (see below).
 *
 * $houseGrowthVolatility is the annual standard deviation of REAL house-price growth
 * (null = house growth is deterministic at its mean, the v1 behaviour). When set, the
 * Monte Carlo draws a per-year house-price shock, correlated to the equity shock by
 * $houseEquityCorrelation. A single house-equity correlation (rather than a full extra
 * matrix row) keeps the asset-class matrix contract intact and captures the one
 * economically load-bearing linkage: UK house and equity real returns co-move only
 * weakly, so selling a home and investing the proceeds genuinely diversifies
 * concentrated housing risk. Sourced defaults + judgement in docs/ASSUMPTIONS.md.
 *
 * $singlePropertyVolatility is the annual standard deviation of REAL growth of ONE home, as
 * against $houseGrowthVolatility, which is an INDEX figure. An index has already diversified
 * the property-specific half of the risk away: it averages a whole market, so what is left in
 * it is the market-wide move. A household whose net worth is one flat is not exposed to index
 * risk: their flat can be re-rated by its block, its lease, its street or its condition while
 * the index does nothing. Null (the default) DERIVES it as the index figure times
 * {@see SINGLE_PROPERTY_VOLATILITY_MULTIPLE}, which is what {@see singlePropertyVolatility}
 * returns and what {@see singlePropertyVolatilityIsAssumed} reports as an engine-supplied
 * default; an explicit figure is the reader's own and wins outright. It scales the sampled
 * index shock at the point the home consumes it, so the sampled index path, and therefore the
 * RNG stream, is untouched.
 *
 * $salaryGrowthVolatility is the same idea for REAL salary growth (null = deterministic
 * at its mean, the pre-2026-07-18 behaviour). When set, the Monte Carlo draws a per-year
 * salary-growth shock, correlated to the equity shock by $salaryEquityCorrelation, so a
 * still-working household's future earnings (and the savings/contributions they fund)
 * carry earnings risk rather than escalating up a straight line. The correlation is kept
 * LOW (weaker than housing's): aggregate real wage growth is near-acyclical once workforce
 * composition nets out, so linking it too tightly to markets would overstate the co-movement.
 * A per-person Person::salaryGrowth override sets a trend, not a risk, so it bypasses the
 * shock, unlike a per-PROPERTY growth override, which since card 0029 re-centres the sampled
 * house path rather than replacing it (an overridden home is the LEAST certain one there is).
 *
 * $investmentIncomeYield is the NOMINAL annual income yield (dividends + interest) of
 * a General Investment Account portfolio. The forecast splits a GIA's total return
 * into this taxable income (taxed each year as dividends) and the remaining capital
 * growth (taxed as CGT only on disposal), so an unwrapped holding carries its real tax
 * drag. The ~2% is a modelling assumption (not a statutory figure), anchored to the
 * global-equity dividend yield (FTSE All-World ~1.3-2%); reviewed 2026-06-27 and kept.
 *
 * $investmentCharge is the annual ongoing charge borne by INVESTED balances — the platform/
 * administration fee plus the funds' ongoing charges (OCF) — deducted from the pot each year
 * after growth (null = no charge, the pre-2026-07-31 behaviour, kept so an old stored run
 * reproduces byte-identically). Asset-class returns are quoted GROSS of charges (the FCA COBS
 * 13 projection basis expects charges to be deducted separately), so without this the household
 * was modelled as holding its portfolio for free: the most reliably predictable drag in the
 * whole model, compounding against them every year in the reassuring direction. Cash deposits
 * carry no charge (a bank account has no platform or fund fee), so it applies to DC pots, ISAs
 * and GIAs only. Sourced default + judgement in docs/spec/ASSUMPTIONS.md.
 *
 * $careCostRealGrowth is the REAL (above-CPI) annual escalation of self-funder care
 * fees (null = flat-real, the pre-2026-07-18 behaviour, kept so an old stored run
 * reproduces byte-identically). The engine draws one CPI series and models every other
 * cost as a real spread over it; care is the fastest-inflating major category in UK
 * retirement (largely National-Living-Wage-pinned staff cost, ratcheted above prices),
 * so leaving it flat-real understated the tool's headline late-life risk. When set, the
 * projector compounds the sampled care fee at CPI + this rate to the year the spell
 * falls, mirroring {@see ExpenseProfile::propertyCostsRealGrowth}. Sourced default +
 * judgement (CPI + 2%) in docs/ASSUMPTIONS.md.
 */
final class AssumptionSet
{
    /**
     * How much wider one property's real-growth spread is than the index's, when the reader
     * gives no figure of their own ({@see $singlePropertyVolatility}). Roughly DOUBLE: the
     * index has diversified away the property-specific component, which for a single home is
     * of the same order as the market-wide one, and variances add. Applies to the primary
     * residence, so it widens the fan on every plan that keeps or buys a home.
     *
     * SOURCE: the property reviewer's figure in the five-discipline expert review of
     * 2026-08-19 (docs/REVIEW-PANEL-2026-08-19.local.md, gitignored). It is a reviewer's
     * judgement rather than a published series. See docs/spec/ASSUMPTIONS.md §13, which
     * flags the sourcing gap and the card raised to close it. User-editable per scenario.
     */
    public const SINGLE_PROPERTY_VOLATILITY_MULTIPLE = 2.0;

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
        public readonly ?Percent $houseGrowthVolatility = null,
        public readonly float $houseEquityCorrelation = 0.2,
        public readonly ?Percent $salaryGrowthVolatility = null,
        public readonly float $salaryEquityCorrelation = 0.1,
        public readonly ?Percent $careCostRealGrowth = null,
        public readonly ?Percent $investmentCharge = null,
        public readonly ?Percent $singlePropertyVolatility = null,
        public readonly bool $isDefault = false,
    ) {}

    /** The real (above-CPI) escalation of self-funder care fees (zero if none). */
    public function careCostRealGrowth(): Percent
    {
        return $this->careCostRealGrowth ?? Percent::zero();
    }

    /**
     * The annual real-growth volatility of ONE home: the reader's own figure where they gave
     * one, else the index figure widened by {@see SINGLE_PROPERTY_VOLATILITY_MULTIPLE}. Null
     * when the set models house growth deterministically: there is no index spread to widen.
     */
    public function singlePropertyVolatility(): ?Percent
    {
        if ($this->singlePropertyVolatility !== null) {
            return $this->singlePropertyVolatility;
        }
        if ($this->houseGrowthVolatility === null || $this->houseGrowthVolatility->basisPoints <= 0) {
            return null;
        }

        return Percent::fromBasisPoints(
            (int) round($this->houseGrowthVolatility->basisPoints * self::SINGLE_PROPERTY_VOLATILITY_MULTIPLE),
        );
    }

    /**
     * Is the single-property volatility in play a figure the ENGINE supplied? True only when it
     * is actually applied and the reader gave none, which is the condition the no-invisible-figures
     * disclosure is gated on.
     */
    public function singlePropertyVolatilityIsAssumed(): bool
    {
        return $this->singlePropertyVolatility === null && $this->singlePropertyVolatility() !== null;
    }

    /**
     * How far the sampled INDEX shock is scaled when it reaches a single home: the effective
     * single-property volatility over the index volatility. 1.0 when there is no index spread
     * to scale, so a deterministic-house set is untouched.
     */
    public function singlePropertyVolatilityMultiple(): float
    {
        $property = $this->singlePropertyVolatility();
        if ($property === null || $this->houseGrowthVolatility === null || $this->houseGrowthVolatility->basisPoints <= 0) {
            return 1.0;
        }

        return $property->basisPoints / $this->houseGrowthVolatility->basisPoints;
    }

    /** The annual ongoing charge on invested balances (zero if none is modelled). */
    public function investmentCharge(): Percent
    {
        return $this->investmentCharge ?? Percent::zero();
    }

    /**
     * A copy running on different asset classes.
     *
     * There is deliberately no `withRealReturnShift` beside it any more (board card 0062). A user
     * who edits "investment growth" moves the asset MIX, not the asset classes: shifting every
     * class's mean and leaving the volatilities and correlations alone raised the return without
     * raising the risk, which is a free lunch inside a Monte Carlo built to price risk. The mix
     * that lands on a target return is solved by `PortfolioAllocation::forBlendedRealReturn`.
     *
     * So nothing user-facing reaches this. It exists for a caller that has to state a whole
     * different set of asset figures, which today is a test pinning behaviour with the market
     * taken out of it.
     *
     * @param  list<AssetClassAssumption>  $assetClasses
     */
    public function withAssetClasses(array $assetClasses): self
    {
        return new self(
            $this->name,
            $this->sourceNote,
            $assetClasses,
            $this->correlationMatrix,
            $this->inflationMean,
            $this->inflationVolatility,
            $this->houseGrowth,
            $this->rentInflation,
            $this->salaryGrowth,
            $this->investmentIncomeYield,
            $this->houseGrowthVolatility,
            $this->houseEquityCorrelation,
            $this->salaryGrowthVolatility,
            $this->salaryEquityCorrelation,
            $this->careCostRealGrowth,
            $this->investmentCharge,
            $this->singlePropertyVolatility,
            $this->isDefault,
        );
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

    public function withCareCostRealGrowth(Percent $value): self
    {
        return $this->copy(careCostRealGrowth: $value);
    }

    public function withInvestmentCharge(Percent $value): self
    {
        return $this->copy(investmentCharge: $value);
    }

    public function withSinglePropertyVolatility(Percent $value): self
    {
        return $this->copy(singlePropertyVolatility: $value);
    }

    /**
     * Clone with selected fields replaced (null = keep current). The non-replaceable
     * fields (name, source, the asset classes and their sourcing, volatilities, correlations,
     * including the house-price and salary-growth volatilities and their equity correlations,
     * and isDefault) carry through so a derived "custom" set keeps its provenance and its
     * risk structure.
     */
    private function copy(
        ?Percent $inflationMean = null,
        ?Percent $houseGrowth = null,
        ?Percent $rentInflation = null,
        ?Percent $salaryGrowth = null,
        ?Percent $investmentIncomeYield = null,
        ?Percent $careCostRealGrowth = null,
        ?Percent $investmentCharge = null,
        ?Percent $singlePropertyVolatility = null,
    ): self {
        return new self(
            $this->name,
            $this->sourceNote,
            $this->assetClasses,
            $this->correlationMatrix,
            $inflationMean ?? $this->inflationMean,
            $this->inflationVolatility,
            $houseGrowth ?? $this->houseGrowth,
            $rentInflation ?? $this->rentInflation,
            $salaryGrowth ?? $this->salaryGrowth,
            $investmentIncomeYield ?? $this->investmentIncomeYield,
            $this->houseGrowthVolatility,
            $this->houseEquityCorrelation,
            $this->salaryGrowthVolatility,
            $this->salaryEquityCorrelation,
            $careCostRealGrowth ?? $this->careCostRealGrowth,
            $investmentCharge ?? $this->investmentCharge,
            $singlePropertyVolatility ?? $this->singlePropertyVolatility,
            $this->isDefault,
        );
    }
}
