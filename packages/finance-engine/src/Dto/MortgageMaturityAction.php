<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * What happens to a property's mortgage when its term ends / it is called for redemption
 * ({@see Property::$mortgageRedemptionYear}). An interest-only or fixed-term mortgage that
 * cannot simply roll on forces one of these:
 *
 *  - Refinance:       the loan rolls into a new mortgage; payments continue (no capital event).
 *                     This is the default, so a property with no redemption year is unaffected.
 *  - RepayFromCapital: the outstanding balance is cleared from the household's liquid assets in
 *                     the redemption year (a one-off outflow) — used to model keeping the home by
 *                     paying the mortgage off (or converting it) with capital. If the capital is
 *                     not there, the shortfall surfaces, flagging the option as unaffordable.
 *  - ForcedSale:      the home cannot be kept — it must be sold. v1 directs this to the sell
 *                     variants (sell-and-rent / buy-cheaper), which model the sale, costs and CGT
 *                     in full; the feasibility layer flags that staying is not an option.
 */
enum MortgageMaturityAction: string
{
    case Refinance = 'refinance';
    case RepayFromCapital = 'repay_from_capital';
    case ForcedSale = 'forced_sale';
}
