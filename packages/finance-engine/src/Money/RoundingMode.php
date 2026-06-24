<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Money;

/**
 * Rounding strategies for integer division inside the money layer.
 *
 * UK tax calculations round differently per tax (income tax, SDLT and CGT each
 * have their own conventions), so the rounding mode is always passed explicitly
 * at the call site rather than assumed globally.
 */
enum RoundingMode
{
    /** Round to nearest; ties go away from zero (matches PHP_ROUND_HALF_UP). */
    case HalfUp;

    /** Round to nearest; ties go to the even neighbour (banker's rounding). */
    case HalfEven;

    /** Round towards negative infinity. */
    case Floor;

    /** Round towards positive infinity. */
    case Ceil;
}
