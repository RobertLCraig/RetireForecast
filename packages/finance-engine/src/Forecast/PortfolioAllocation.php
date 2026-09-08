<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;

/**
 * How invested pots (DC pensions, ISA, GIA) are split across the asset classes of
 * an AssumptionSet. Weights line up with {@see AssumptionSet::$assetClasses} order
 * (global equities, gilts/bonds, cash) and must sum to 1.
 *
 * The default is the signed-off "cautious 40/60" (40% equities, 60% bonds, no cash
 * within pots); cash accounts are modelled separately at the cash assumption.
 *
 * Board card 0062 added three things to it, all for one reason: the mix is where the risk
 * lives, and until then the risk could not be moved at all.
 *
 *  - $endWeights + $glideYears make it a GLIDEPATH: the mix moves in a straight line from
 *    $weights to $endWeights over $glideYears years and then stays there. {@see at()} is the
 *    one home of that arithmetic, and every draws class asks it per year, so no surface can
 *    show a de-risking plan that the projection did not run. Null end weights = a fixed mix,
 *    where at() returns the same object for every year and nothing stored moves.
 *  - {@see blendedVolatility()} reports the risk the mix actually carries, read through the
 *    set's own correlation matrix. It is what makes "more return" visibly cost something.
 *  - {@see forBlendedRealReturn()} answers "what mix would earn X?" by RE-WEIGHTING, which
 *    is the only way to raise the expected return that also raises the volatility. The old
 *    route (shifting every asset class's mean and leaving the volatilities alone) manufactured
 *    return out of nothing inside a Monte Carlo whose whole job is to price risk.
 */
final class PortfolioAllocation
{
    /**
     * @param  list<float>  $weights  same order as the AssumptionSet's asset classes
     * @param  list<float>|null  $endWeights  the mix glided TO (null = a fixed mix)
     * @param  int  $glideYears  years taken to get there (ignored without end weights)
     */
    public function __construct(
        public readonly array $weights,
        public readonly ?array $endWeights = null,
        public readonly int $glideYears = 0,
    ) {}

    public static function cautious40_60(): self
    {
        return AllocationProfile::Cautious->allocation();
    }

    /**
     * The same starting mix, de-risking to $target over $years. A non-positive number of
     * years is no glidepath at all rather than an instant jump: the reader who says "over 0
     * years" has not chosen a second mix, they have mistyped, and the fixed mix they picked
     * is the safe reading.
     */
    public function glidingTo(self $target, int $years): self
    {
        if ($years <= 0) {
            return new self($this->weights);
        }

        return new self($this->weights, $target->weights, $years);
    }

    public function glides(): bool
    {
        return $this->endWeights !== null && $this->glideYears > 0;
    }

    /**
     * The mix in force in year $yearIndex: the starting mix in year 0, the target from the
     * end of the glide onwards, and a straight line between them. A fixed allocation returns
     * itself, so a plan with no glidepath is byte-identical to before this existed.
     */
    public function at(int $yearIndex): self
    {
        if (! $this->glides() || $yearIndex <= 0) {
            return $this->glides() ? new self($this->weights) : $this;
        }

        $through = min(1.0, $yearIndex / $this->glideYears);
        $blended = [];
        foreach ($this->weights as $i => $weight) {
            $blended[] = $weight + $through * (($this->endWeights[$i] ?? 0.0) - $weight);
        }

        return new self($blended);
    }

    /** The allocation-weighted expected real return for invested pots. */
    public function blendedRealReturn(AssumptionSet $set): float
    {
        $blended = 0.0;
        foreach ($set->assetClasses as $i => $assetClass) {
            $blended += ($this->weights[$i] ?? 0.0) * $assetClass->expectedRealReturn->asFraction();
        }

        return $blended;
    }

    /**
     * The annual standard deviation of the MIX's real return: sqrt(w' C w) over the set's
     * asset volatilities and correlation matrix. This is the figure that has to move when the
     * expected return moves; showing it beside the return is what stops a reader reading a
     * raised growth rate as free money.
     */
    public function blendedVolatility(AssumptionSet $set): float
    {
        $variance = 0.0;
        foreach ($set->assetClasses as $i => $a) {
            foreach ($set->assetClasses as $j => $b) {
                $correlation = $i === $j ? 1.0 : ($set->correlationMatrix[$i][$j] ?? 0.0);
                $variance += ($this->weights[$i] ?? 0.0) * ($this->weights[$j] ?? 0.0)
                    * $a->volatility->asFraction() * $b->volatility->asFraction() * $correlation;
            }
        }

        return sqrt(max(0.0, $variance));
    }

    /**
     * The blended real returns reachable by moving between equities and bonds while holding
     * the mix's cash weight where the reader put it: [all bonds, all equities]. Anything
     * outside it cannot be had at any risk from these asset classes, which is what makes a
     * request for it a refusal rather than a re-weighting.
     *
     * @return array{0: float, 1: float}
     */
    public static function reachableRealReturnRange(AssumptionSet $set, self $from): array
    {
        $cashWeight = $from->weights[2] ?? 0.0;
        $investable = max(0.0, 1.0 - $cashWeight);
        $cash = ($set->assetClasses[2] ?? null)?->expectedRealReturn->asFraction() ?? 0.0;
        $equity = ($set->assetClasses[0] ?? null)?->expectedRealReturn->asFraction() ?? 0.0;
        $bond = ($set->assetClasses[1] ?? null)?->expectedRealReturn->asFraction() ?? 0.0;

        $floor = $cashWeight * $cash + $investable * $bond;
        $ceiling = $cashWeight * $cash + $investable * $equity;

        return [min($floor, $ceiling), max($floor, $ceiling)];
    }

    /**
     * The mix whose blended real return IS $target, found by moving weight between equities
     * and bonds and leaving the cash weight and the glidepath alone. Null when no mix of
     * these asset classes reaches it: the caller must then refuse the figure rather than
     * quietly hand back something else, because a return nobody can earn is exactly the
     * free lunch this replaced.
     */
    public static function forBlendedRealReturn(AssumptionSet $set, float $target, self $from): ?self
    {
        [$min, $max] = self::reachableRealReturnRange($set, $from);
        if ($target < $min - 1e-9 || $target > $max + 1e-9) {
            return null;
        }

        $cashWeight = $from->weights[2] ?? 0.0;
        $investable = max(0.0, 1.0 - $cashWeight);
        if ($investable <= 0.0) {
            return null;
        }

        $cash = ($set->assetClasses[2] ?? null)?->expectedRealReturn->asFraction() ?? 0.0;
        $equity = ($set->assetClasses[0] ?? null)?->expectedRealReturn->asFraction() ?? 0.0;
        $bond = ($set->assetClasses[1] ?? null)?->expectedRealReturn->asFraction() ?? 0.0;
        $spread = $equity - $bond;
        if (abs($spread) < 1e-12) {
            return null;
        }

        // target = cashWeight*cash + equityWeight*equity + (investable - equityWeight)*bond
        $equityWeight = ($target - $cashWeight * $cash - $investable * $bond) / $spread;
        $equityWeight = max(0.0, min($investable, $equityWeight));

        return new self([$equityWeight, $investable - $equityWeight, $cashWeight], $from->endWeights, $from->glideYears);
    }
}
