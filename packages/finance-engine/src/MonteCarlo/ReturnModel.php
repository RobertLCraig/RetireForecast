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
 * into the invested-pot return; the cash asset drives the cash return.
 *
 * Inflation is a factor IN that matrix rather than a draw beside it (board card 0064), so a
 * price shock lands on each asset class by its own amount, and it carries an AR(1) memory, so
 * an episode runs for years the way real inflation does. Both are off on a set that states
 * neither, which is then exactly the independent memoryless draw the engine made before.
 *
 * House-price growth is stochastic when the set carries a house volatility: a per-year
 * house shock is drawn, correlated to the equity shock (asset index 0) by the set's
 * house-equity correlation, so a home's value co-varies weakly with markets rather than
 * marching up a straight line. With no house volatility (null) it stays deterministic at
 * its mean — the v1 behaviour — and no house draw is consumed, so those runs are
 * byte-identical to before.
 *
 * Salary growth is stochastic on exactly the same footing when the set carries a salary
 * volatility: a per-year salary shock is drawn, weakly correlated to the equity shock, so
 * a still-working household's earnings (and the savings they fund) carry earnings risk.
 * Null salary volatility keeps it deterministic and consumes no draw, so every pre-existing
 * stored run (whose snapshot has no salary volatility) is byte-identical to before — the
 * reproducibility guarantee that matters. The salary shock is drawn LAST in each year's
 * iteration purely as good order: it keeps that year's house/asset/inflation draws ahead of
 * it (a fresh feature appended at the end), though once salary volatility IS on the extra
 * draw does advance the shared stream for later years, so a set with BOTH volatilities on
 * samples fresh house/salary paths together (same distribution, no stored run affected).
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

    private readonly float $salaryMean;

    private readonly float $salaryVol;

    private readonly float $salaryEquityCorrelation;

    public function __construct(
        private readonly AssumptionSet $set,
        private readonly PortfolioAllocation $allocation,
    ) {
        // Inflation is decomposed AS AN EXTRA FACTOR alongside the asset classes rather than drawn
        // beside them (board card 0064), so a price shock lands on each asset class by its own
        // amount: hardest on nominal gilts, least on real assets. It goes LAST, so index 0 is still
        // global equities for the house and salary factors that hang off it, and — because a set
        // stating no correlations produces a last row of [0, ..., 0, 1] — the inflation shock is
        // then exactly the raw normal that used to be drawn here, in the same position in the RNG
        // stream. A set that models nothing new is therefore byte-identical to before.
        $this->cholesky = Cholesky::decompose(self::withInflationRow($set));

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

        // Salary growth: the same construction as housing (mean always; volatility only when
        // the set carries one; correlation clamped to [-1, 1]).
        $this->salaryMean = $set->salaryGrowth->asFraction();
        $this->salaryVol = $set->salaryGrowthVolatility?->asFraction() ?? 0.0;
        $this->salaryEquityCorrelation = max(-1.0, min(1.0, $set->salaryEquityCorrelation));
    }

    /**
     * Generate $years of returns for one path.
     *
     * @return array{investment: list<float>, cash: list<float>, inflation: list<float>, house: list<float>, salary: list<float>}
     */
    public function generatePath(int $years, Randomizer $rng): array
    {
        $investment = [];
        $cash = [];
        $inflation = [];
        $house = [];
        $salary = [];

        // The mix in force in each year. A glidepath (board card 0062) de-risks as the plan runs
        // on, so the weights are read per year rather than once; a fixed mix returns the same
        // array every year and consumes no extra draw, so the RNG stream and every stored run
        // are byte-identical to before.
        $glides = $this->allocation->glides();
        $weights = $this->allocation->weights;
        $inflMean = $this->set->inflationMean->asFraction();
        $inflVol = $this->set->inflationVolatility->asFraction();
        $houseIndependentScale = sqrt(max(0.0, 1.0 - $this->houseEquityCorrelation ** 2));
        $salaryIndependentScale = sqrt(max(0.0, 1.0 - $this->salaryEquityCorrelation ** 2));

        // Inflation's AR(1) memory: phi is how much of one year's deviation from the mean survives
        // into the next, and the innovation is scaled by sqrt(1 - phi^2) so the UNCONDITIONAL
        // spread of any single year stays exactly the stated volatility. Persistence therefore buys
        // cumulative spread over a retirement — which is the point, the model running against
        // nominal thresholds frozen for years — without silently raising the volatility the reader
        // typed. Year 0 is drawn from that same stationary distribution, so no year is special.
        $phi = $this->set->inflationPersistence();
        $innovationScale = sqrt(max(0.0, 1.0 - $phi ** 2));
        $inflationIndex = count($this->means);
        $deviation = 0.0;

        for ($y = 0; $y < $years; $y++) {
            $u = [];
            for ($i = 0; $i <= $inflationIndex; $i++) {
                $u[$i] = $this->standardNormal($rng);
            }
            $z = Cholesky::apply($this->cholesky, $u);

            if ($glides) {
                $weights = $this->allocation->at($y)->weights;
            }

            $blended = 0.0;
            foreach ($this->means as $i => $mean) {
                $assetReal = $mean + $this->vols[$i] * $z[$i];
                $blended += ($weights[$i] ?? 0.0) * $assetReal;
            }

            $investment[] = $blended;
            $cash[] = $this->means[$this->cashIndex] + $this->vols[$this->cashIndex] * $z[$this->cashIndex];
            $deviation = $y === 0
                ? $inflVol * $z[$inflationIndex]
                : $phi * $deviation + $innovationScale * $inflVol * $z[$inflationIndex];
            $inflation[] = $inflMean + $deviation;

            // House-price shock, correlated to the equity shock (z[0]) by rho: a fresh normal
            // supplies the idiosyncratic part. Only drawn when the set has a house volatility,
            // so deterministic-house sets never touch the RNG stream (byte-identical to v1).
            if ($this->houseVol > 0.0) {
                $houseZ = $this->houseEquityCorrelation * $z[0] + $houseIndependentScale * $this->standardNormal($rng);
                $house[] = $this->houseMean + $this->houseVol * $houseZ;
            } else {
                $house[] = $this->houseMean;
            }

            // Salary-growth shock, correlated to the equity shock (z[0]) by rho, drawn LAST so it
            // never perturbs the house/asset/inflation stream. Only drawn when the set has a salary
            // volatility, so deterministic-salary sets are byte-identical to before.
            if ($this->salaryVol > 0.0) {
                $salaryZ = $this->salaryEquityCorrelation * $z[0] + $salaryIndependentScale * $this->standardNormal($rng);
                $salary[] = $this->salaryMean + $this->salaryVol * $salaryZ;
            } else {
                $salary[] = $this->salaryMean;
            }
        }

        return ['investment' => $investment, 'cash' => $cash, 'inflation' => $inflation, 'house' => $house, 'salary' => $salary];
    }

    /**
     * The set's asset correlation matrix with inflation appended as a final row and column, from
     * {@see AssumptionSet::inflationAssetCorrelations()} (which pads and clamps, so the row is
     * always the right length and always in [-1, 1]). The result is symmetric with 1.0 on the
     * diagonal, which is the contract {@see Cholesky::decompose} needs; it still throws where the
     * stated correlations describe a world that cannot exist, rather than quietly producing one.
     *
     * @return list<list<float>>
     */
    private static function withInflationRow(AssumptionSet $set): array
    {
        $row = $set->inflationAssetCorrelations();
        $matrix = [];
        foreach ($set->correlationMatrix as $i => $assetRow) {
            $matrix[] = [...array_map(static fn ($v): float => (float) $v, $assetRow), $row[$i] ?? 0.0];
        }
        $matrix[] = [...$row, 1.0];

        return $matrix;
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
