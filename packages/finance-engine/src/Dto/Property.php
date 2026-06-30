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
    ) {}
}
