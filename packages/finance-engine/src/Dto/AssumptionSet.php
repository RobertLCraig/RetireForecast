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
 * $salaryGrowthVolatility is the same idea for REAL salary growth (null = deterministic
 * at its mean, the pre-2026-07-18 behaviour). When set, the Monte Carlo draws a per-year
 * salary-growth shock, correlated to the equity shock by $salaryEquityCorrelation, so a
 * still-working household's future earnings (and the savings/contributions they fund)
 * carry earnings risk rather than escalating up a straight line. The correlation is kept
 * LOW (weaker than housing's): aggregate real wage growth is near-acyclical once workforce
 * composition nets out, so linking it too tightly to markets would overstate the co-movement.
 * A per-person Person::salaryGrowth override sets a trend, not a risk, so it bypasses the
 * shock (as the per-pot / per-property growth overrides bypass their sampled paths).
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
        public readonly bool $isDefault = false,
    ) {}

    /** The real (above-CPI) escalation of self-funder care fees (zero if none). */
    public function careCostRealGrowth(): Percent
    {
        return $this->careCostRealGrowth ?? Percent::zero();
    }

    /** The annual ongoing charge on invested balances (zero if none is modelled). */
    public function investmentCharge(): Percent
    {
        return $this->investmentCharge ?? Percent::zero();
    }

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

    public function withCareCostRealGrowth(Percent $value): self
    {
        return $this->copy(careCostRealGrowth: $value);
    }

    public function withInvestmentCharge(Percent $value): self
    {
        return $this->copy(investmentCharge: $value);
    }

    /**
     * Clone with selected fields replaced (null = keep current). The non-replaceable
     * fields (name, source, volatilities, correlations — including the house-price and
     * salary-growth volatilities and their equity correlations — isDefault) carry through
     * so a derived "custom" set keeps its provenance and risk structure.
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
        ?Percent $careCostRealGrowth = null,
        ?Percent $investmentCharge = null,
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
            $this->houseGrowthVolatility,
            $this->houseEquityCorrelation,
            $this->salaryGrowthVolatility,
            $this->salaryEquityCorrelation,
            $careCostRealGrowth ?? $this->careCostRealGrowth,
            $investmentCharge ?? $this->investmentCharge,
            $this->isDefault,
        );
    }
}
