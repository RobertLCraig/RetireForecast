<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\Warning;

/**
 * The annual-allowance position for a year's money-purchase pension contributions.
 *
 *  - $availableAllowance: the most that can be contributed without an annual
 *    allowance charge, after any taper, the MPAA, and usable carry-forward.
 *  - $taperedAllowance: the standard £60,000 after the high-income taper (before
 *    the MPAA and carry-forward are considered).
 *  - $mpaaApplies: flexible access has reduced the money-purchase allowance to the
 *    MPAA and removed carry-forward.
 *  - $carryForwardUsed: unused allowance from earlier years actually applied (always
 *    zero once the MPAA applies).
 *  - $excessContributions: contributions above $availableAllowance, which suffer an
 *    annual allowance charge at the contributor's marginal rate.
 */
final class AnnualAllowanceResult
{
    /**
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public readonly Money $availableAllowance,
        public readonly Money $taperedAllowance,
        public readonly bool $mpaaApplies,
        public readonly Money $carryForwardUsed,
        public readonly Money $excessContributions,
        public readonly array $warnings,
    ) {}
}
