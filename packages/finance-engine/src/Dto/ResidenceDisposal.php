<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * A former main residence the household has DISPOSED OF during the plan — the fact the
 * Inheritance Tax downsizing addition is computed from.
 *
 * Without this the engine deleted the residence nil-rate band from every plan that sells: a
 * sell-and-rent plan died owning no home and got a band of nil, and a sell-and-buy-cheaper plan
 * was capped at the cheaper home. The statute exists precisely to stop that, so the tool that
 * compares staying put against downsizing was penalising every downsizing option with tax
 * Parliament wrote a rule to prevent. The rule itself lives on `Iht\InheritanceTaxCalculator`,
 * named here in prose rather than as a `@see`: a DTO must not import the calculator that reads it,
 * and pint turns a fully-qualified `@see` into a real `use`.
 *
 * $netValue is the household's own interest in the home at the date it was sold: its share of the
 * sale price LESS the debt secured on it, the same net basis the estate values the home on at
 * death, so the two ends of the "lost band" subtraction are measured the same way. It is a NOMINAL
 * figure at $year, which is the right basis: the band it is compared against is frozen in cash
 * terms, so a disposal is measured against the band in force when it happened.
 *
 * $year is the calendar year of the disposal. The addition is only available for a disposal on or
 * after 8 July 2015 (`InheritanceTaxCalculator::DOWNSIZING_DISPOSALS_AFTER_YEAR`);
 * the engine models no disposal before its own base year, so the gate is a statement of the rule
 * rather than a filter that fires.
 */
final class ResidenceDisposal
{
    public function __construct(
        public readonly Money $netValue,
        public readonly int $year,
    ) {}
}
