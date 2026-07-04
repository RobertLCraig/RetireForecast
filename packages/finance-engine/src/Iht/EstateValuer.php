<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Iht;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * Values the estate attributable to a death from its raw parts, as ONE definition
 * built from its components (the reconciliation rule): liquid assets, the share of
 * the home net of its mortgage, and the remaining pension value.
 *
 * The caller decides whose share this is:
 *  - First death of a couple: the deceased's own liquid + pension (held per person in
 *    the projector state) and their share of the home.
 *  - Last death: the whole remaining estate (the survivor holds all the liquid +
 *    pensions by then, plus the whole home).
 *
 * Home equity is floored at zero (a mortgage larger than the value is not a negative
 * estate). The valuer does no tax, exemption or death-order logic — that is the
 * {@see InheritanceTaxCalculator} plus the relationship-status wiring in the forecast;
 * this only produces the reconciled figures they consume.
 */
final class EstateValuer
{
    /**
     * The estate at a death: the liquid and pension value passed in, plus the home equity
     * (home value less mortgage, floored at zero) attributable to this death.
     */
    public static function value(Money $liquid, Money $pensionValue, Money $homeValue, Money $mortgage): EstateValuation
    {
        $homeEquity = $homeValue->minus($mortgage)->minZero();

        return new EstateValuation(
            liquid: $liquid,
            homeEquity: $homeEquity,
            pensionValue: $pensionValue,
            estateExcludingPensions: $liquid->plus($homeEquity),
        );
    }
}
