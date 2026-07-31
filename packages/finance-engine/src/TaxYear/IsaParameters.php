<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * ISA subscription limits for one tax year.
 *
 * The overall allowance is **per person, per tax year**, and it caps what may be paid IN — not what
 * the wrapper may hold. A pot that grew to £500,000 inside an ISA is fine; paying £30,000 into one
 * in a single year is not, and until this existed the projector let it happen.
 *
 *  - overallAllowance: the total that may be subscribed across all of one person's ISAs in a year.
 *  - cashAllowanceUnder65 / cashAllowanceAgeThreshold: from 6 April 2027 the CASH ISA allowance is
 *    cut for savers below the threshold age while older savers keep the full overall allowance. The
 *    overall figure is unchanged either way — the balance must go to stocks and shares.
 *  - reformFromTaxYear: the tax year the cash-ISA cut takes effect ('2027-28'), so a projection
 *    that runs through it applies the old rule before and the new rule after, from the record
 *    rather than from a hard-coded date in the projector.
 *
 * The engine models an ISA as a single invested balance, so the cash-vs-stocks split (and the
 * 2027 charge on cash held inside a stocks-and-shares ISA) has nothing to bite on here; the
 * fields are carried because a public release needs them and because a figure with a source is
 * better than a figure discovered later. See docs/spec/METHODOLOGY.md "What we don't model".
 */
final class IsaParameters
{
    public function __construct(
        public readonly Money $overallAllowance,
        public readonly Money $cashAllowanceUnder65,
        public readonly int $cashAllowanceAgeThreshold,
        public readonly string $reformFromTaxYear,
    ) {}
}
