<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * A documented one-off capital inflow — a family gift, an inheritance, the sale of
 * something outside the plan. This is the no-magic-money rule's input for money
 * arriving from outside the modelled assets: instead of a balance quietly assumed to
 * exist, the receipt states where the money comes from ($label), who receives it
 * ($ownerId), how much ($amount, today's money) and when ($calendarYear).
 *
 * The forecast credits it to spendable cash in its calendar year (inflated to that
 * year's prices, the one-off-cost convention) and reports it as the `capital_receipt`
 * income source, so it is visible on the cashflow ladder — never a secure income (it
 * is one-off) and never taxed (a gift is not income; any tax on the giver's side is
 * out of scope). Means-tested benefits see the banked cash through the capital tariff
 * from the following year, as they would in reality.
 *
 * If the owner has died by the receipt year the household still receives it (inflows
 * pool at household level; any residue banks to the first living person's cash — the
 * engine-wide surplus convention). A receipt dated after the last survivor's death is
 * never realised: the projection has ended.
 */
final class CapitalReceipt
{
    public function __construct(
        public readonly string $ownerId,
        public readonly string $label,
        public readonly Money $amount,
        public readonly int $calendarYear,
    ) {}
}
