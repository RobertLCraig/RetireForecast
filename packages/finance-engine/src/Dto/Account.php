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
        // A plan to buy a PURCHASED LIFE ANNUITY with part of THIS account (board card 0060).
        // The account is the named source: the money leaves this wrapper at the purchase age and
        // becomes a secured income for life, taxed on its interest element only. Null = no annuity,
        // which is every account entered before the card. The mirror of
        // {@see DcPension::$annuityPurchase}, so an annuity has one DTO whichever asset buys it.
        public readonly ?AnnuityPurchase $annuityPurchase = null,
    ) {}

    /**
     * The same account marked to a different value (immutable) — the capacity-for-loss stress.
     * Balance and unrealised gain move together because they must: the cost basis does not change
     * in a market fall, so a fall of £X takes £X off the gain as well, and leaving the gain where
     * it was would tax a profit the household no longer has. Every other field is carried through
     * by name; `AssetWitherTest` fails if one is dropped.
     */
    public function withValue(Money $balance, ?Money $unrealisedGain): self
    {
        return new self(
            ownerId: $this->ownerId,
            type: $this->type,
            balance: $balance,
            unrealisedGain: $unrealisedGain,
            yield: $this->yield,
            ongoingContributions: $this->ongoingContributions,
            annuityPurchase: $this->annuityPurchase,
        );
    }
}
