<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

/**
 * Whether the success probability is provably monotone in a lever's value — which decides
 * whether the crossing may be found by a monotone fit (S3: "monotone-fit only where the
 * lever is provably monotone; buy price is, longevity is not").
 *
 *  - Increasing: more of the lever raises success (e.g. more starting cash, a later retirement).
 *  - Decreasing: more of the lever lowers success (e.g. a higher buy price, higher spend).
 *  - Unknown:    not monotone (drawdown order, State-Pension deferral, the longevity lever) —
 *                the sweep must report the first crossing and flag that others may exist.
 */
enum LeverDirection
{
    case Increasing;
    case Decreasing;
    case Unknown;
}
