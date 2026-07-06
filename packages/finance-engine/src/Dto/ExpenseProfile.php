<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use InvalidArgumentException;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The household's spending target, split into the essential floor (the bar for the
 * "essentials always met" success measure) and discretionary spend on top.
 *
 * **Age-varying spend (the "smile").** Essential and discretionary spend each carry a
 * {@see SpendPath} — a piecewise-constant real path by the reference person's age — so spend
 * can step with age (go-go / slow-go / no-go, or a per-line Basu-style schedule). The
 * $essentialAnnualSpend / $discretionaryAnnualSpend scalars are the **headline** (start-band)
 * amounts — the same values as before for a flat plan, and the figure the non-age-aware
 * consumers (benchmarks, presenters, sweep levers) read. The projector reads the age-aware
 * {@see essentialAnnualSpendAt} / {@see targetAnnualSpendAt} instead. A plan with no smile has
 * flat paths, so every scalar and every age lookup returns the one value — byte-identical to
 * the pre-smile engine. **One home:** the scalar is always the path's first band, enforced in
 * the constructor; a caller building a path derives the scalar from {@see SpendPath::startAmount}.
 *
 * $survivorSpendFactor is the proportion of the couple's spend that the survivor
 * needs after the first death (commonly around 70%), applied by the joint-life
 * model. $oneOffCosts are dated lump expenses (care, moving costs, etc.) keyed by
 * the age at which they fall.
 *
 * **Contingent costs (one home per cost, charged only while its condition holds).**
 * $propertyCosts, $mortgageCosts and $employmentCosts are the portions of the spend above
 * that are *conditional*, carried separately so the engine can stop charging them when the
 * condition no longer holds (they are also still part of essential/discretionary and thus of
 * {@see targetAnnualSpend} — a marked subset, not an addition). They are flat (they do not
 * smile — a mortgage payment / service charge / commute does not fade with age), so the
 * withers below add/remove them from every band of the essential path:
 *  - $propertyCosts — housing-linked costs tied to owning the *current* home (service charge /
 *    ground rent / factor fee) entered as spend lines. They stop when that home is sold: the
 *    buy/rent variants build their household with {@see withoutPropertyCosts}, so only "stay
 *    put" keeps them. (Property *running* costs — maintenance / insurance / council tax — live
 *    on the {@see Property} and are already tied to ownership there.)
 *  - $mortgageCosts — the ongoing mortgage *payment*, which stops when the mortgage ends,
 *    whether by *sale* (dropped by {@see withoutPropertyCosts}, like the other property costs)
 *    or by *redemption* while the home is kept (the projector drops it once the mortgage is
 *    repaid from capital — a stricter condition than "while owning", which service charge etc.
 *    keep). Separated so a repay-and-stay path does not double-count the repayment plus the
 *    ongoing payment.
 *  - $employmentCosts — status-linked costs (e.g. commuting) charged only while someone is
 *    working; the projector drops them in years no one earns (i.e. from retirement).
 * All are treated as essential (their auto-classified members — mortgage, service charge,
 * commute — are needs), so removing them reduces the essential floor first. Null = none.
 */
final class ExpenseProfile
{
    /** The essential floor as an age-varying real path — its first band is $essentialAnnualSpend. */
    public readonly SpendPath $essentialSpendPath;

