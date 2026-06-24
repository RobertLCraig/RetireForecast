<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A non-pension savings/investment account. All such accounts are assessable
 * capital for means-tested benefits. $unrealisedGain (GIA only) feeds CGT; ISA
 * income and gains are tax-free. $yield is the assumed income return for the
 * forecast.
 */
final class Account
{
    public function __construct(
        public readonly string $ownerId,
        public readonly AccountType $type,
        public readonly Money $balance,
        public readonly ?Money $unrealisedGain = null,
        public readonly ?Percent $yield = null,
    ) {}
}
