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
 *
 * $ongoingContributions is a planned regular payment into the account per year
 * (today's money) — e.g. a "saved" self-investment line. It is funded from surplus
 * income, so it stops automatically once the household is in net drawdown.
 */
final class Account
{
    public function __construct(
        public readonly string $ownerId,
        public readonly AccountType $type,
        public readonly Money $balance,
        public readonly ?Money $unrealisedGain = null,
        public readonly ?Percent $yield = null,
        public readonly ?Money $ongoingContributions = null,
    ) {}
}
