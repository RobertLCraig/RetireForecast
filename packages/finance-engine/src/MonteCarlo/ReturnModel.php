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
 * drawn independently around its mean. House-price and salary growth use their
 * expected values in v1 (their own volatility is a later refinement).
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
    }

    /**
     * Generate $years of returns for one path.
     *
     * @return array{investment: list<float>, cash: list<float>, inflation: list<float>}
     */
    public function generatePath(int $years, Randomizer $rng): array
    {
        $investment = [];
        $cash = [];
        $inflation = [];

        $weights = $this->allocation->weights;
        $inflMean = $this->set->inflationMean->asFraction();
        $inflVol = $this->set->inflationVolatility->asFraction();

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
        }

        return ['investment' => $investment, 'cash' => $cash, 'inflation' => $inflation];
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
