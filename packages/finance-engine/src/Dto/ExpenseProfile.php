<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The household's spending target, split into the essential floor (the bar for the
 * "essentials always met" success measure) and discretionary spend on top.
 *
 * $survivorSpendFactor is the proportion of the couple's spend that the survivor
 * needs after the first death (commonly around 70%), applied by the joint-life
 * model. $oneOffCosts are dated lump expenses (care, moving costs, etc.) keyed by
 * the age at which they fall.
 */
final class ExpenseProfile
{
    /**
     * @param  list<array{atAge: int, amount: Money, label: string}>  $oneOffCosts
     */
    public function __construct(
        public readonly Money $essentialAnnualSpend,
        public readonly Money $discretionaryAnnualSpend,
        public readonly Percent $survivorSpendFactor,
        public readonly array $oneOffCosts = [],
    ) {}

    public function targetAnnualSpend(): Money
    {
        return $this->essentialAnnualSpend->plus($this->discretionaryAnnualSpend);
    }
}
