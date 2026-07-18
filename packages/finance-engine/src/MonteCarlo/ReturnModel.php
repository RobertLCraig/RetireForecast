<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use Random\Randomizer;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Forecast\PortfolioAllocation;

/**
 * Generates one path of correlated annual REAL returns and inflation from an
 * AssumptionSet, for the Monte Carlo.
 *
 * Each year, independent standard-normal draws are correlated via the Cholesky
 * factor of the asset correlation matrix, scaled by each asset's volatility and
 * centred on its expected real return. The allocation blends the per-asset returns
 * into the invested-pot return; the cash asset drives the cash return. Inflation is
 * drawn independently around its mean.
 *
 * House-price growth is stochastic when the set carries a house volatility: a per-year
 * house shock is drawn, correlated to the equity shock (asset index 0) by the set's
 * house-equity correlation, so a home's value co-varies weakly with markets rather than
 * marching up a straight line. With no house volatility (null) it stays deterministic at
 * its mean — the v1 behaviour — and no house draw is consumed, so those runs are
 * byte-identical to before. Salary growth remains deterministic (a later refinement).
 *
 * Returns are lognormal in effect because the projector compounds them
 * multiplicatively; draws are on the return itself (a normal shock), which is a
 * standard, transparent choice for an annual-step retirement model.
 */
final class ReturnModel
{
    /** @var list<list<float>> */
    private readonly array $cholesky;

    /** @var list<float> */
    private readonly array $means;

    /** @var list<float> */
    private readonly array $vols;

    private readonly int $cashIndex;

    private readonly float $houseMean;

    private readonly float $houseVol;

    private readonly float $houseEquityCorrelation;

    public function __construct(
        private readonly AssumptionSet $set,
        private readonly PortfolioAllocation $allocation,
    ) {
        $this->cholesky = Cholesky::decompose($set->correlationMatrix);

        $means = [];
        $vols = [];
        foreach ($set->assetClasses as $assetClass) {
            $means[] = $assetClass->expectedRealReturn->asFraction();
            $vols[] = $assetClass->volatility->asFraction();
        }
        $this->means = $means;
        $this->vols = $vols;
        $this->cashIndex = count($set->assetClasses) - 1;

        // House-price growth: its mean always, its volatility only when the set carries one
        // (else deterministic). The correlation is clamped to [-1, 1] so the independent
        // component's variance (1 - rho^2) can never go negative.
        $this->houseMean = $set->houseGrowth->asFraction();
        $this->houseVol = $set->houseGrowthVolatility?->asFraction() ?? 0.0;
        $this->houseEquityCorrelation = max(-1.0, min(1.0, $set->houseEquityCorrelation));
    }

    /**
     * Generate $years of returns for one path.
     *
     * @return array{investment: list<float>, cash: list<float>, inflation: list<float>, house: list<float>}
     */
    public function generatePath(int $years, Randomizer $rng): array
    {
        $investment = [];
        $cash = [];
        $inflation = [];
        $house = [];

        $weights = $this->allocation->weights;
        $inflMean = $this->set->inflationMean->asFraction();
        $inflVol = $this->set->inflationVolatility->asFraction();
        $houseIndependentScale = sqrt(max(0.0, 1.0 - $this->houseEquityCorrelation ** 2));

        for ($y = 0; $y < $years; $y++) {
            $u = [];
            foreach ($this->means as $i => $unused) {
                $u[$i] = $this->standardNormal($rng);
            }
            $z = Cholesky::apply($this->cholesky, $u);

            $blended = 0.0;
            foreach ($this->means as $i => $mean) {
                $assetReal = $mean + $this->vols[$i] * $z[$i];
                $blended += ($weights[$i] ?? 0.0) * $assetReal;
            }

            $investment[] = $blended;
            $cash[] = $this->means[$this->cashIndex] + $this->vols[$this->cashIndex] * $z[$this->cashIndex];
            $inflation[] = $inflMean + $inflVol * $this->standardNormal($rng);

            // House-price shock, correlated to the equity shock (z[0]) by rho: a fresh normal
            // supplies the idiosyncratic part. Only drawn when the set has a house volatility,
            // so deterministic-house sets never touch the RNG stream (byte-identical to v1).
            if ($this->houseVol > 0.0) {
                $houseZ = $this->houseEquityCorrelation * $z[0] + $houseIndependentScale * $this->standardNormal($rng);
                $house[] = $this->houseMean + $this->houseVol * $houseZ;
            } else {
                $house[] = $this->houseMean;
            }
        }

        return ['investment' => $investment, 'cash' => $cash, 'inflation' => $inflation, 'house' => $house];
    }

    /** A standard normal draw via Box-Muller from the seeded uniform generator. */
    private function standardNormal(Randomizer $rng): float
    {
        $u1 = $rng->nextFloat();
        $u2 = $rng->nextFloat();
        if ($u1 < 1e-12) {
            $u1 = 1e-12;
        }

        return sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);
    }
}
