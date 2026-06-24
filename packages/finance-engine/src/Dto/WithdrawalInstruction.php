<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Pension\WithdrawalKind;

/**
 * A planned pension withdrawal: take $amount of a given $kind (PCLS / UFPLS /
 * drawdown income) at $atAge. The forecast applies these in order to drive the
 * lump-sum tax-shock and MPAA logic year by year.
 */
final class WithdrawalInstruction
{
    public function __construct(
        public readonly WithdrawalKind $kind,
        public readonly Money $amount,
        public readonly int $atAge,
    ) {}
}
