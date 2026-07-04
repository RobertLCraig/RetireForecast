<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Iht;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * The value of the estate attributable to one death, broken into its parts so the
 * total always reconciles to the sum of them (the data-integrity rule: a reported
 * total has one definition, built from its components — no stored total to drift).
 *
 * $estateExcludingPensions = $liquid + $homeEquity, kept separate from
 * $pensionValue because unused pensions only enter the estate from April 2027 (the
 * {@see InheritanceTaxCalculator}'s $includePensionsInEstate flag decides whether to
 * add them). All figures are in the units the caller supplies (the projector works
 * in nominal pounds at the death year; see {@see EstateValuer}).
 */
final class EstateValuation
{
    public function __construct(
        public readonly Money $liquid,
        public readonly Money $homeEquity,
        public readonly Money $pensionValue,
        public readonly Money $estateExcludingPensions,
    ) {}
}
