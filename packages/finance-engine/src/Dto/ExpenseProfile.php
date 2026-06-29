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
 *
 * **Contingent costs (one home per cost, charged only while its condition holds).**
 * $propertyCosts and $employmentCosts are the portions of the spend above that are
 * *conditional*, carried separately so the engine can stop charging them when the
 * condition no longer holds (they are also still part of essential/discretionary and
 * thus of {@see targetAnnualSpend} — they are a marked subset, not an addition):
 *  - $propertyCosts — housing-linked costs tied to the *current* home (mortgage payment,
 *    service charge / ground rent) entered as spend lines. They stop when that home is
 *    sold: the buy/rent variants build their household with {@see withoutPropertyCosts},
 *    so only "stay put" keeps them. (Property *running* costs — maintenance / insurance /
 *    council tax — live on the {@see Property} and are already tied to ownership there.)
 *  - $employmentCosts — status-linked costs (e.g. commuting) charged only while someone is
 *    working; the projector drops them in years no one earns (i.e. from retirement).
 * Both are treated as essential (their auto-classified members — mortgage, service charge,
 * commute — are needs), so removing them reduces the essential floor first. Null = none.
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
        public readonly ?Money $propertyCosts = null,
        public readonly ?Money $employmentCosts = null,
    ) {}

    public function targetAnnualSpend(): Money
    {
        return $this->essentialAnnualSpend->plus($this->discretionaryAnnualSpend);
    }

    /** The housing-linked contingent costs (zero if none). */
    public function propertyCosts(): Money
    {
        return $this->propertyCosts ?? Money::zero();
    }

    /** The employment-linked contingent costs (zero if none). */
    public function employmentCosts(): Money
    {
        return $this->employmentCosts ?? Money::zero();
    }

    /**
     * The same profile with the current home's housing-linked costs removed — for the
     * buy/rent variants, where that home is sold. The costs are essential by nature, so
     * they come out of the essential floor (capped at zero); discretionary is unchanged.
     */
    public function withoutPropertyCosts(): self
    {
        $property = $this->propertyCosts();
        if (! $property->isPositive()) {
            return $this;
        }

        return new self(
            essentialAnnualSpend: $this->essentialAnnualSpend->minus($property)->minZero(),
            discretionaryAnnualSpend: $this->discretionaryAnnualSpend,
            survivorSpendFactor: $this->survivorSpendFactor,
            oneOffCosts: $this->oneOffCosts,
            propertyCosts: null,
            employmentCosts: $this->employmentCosts,
        );
    }
}
