<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\MonteCarlo;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The spread of Inheritance Tax across the Monte Carlo's sampled futures, when IHT is
 * modelled. Because the tax depends on how long you live and how your assets fare, it
 * varies a lot path to path — a single deterministic figure hides that. This shows the
 * shape: how often any IHT is due at all, and the median vs the high-end bill.
 *
 * $shareWithAnyIht is the fraction of paths whose total IHT is above zero. $medianIht and
 * $p90Iht are percentiles across ALL paths (including the £0 ones) in REAL today's money —
 * so the median reads as the central "what you'd leave" outcome (often £0 or modest) and
 * $p90Iht as the tail (long life + strong returns leave a large estate). Null when IHT is
 * not modelled.
 */
final class IhtDistribution
{
    public function __construct(
        public readonly float $shareWithAnyIht,
        public readonly Money $medianIht,
        public readonly Money $p90Iht,
    ) {}
}