    /** The discretionary spend as an age-varying real path — its first band is $discretionaryAnnualSpend. */
    public readonly SpendPath $discretionarySpendPath;

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
        public readonly ?Money $mortgageCosts = null,
        ?SpendPath $essentialSpendPath = null,
        ?SpendPath $discretionarySpendPath = null,
    ) {
        $this->essentialSpendPath = $this->resolvePath($essentialSpendPath, $essentialAnnualSpend, 'essential');
        $this->discretionarySpendPath = $this->resolvePath($discretionarySpendPath, $discretionaryAnnualSpend, 'discretionary');
    }

    /** The headline (start-of-retirement) target: essential + discretionary first-band spend. */
    public function targetAnnualSpend(): Money
    {
        return $this->essentialAnnualSpend->plus($this->discretionaryAnnualSpend);
    }

    /** The essential floor for a reference person of the given age (the path lookup). */
    public function essentialAnnualSpendAt(int $refAge): Money
    {
        return $this->essentialSpendPath->amountAt($refAge);
    }

    /** The discretionary spend for a reference person of the given age (the path lookup). */
    public function discretionaryAnnualSpendAt(int $refAge): Money
    {
        return $this->discretionarySpendPath->amountAt($refAge);
    }

    /** The total target spend for a reference person of the given age (essential + discretionary paths). */
    public function targetAnnualSpendAt(int $refAge): Money
    {
        return $this->essentialAnnualSpendAt($refAge)->plus($this->discretionaryAnnualSpendAt($refAge));
    }

    /** True when spend varies with age — a smile is present on either the essential or discretionary path. */
    public function hasSmile(): bool
    {
        return ! $this->essentialSpendPath->isFlat() || ! $this->discretionarySpendPath->isFlat();
    }

    /** The housing-linked contingent costs — service charge / ground rent (zero if none). */
    public function propertyCosts(): Money
    {
        return $this->propertyCosts ?? Money::zero();
    }

    /** The ongoing mortgage payment — stops when the mortgage ends by sale or redemption (zero if none). */
    public function mortgageCosts(): Money
    {
        return $this->mortgageCosts ?? Money::zero();
    }

    /** The employment-linked contingent costs (zero if none). */
    public function employmentCosts(): Money
    {
        return $this->employmentCosts ?? Money::zero();
    }

    /**
     * The same profile with the current home's housing-linked costs removed — for the
     * buy/rent variants, where that home is sold. That means BOTH the ownership costs
     * (service charge / ground rent) AND the mortgage payment: a sold home pays neither.
     * The costs are essential by nature and flat, so they come out of every band of the
     * essential path (capped at zero); the discretionary path is unchanged.
     */
    public function withoutPropertyCosts(): self
    {
        $housing = $this->propertyCosts()->plus($this->mortgageCosts());
        if (! $housing->isPositive()) {
            return $this;
        }

        $essentialPath = $this->essentialSpendPath->minusFlat($housing);

        return new self(
            essentialAnnualSpend: $essentialPath->startAmount(),
            discretionaryAnnualSpend: $this->discretionaryAnnualSpend,
            survivorSpendFactor: $this->survivorSpendFactor,
            oneOffCosts: $this->oneOffCosts,
            propertyCosts: null,
            employmentCosts: $this->employmentCosts,
            mortgageCosts: null,
            essentialSpendPath: $essentialPath,
            discretionarySpendPath: $this->discretionarySpendPath,
        );
    }

    /**
     * The same profile with an ongoing mortgage payment added — the interest-only cost of a
     * mortgage taken to fund a buy above the sale proceeds. It is an essential, flat cost, so it
     * lifts every band of the essential path and is marked as the mortgage subset (so it stops if
     * that mortgage is ever redeemed). Typically applied after {@see withoutPropertyCosts} on a
     * buy variant, so it replaces the sold home's payment with the new home's.
     */
    public function withMortgageCosts(Money $mortgageCosts): self
    {
        $essentialPath = $this->essentialSpendPath->plusFlat($mortgageCosts);

        return new self(
            essentialAnnualSpend: $essentialPath->startAmount(),
            discretionaryAnnualSpend: $this->discretionaryAnnualSpend,
            survivorSpendFactor: $this->survivorSpendFactor,
            oneOffCosts: $this->oneOffCosts,
            propertyCosts: $this->propertyCosts,
            employmentCosts: $this->employmentCosts,
            mortgageCosts: $mortgageCosts,
            essentialSpendPath: $essentialPath,
            discretionarySpendPath: $this->discretionarySpendPath,
        );
    }

    /**
     * Resolve the stored path: the supplied path, else a flat path from the scalar. When a path
     * is supplied its first band MUST equal the scalar headline — the "one home" invariant that
     * keeps the scalar and the path from drifting (a caller derives the scalar from
     * {@see SpendPath::startAmount}). A loud failure, never a silent divergence.
     */
    private function resolvePath(?SpendPath $path, Money $scalar, string $which): SpendPath
    {
        if ($path === null) {
            return SpendPath::flat($scalar);
        }

        if (! $path->startAmount()->equals($scalar)) {
            throw new InvalidArgumentException(
                "The {$which} spend scalar ({$scalar}) must equal the path's first band ({$path->startAmount()})."
            );
        }

        return $path;
    }
}
