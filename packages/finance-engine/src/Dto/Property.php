<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A property the household owns. The main residence is exempt from CGT (Private
 * Residence Relief) and from means-tested-benefit capital while occupied; selling
 * it is what converts that exempt value into assessable capital.
 *
 * $everLet flags a past letting period that restricts PRR. $runningCosts is the
 * annual maintenance + insurance + council tax used in the buy-vs-rent comparison.
 * $ownershipShare null means wholly owned (100%).
 *
 * $cgtHistory, when set, drives the Capital Gains Tax on selling a home whose Private
 * Residence Relief is only partial (it was let / not the main home for part of ownership);
 * null is the common full-relief case (main home throughout) — no CGT on sale.
 *
 * $mortgageRedemptionYear is the calendar year the current mortgage term ends / it is called
 * for redemption (an interest-only or fixed-term loan that cannot simply roll on). Null means
 * no scheduled event — the mortgage is assumed to continue, the existing behaviour.
 * $mortgageMaturityAction says what happens then ({@see MortgageMaturityAction}); the default
 * Refinance rolls it over, so a property without a redemption year is unaffected.
 *
 * $isLet flags that the household lets this property out and lives elsewhere (the "let-to-let"
 * strategy) rather than occupying it. It is then no longer the exempt main residence for the
 * pension-age means test: its equity (value − outstanding mortgage) counts as ASSESSABLE
 * capital, so — like selling — letting it out erodes Pension Credit and can cross the £16,000
 * Housing/Council-Tax-support cliff. Default false = they occupy it (exempt, the common case).
 *
 * $mortgageRollUpRate models a lifetime mortgage (equity release): a FIXED, fixed-for-life
 * NOMINAL interest rate at which the $outstandingMortgage balance ROLLS UP (compounds) each
 * year when no payments are made — the balance is repaid from the estate on death/sale/care,
 * capped at the home's value by the Equity Release Council No-Negative-Equity Guarantee. Null
 * (the default) = the balance is STATIC, as before: a repayment or interest-serviced mortgage
 * (RIO), whose interest — if any — is entered as an expense line, not accrued here. Set it only
 * for the no-payments roll-up case; an interest-serviced lifetime mortgage keeps the balance
 * level, so it leaves this null and carries the interest as an expense (like a RIO).
 *
 * $mortgageOverpaymentAnnual is a voluntary annual overpayment on a rolled-up lifetime mortgage:
 * many products allow penalty-free overpayments (typically up to ~10% of the loan a year) that
 * reduce the balance, slowing the roll-up. It is a FIXED NOMINAL amount subtracted from the balance
 * each year AFTER the roll-up compounds (the balance grows at the rate, then the overpayment pays
 * some back). Applies only when $mortgageRollUpRate is set (a serviced/RIO mortgage has no rolling
 * balance to overpay); null (the default) = pure roll-up, no overpayment. The cash to fund it is a
 * separate outflow (a "Mortgage" expense line of the same amount), so the two together model an
 * overpayment honestly: the balance falls, but the household must find the money to pay it.
 */
final class Property
{
    public function __construct(
        public readonly Money $currentValue,
        public readonly OwnershipType $ownership,
        public readonly bool $isPrimaryResidence = true,
        public readonly bool $everLet = false,
        public readonly ?Money $outstandingMortgage = null,
        public readonly ?Money $runningCosts = null,
        public readonly ?Percent $growthAssumptionOverride = null,
        public readonly ?Percent $ownershipShare = null,
        public readonly ?CgtHistory $cgtHistory = null,
        public readonly ?int $mortgageRedemptionYear = null,
        public readonly MortgageMaturityAction $mortgageMaturityAction = MortgageMaturityAction::Refinance,
        public readonly bool $isLet = false,
        public readonly ?Percent $mortgageRollUpRate = null,
        public readonly ?Money $mortgageOverpaymentAnnual = null,
    ) {}
}
