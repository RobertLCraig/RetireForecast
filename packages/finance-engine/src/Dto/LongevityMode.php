<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * How a person's modelled lifespan departs from the cohort-table peer average.
 * Used by a longevity what-if (e.g. a known health condition, or expecting to
 * live longer than average).
 */
enum LongevityMode
{
    /** Use the cohort life table unchanged (the default). */
    case Peer;

    /** Assume death at a specific age, regardless of the table. */
    case FixedAge;

    /** Shift the peer death age by ± whole years. */
    case OffsetYears;

    /** Scale every year's mortality rate q(x) by a multiplier (>1 = higher mortality, shorter life). */
    case MortalityMultiplier;
}
