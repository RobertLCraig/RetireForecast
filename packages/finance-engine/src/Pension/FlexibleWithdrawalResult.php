<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\Warning;

/**
 * The full picture of a flexible pension withdrawal: how it splits, what tax is
 * actually taken at source (emergency basis on a first withdrawal), what is truly
 * due, and therefore what must be reclaimed and how.
 *
 * This is the data behind the headline "lump-sum tax shock" panel. The gap between
 * {@see $taxDeductedAtSource} and {@see $correctMarginalTax} is the over-deduction
 * the user did not expect; {@see $reclaimForm} is how they get it back.
 */
final class FlexibleWithdrawalResult
{
    /**
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public readonly WithdrawalKind $kind,
        public readonly Money $gross,
        public readonly Money $taxFree,
        public readonly Money $taxable,
        public readonly Money $lsaUsed,
        public readonly Money $taxDeductedAtSource,
        public readonly bool $emergencyBasisApplied,
        public readonly Money $correctMarginalTax,
        public readonly Money $overDeduction,
        public readonly ?ReclaimForm $reclaimForm,
        public readonly Money $netReceived,
        public readonly bool $mpaaTriggered,
        public readonly array $warnings,
    ) {}
}
