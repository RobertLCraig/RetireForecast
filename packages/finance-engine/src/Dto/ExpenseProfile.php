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
    /**
     * The REAL (above-CPI) annual growth charged on the $propertyCosts bucket when the reader gives
     * no rate of their own: **CPI + 3%**.
     *
     * Plain CPI is the one shape the evidence rules out. The three largest components of a block
     * service charge have each compounded faster than prices since 2019: buildings insurance
     * (post-Grenfell risk repricing), building-safety compliance (surveys, waking watch, remediation
     * and the new regulatory regime), and the communal energy a charge covering water and lighting
     * buys, none of which a CPI escalator captures. Over a long projection the difference is
     * thousands a year of real spend concentrated in the survivor years, which is enough to flip a
     * verdict, so leaving it at zero was not the neutral choice it looked like.
     *
     * **Adverse by rule, editable by design** (Rob's standing default rule): where several figures
     * are defensible the shipped one is the most adverse, and it is exposed as a user input with its
     * alternatives beside it. CPI + 1.5% is the optimistic sensitivity.
     *
     * **PUBLIC so a presenter can DISCLOSE it without restating it**, the no-invisible-figures rule.
     * It applies ONLY when $propertyCosts is positive: a household with no service charge has nothing
     * for it to grow. An explicit rate, INCLUDING an explicit zero, is the reader's own figure and
     * always wins.
     *
     * **Source of record: the expert property review of 2026-08-19** (board card 0028), which models
     * CPI + 3% real with CPI + 1.5% as the optimistic sensitivity; verified_on 2026-08-19. That is a
     * reviewer's judgement, NOT a published series, so a primary citation is still owed (board
     * card 0085). See docs/spec/ASSUMPTIONS.md §12.
     */
    public const DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS = 300; // CPI + 3.00% a year

    /** The essential floor as an age-varying real path — its first band is $essentialAnnualSpend. */
    public readonly SpendPath $essentialSpendPath;

    /** The discretionary spend as an age-varying real path — its first band is $discretionaryAnnualSpend. */
    public readonly SpendPath $discretionarySpendPath;

    /**
     * @param  list<array{atAge: int, amount: Money, label: string, condition?: string}>  $oneOffCosts
     *                                                                                                  An optional `condition` of `while_owning_home` makes the lump a liability of OWNING the
     *                                                                                                  current home (a Section 20 major-works demand on a block), so it dies with that home
     *                                                                                                  exactly as the service charge does. Absent = charged always, whatever happens to the home.
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
        /**
         * The REAL (above-inflation) annual growth of the $propertyCosts bucket: service
         * charges and levies have outpaced CPI sector-wide (insurance, building safety), so a
         * leaseholder models "CPI + x%" on exactly those lines. The projector compounds it
         * per projection year on top of the CPI all spend rides. Applies ONLY to $propertyCosts
         * (a mortgage payment is contractual and does not escalate with it).
         *
         * **Null is not zero.** It means "no figure given", and the reader is then charged
         * {@see DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS}. Pass an explicit Percent::zero() to say
         * the bucket really does track CPI. Read it through {@see propertyCostsRealGrowth()},
         * never off this property, or the default is silently skipped.
         */
        public readonly ?Percent $propertyCostsRealGrowth = null,
        /**
         * The part of $propertyCosts that BUYS UTILITIES — the water, and the communal or
         * whole-flat electricity and heating, a block service charge commonly includes.
         *
         * It is a marked subset of $propertyCosts (never an addition), and it is the one part of
         * the charge that does NOT die with the home: a house, a bungalow or a park home still has
         * to be heated and plumbed, so {@see withoutPropertyCosts} carries it across the sale as
         * ordinary always-charged essential spend. Without it every sell and every buy plan was
         * cheaper than it can really be, by whatever the charge was buying.
         *
         * It is the reader's own figure — the engine never invents one — so null means the charge
         * buys no utilities and the whole thing stops with the home, exactly as before. Read it
         * through {@see propertyCostsUtilities()}, which clamps it to the bucket it comes out of.
         */
        public readonly ?Money $propertyCostsUtilities = null,
        /**
         * The household's state-dependent spending rule: cut discretionary spend while the plan is
         * underfunded, restore it when it recovers ({@see SpendingGuardrail}, board card 0063).
         *
         * Null means no guardrail, which is the pre-card behaviour: the household spends the same
         * in real terms whatever happens. It is opt-in because a guardrail only ever makes a plan
         * look better, so the adverse default is the one that does not have it.
         */
        public readonly ?SpendingGuardrail $spendingGuardrail = null,
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

    /**
     * The utilities bought by the housing-linked costs — the part of the service charge that
     * survives a sale (zero if none). Clamped to the bucket it is a subset of, so a mis-entered
     * figure larger than the charge can never ADD spend when the home goes.
     */
    public function propertyCostsUtilities(): Money
    {
        $utilities = $this->propertyCostsUtilities ?? Money::zero();

        return $utilities->greaterThan($this->propertyCosts()) ? $this->propertyCosts() : $utilities;
    }

    /** The employment-linked contingent costs (zero if none). */
    public function employmentCosts(): Money
    {
        return $this->employmentCosts ?? Money::zero();
    }

    /**
     * The real (above-inflation) growth of the property-costs bucket.
     *
     * An explicit rate, INCLUDING an explicit zero, is the reader's own figure and wins. A blank
     * one takes {@see DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS}, which the presenter discloses, but
     * only where there is a bucket to grow: no service charge, no escalation, no invented spend.
     */
    public function propertyCostsRealGrowth(): Percent
    {
        if ($this->propertyCostsRealGrowth !== null) {
            return $this->propertyCostsRealGrowth;
        }

        return $this->propertyCosts()->isPositive()
            ? Percent::fromBasisPoints(self::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS)
            : Percent::zero();
    }

    /** True when the growth above is a figure the ENGINE supplied, so a presenter must disclose it. */
    public function propertyCostsGrowthIsAssumed(): bool
    {
        return $this->propertyCostsRealGrowth === null && $this->propertyCosts()->isPositive();
    }

    /**
     * The same profile with the current home's housing-linked costs removed — for the
     * buy/rent variants, where that home is sold. That means BOTH the ownership costs
     * (service charge / ground rent) AND the mortgage payment: a sold home pays neither.
     * The costs are essential by nature and flat, so they come out of every band of the
     * essential path (capped at zero); the discretionary path is unchanged.
     *
     * The dated lumps marked `while_owning_home` go with them. A major-works demand is a
     * liability of owning the flat, so a plan that sold it in year 0 must not be charged for a
     * building it never owned. That is the same rule as the service charge, applied to those costs
     * in their lumpy form.
     *
     * The ONE thing that does not go is {@see $propertyCostsUtilities}: a service charge that
     * bought the water and the electricity was buying something the household needs wherever it
     * lives, so that part stays in the essential floor as ordinary always-charged spend. It keeps
     * no marker afterwards, because nothing may strip it a second time and no service-charge
     * escalator belongs on an energy bill.
     */
    public function withoutPropertyCosts(): self
    {
        $gross = $this->propertyCosts()->plus($this->mortgageCosts());
        $oneOffs = array_values(array_filter(
            $this->oneOffCosts,
            static fn (array $cost): bool => ($cost['condition'] ?? null) !== 'while_owning_home',
        ));

        // The guard reads the GROSS buckets, not what is left after the utilities are kept back:
        // a charge that is entirely utilities nets to nothing to remove, and returning $this there
        // would leave the sold home's marker (and its escalator) on a plan that has no home.
        if (! $gross->isPositive() && count($oneOffs) === count($this->oneOffCosts)) {
            return $this;
        }

        $essentialPath = $this->essentialSpendPath->minusFlat($gross->minus($this->propertyCostsUtilities()));

        return $this->copy([
            'essentialAnnualSpend' => $essentialPath->startAmount(),
            'essentialSpendPath' => $essentialPath,
            'oneOffCosts' => $oneOffs,
            'propertyCosts' => null,
            'mortgageCosts' => null,
            // The utilities part is now ordinary essential spend inside the path above, so its
            // marker must go: nothing may strip it a second time, and no service-charge escalator
            // belongs on an energy bill. The escalation RATE is carried through instead: it is inert
            // with no bucket to grow, and dropping it was the silent drift this wither had made.
            'propertyCostsUtilities' => null,
        ]);
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

        return $this->copy([
            'essentialAnnualSpend' => $essentialPath->startAmount(),
            'essentialSpendPath' => $essentialPath,
            'mortgageCosts' => $mortgageCosts,
        ]);
    }

    /**
     * The same profile with a dated one-off cost appended — e.g. the unfunded part of a home
     * purchase, charged in the year it falls so money the plan does not have is never conjured
     * (the year shows a visible shortfall instead). Every other field is preserved, as it is by
     * every wither now that they share {@see copy}.
     */
    public function withOneOffCost(int $atAge, Money $amount, string $label): self
    {
        $oneOffs = $this->oneOffCosts;
        $oneOffs[] = ['atAge' => $atAge, 'amount' => $amount, 'label' => $label];

        return $this->copy(['oneOffCosts' => $oneOffs]);
    }

    /**
     * The ONE place an ExpenseProfile is rebuilt from an existing one. Every wither goes through
     * here, so a field added to this DTO cannot be silently dropped by a wither that listed the
     * constructor arguments by hand and was never updated — which is exactly how a carefully
     * entered input disappears from a variant's forecast. Three withers used to hand-list nine
     * arguments each, and one of them had already lost a field. Guarded by
     * `ExpenseProfileWitherTest`.
     *
     * Keyed by field name rather than typed parameters, because two of the withers must SET a
     * field to null (a sold home has no service charge) and a nullable parameter cannot tell
     * "leave it alone" from "make it null". A field absent from $changes is carried through.
     *
     * @param  array<string, mixed>  $changes  field name => its new value
     */
    private function copy(array $changes): self
    {
        $take = fn (string $field): mixed => array_key_exists($field, $changes) ? $changes[$field] : $this->{$field};

        return new self(
            essentialAnnualSpend: $take('essentialAnnualSpend'),
            discretionaryAnnualSpend: $take('discretionaryAnnualSpend'),
            survivorSpendFactor: $take('survivorSpendFactor'),
            oneOffCosts: $take('oneOffCosts'),
            propertyCosts: $take('propertyCosts'),
            employmentCosts: $take('employmentCosts'),
            mortgageCosts: $take('mortgageCosts'),
            essentialSpendPath: $take('essentialSpendPath'),
            discretionarySpendPath: $take('discretionarySpendPath'),
            propertyCostsRealGrowth: $take('propertyCostsRealGrowth'),
            propertyCostsUtilities: $take('propertyCostsUtilities'),
            spendingGuardrail: $take('spendingGuardrail'),
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
