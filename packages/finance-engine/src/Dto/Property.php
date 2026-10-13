<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Benefits\CouncilTax;
use RetireForecast\FinanceEngine\Housing\HousingProceeds;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A property the household owns. The main residence is exempt from CGT (Private
 * Residence Relief) and from means-tested-benefit capital while occupied; selling
 * it is what converts that exempt value into assessable capital.
 *
 * $everLet flags a past letting period that restricts PRR. $runningCosts is the
 * annual maintenance + insurance used in the buy-vs-rent comparison.
 * $ownershipShare null means wholly owned (100%).
 *
 * $annualCouncilTax is the household's council tax bill, held APART from $runningCosts because
 * it is the one running cost that shrinks: a single occupant gets 25% off automatically, a
 * household on or near the Pension Credit line gets Council Tax Reduction, and a qualifying
 * disabled resident is charged a band lower. Bundled in with maintenance and insurance none of
 * those could apply, so a survivor was charged a couple's council tax for the rest of their life
 * ({@see CouncilTax}, board card 0047). Null means it is
 * still inside $runningCosts, which is how every scenario stored before this behaved: the bill is
 * charged in full, with no discount and no reduction, and the result says so.
 *
 * Unlike the other running costs it is NOT scaled by $ownershipShare. Council tax is charged to
 * the people who LIVE in the dwelling, not to its owners in proportion — someone owning a third
 * of the home they live in pays the whole bill, and it is exactly that occupancy that the
 * single-person discount turns on.
 *
 * $disabledBandReduction is null for the ordinary case and otherwise the band the dwelling is
 * actually in, which is what says the disabled band reduction applies: the bill is then charged
 * as the band below. Holding the band here rather than beside a separate yes/no flag makes the
 * invalid state — "the reduction applies, but from which band?" — unrepresentable. The reduction
 * is NOT means-tested: it needs only a qualifying feature (an extra bathroom, a room used for the
 * disabled person's needs, or space to use a wheelchair indoors).
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
 * $occupiedByQualifyingRelative says that somebody the CARE means test gives a MANDATORY property
 * disregard to lives in this home. The disregard is not limited to a surviving spouse: the local
 * authority must disregard the home while it is occupied by the resident's spouse or civil
 * partner, by a relative aged 60 or over, by an incapacitated relative of any age, or by a child
 * of the resident under 18. (A carer who gave up their own home to move in is a DISCRETIONARY
 * disregard, which this engine does not claim.) The engine already shields the home while a
 * partner is alive and living there, so this flag is what covers everyone else on that list —
 * board card 0055, before which a household with a resident older relative was told to fund care
 * out of a home no authority could have charged against. Default false is the adverse answer:
 * nobody qualifies, so the home counts.
 *
 * It says nothing about Pension Credit or Housing Benefit, whose capital rules are their own, and
 * it does not apply to a LET property ($isLet) — the disregard is for a home somebody occupies.
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
 *
 * $lettingManagementRate, $lettingVoidRate and $lettingMaintenanceRate are what letting the
 * property COSTS, each as a percentage of gross rent, and each applying only when $isLet. Null
 * means "no figure given" and takes the shipped default below; an explicit rate, INCLUDING an
 * explicit zero, is the reader's own and wins. Read them through their accessors, never off these
 * properties, or the default is silently skipped.
 *
 * $isChattelDwelling says this home is a CHATTEL standing on somebody else's land (a park home,
 * a mobile home, a houseboat) rather than an interest in a dwelling-house. The owner holds the
 * unit plus a pitch agreement, and that difference changes two things nothing else in this engine
 * could see (board card 0059): no residence nil-rate band ({@see qualifiesForRnrb}), and a site
 * owner's commission on every resale ({@see saleCommissionRate}). NULL means nobody was asked, and
 * is then DERIVED from whether the home is modelled as losing value: a depreciating home is a park
 * home in all but name, and reading it that way is the adverse answer, which is the standing rule
 * where a figure is not stated. An explicit answer, including an explicit false on a depreciating
 * home such as a leasehold flat in a falling market, is the reader's own and wins.
 *
 * $beneficialShares is who owns how much of the home, keyed by person id, and it is what the FIRST
 * death values: an estate carries the deceased's own share, not half by assumption. Null (the
 * default) means nobody said, and the shares are then EQUAL between the household's members, which
 * is what the engine did unconditionally before card 0059 and is the presumption for a joint
 * tenancy. The default is disclosed rather than silent. It is immaterial while a couple is married
 * (the first death is spouse-exempt) and material the moment they are not.
 *
 * $repaymentTerms models the third mortgage shape — an ordinary capital-and-interest
 * ("repayment") mortgage that AMORTISES $outstandingMortgage to zero over a term
 * ({@see RepaymentMortgageTerms}). When set, the engine owns both the balance and the payment:
 * the balance follows the amortisation schedule, and the fixed-nominal instalment REPLACES the
 * "Mortgage" expense line (which is dropped, so the two can never double-count). Null (the
 * default) leaves the pre-existing behaviour untouched — a static or rolled-up balance whose
 * payment, if any, is the expense line. Mutually exclusive with $mortgageRollUpRate: a loan
 * cannot both amortise and roll up.
 */
final class Property
{
    /**
     * What a fully managed single let COSTS, as a percentage of gross rent, where the reader gives
     * no figure of their own: **18% management, 8% void, 8% maintenance, about a third of the
     * rent before any tax.**
     *
     * Gross rent is the one figure a landlord never receives. A letting agent's fully managed fee
     * is 10% to 15% plus VAT, shipped at the top; the flat stands empty between tenancies, which on a single property
     * is roughly a month a year; and repairs, an inventory, the annual gas safety certificate and
     * the five-yearly electrical report are all the landlord's, not the tenant's. Modelling the
     * rent gross does not merely flatter a let plan, it can invert its sign: the reviewer's
     * arithmetic turned a modelled positive contribution into a real cash loss.
     *
     * The void is lost rent rather than a bill, but the arithmetic is the same (it never arrives,
     * and it is never taxed), so all three come off gross rent together.
     *
     * **Adverse by rule, editable by design** (Rob's standing default rule): where several figures
     * are defensible the shipped one is the most adverse, and it is exposed as a user input. A
     * landlord who self-manages sets management to zero; one letting to a long-term tenant sets a
     * lower void.
     *
     * **PUBLIC so a presenter can DISCLOSE each figure without restating it**, the
     * no-invisible-figures rule.
     *
     * **Sources (card 0087, verified_on 2026-10-11).** No primary government or ARLA Propertymark
     * series exists for any of the three (the English Private Landlord Survey 2024 publishes none),
     * so each is a fetchable secondary source:
     *  - management: https://www.buildington.co.uk/blog/letting-agent-vs-self-managing-a-2026-guide-for-landlords
     *    (10% to 15% plus VAT; 15% + VAT = 18%);
     *  - void: https://www.propertyreporter.co.uk/englands-average-void-period-falls-to-21-days-as-landlords-adapt-to-rra.html
     *    (21 to 24 days per void in 2026; a month a year sits above it);
     *  - maintenance: https://www.mortgagesolutions.co.uk/mortgage-news/2026/01/06/landlords-income-squeezed-by-rising-repair-and-maintenance-costs/
     *    (repairs 31% to 39% of running costs that are 25% to 45% of rent; 8% is the low end of
     *    that DERIVED range, rounded up, not a published rate).
     * See docs/spec/ASSUMPTIONS.md §14 and docs/research/RESEARCH-0087-letting-cost-rates.md.
     */
    public const DEFAULT_LETTING_MANAGEMENT_BPS = 1_800; // 18.00% of gross rent, VAT included

    public const DEFAULT_LETTING_VOID_BPS = 800;         // 8.00% of gross rent

    public const DEFAULT_LETTING_MAINTENANCE_BPS = 800;  // 8.00% of gross rent

    /**
     * What the SITE OWNER takes out of the price when a park home changes hands: **10% of the sale
     * price**, the statutory maximum, and in practice what is always charged.
     *
     * It matters to a forecast because the exit is not optional. A park home is sold or given up
     * eventually, on a move into care or by the estate, so the commission is a cost the plan
     * will meet, and a terminal value that ignores it overstates what the household actually has by
     * a tenth of the home. The home is also far less liquid than the figure reads: the site owner's
     * approval of the buyer stands between the seller and the price.
     *
     * **PUBLIC so a presenter can DISCLOSE the rate without restating it**, the no-invisible-figures
     * rule.
     *
     * source: Mobile Homes Act 1983 Sch.1 Pt.1 para 25 and the Mobile Homes (Commission) Order 1983
     * (SI 1983/748), which set the maximum commission on a sale at 10 per cent of the price,
     * https://www.legislation.gov.uk/ukpga/1983/34/schedule/1
     * verified_on: NOT VERIFIED. This build had no web access, so the rate and the instrument are
     * STATED from the legislation, not checked against a live page. See docs/spec/ASSUMPTIONS.md
     * §31 and board card 0135.
     */
    public const MAX_SITE_COMMISSION_BPS = 1_000; // 10.00% of the sale price

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
        public readonly ?RepaymentMortgageTerms $repaymentTerms = null,
        public readonly ?Percent $lettingManagementRate = null,
        public readonly ?Percent $lettingVoidRate = null,
        public readonly ?Percent $lettingMaintenanceRate = null,
        public readonly ?Money $annualCouncilTax = null,
        public readonly ?CouncilTaxBand $disabledBandReduction = null,
        public readonly bool $occupiedByQualifyingRelative = false,
        public readonly ?bool $isChattelDwelling = null,
        /** @var array<string, Percent>|null who owns how much, keyed by person id; null = equal */
        public readonly ?array $beneficialShares = null,
    ) {
        if ($repaymentTerms !== null && $mortgageRollUpRate !== null) {
            throw new \InvalidArgumentException('A mortgage cannot both amortise (repaymentTerms) and roll up (mortgageRollUpRate) — choose one.');
        }
    }

    /**
     * The same property at a different value (immutable) — the capacity-for-loss stress, which
     * marks the home down while the debt secured on it stays exactly where it is. Every other
     * field is carried through by name; a field added above and forgotten here would silently
     * vanish from a stressed forecast, which `AssetWitherTest` fails on.
     */
    public function withCurrentValue(Money $currentValue): self
    {
        return new self(
            currentValue: $currentValue,
            ownership: $this->ownership,
            isPrimaryResidence: $this->isPrimaryResidence,
            everLet: $this->everLet,
            outstandingMortgage: $this->outstandingMortgage,
            runningCosts: $this->runningCosts,
            growthAssumptionOverride: $this->growthAssumptionOverride,
            ownershipShare: $this->ownershipShare,
            cgtHistory: $this->cgtHistory,
            mortgageRedemptionYear: $this->mortgageRedemptionYear,
            mortgageMaturityAction: $this->mortgageMaturityAction,
            isLet: $this->isLet,
            mortgageRollUpRate: $this->mortgageRollUpRate,
            mortgageOverpaymentAnnual: $this->mortgageOverpaymentAnnual,
            repaymentTerms: $this->repaymentTerms,
            lettingManagementRate: $this->lettingManagementRate,
            lettingVoidRate: $this->lettingVoidRate,
            lettingMaintenanceRate: $this->lettingMaintenanceRate,
            annualCouncilTax: $this->annualCouncilTax,
            disabledBandReduction: $this->disabledBandReduction,
            occupiedByQualifyingRelative: $this->occupiedByQualifyingRelative,
            isChattelDwelling: $this->isChattelDwelling,
            beneficialShares: $this->beneficialShares,
        );
    }

    /**
     * Is this home a chattel on somebody else's pitch rather than an interest in land? The
     * reader's own answer where they gave one; otherwise DERIVED from the home being modelled as
     * losing value, which is how a park home is entered here and nothing else is.
     */
    public function isChattelDwelling(): bool
    {
        return $this->isChattelDwelling ?? $this->depreciates();
    }

    /**
     * Can the residence nil-rate band be claimed against this home?
     *
     * The band needs a "qualifying residential interest", an interest in a DWELLING-HOUSE that
     * was the deceased's residence. A park-home owner owns a chattel plus the right to keep it on
     * a pitch, which is not an interest in the land, so the band is at best unsafe and most likely
     * unavailable. Claiming it anyway would understate the tax on exactly the estate this tool
     * reports, so it is refused. The DOWNSIZING ADDITION is the one route back where a real house
     * was sold to buy the park home, and that is decided by the disposal, not here.
     */
    public function qualifiesForRnrb(): bool
    {
        return ! $this->isChattelDwelling();
    }

    /** What the site owner takes on a resale: {@see MAX_SITE_COMMISSION_BPS}, or nothing at all. */
    public function saleCommissionRate(): Percent
    {
        return $this->isChattelDwelling()
            ? Percent::fromBasisPoints(self::MAX_SITE_COMMISSION_BPS)
            : Percent::zero();
    }

    /**
     * A gross value of this home less the commission the site owner takes when it is sold, for a
     * home that is VALUED rather than sold: the estate and the care means test. An ordinary house is
     * returned untouched. A modelled sale charges the same {@see saleCommissionRate} as an itemised
     * selling cost inside {@see HousingProceeds::compute()}.
     */
    public function netOfSaleCommission(Money $gross): Money
    {
        return $gross->minus($gross->applyRate($this->saleCommissionRate()));
    }

    /**
     * One person's beneficial share of this home. The reader's own figure where they gave one;
     * otherwise an EQUAL share between $ownerCount owners, which is the presumption for a joint
     * tenancy and is what this engine did unconditionally before board card 0059.
     */
    public function beneficialShare(string $personId, int $ownerCount): Percent
    {
        $stated = $this->beneficialShares[$personId] ?? null;
        if ($stated !== null) {
            return $stated;
        }

        return Percent::fromBasisPoints((int) round(10_000 / max(1, $ownerCount)));
    }

    /**
     * Each of $ids' claim on this home, as weights in basis points for {@see PenceSplit::byWeight}
     * (board card 0102): the sale proceeds and the care assessment divide the home by these. Only
     * the owners passed in are weighed, so a dead owner's share falls to the living one, who
     * inherited it. Equal where nobody said, so an unstated split reproduces the old even one, and
     * equal too where the living owners' stated shares are all nil (a survivor who owned none of
     * it before the death owns all of it after).
     *
     * @param  list<string>  $ids
     * @return array<string, int> id => basis points
     */
    public function shareWeights(array $ids): array
    {
        $weights = [];
        foreach ($ids as $id) {
            $weights[$id] = $this->beneficialShare($id, count($ids))->basisPoints;
        }

        return array_sum($weights) > 0 ? $weights : array_fill_keys($ids, 1);
    }

    /** Is the home modelled as LOSING value in real terms? */
    private function depreciates(): bool
    {
        return $this->growthAssumptionOverride !== null
            && $this->growthAssumptionOverride->basisPoints < 0;
    }

    /** The letting agent's fully managed fee, VAT included (zero unless the property is let). */
    public function lettingManagementRate(): Percent
    {
        return $this->lettingRate($this->lettingManagementRate, self::DEFAULT_LETTING_MANAGEMENT_BPS);
    }

    /** The share of the year the property stands empty between tenancies (zero unless let). */
    public function lettingVoidRate(): Percent
    {
        return $this->lettingRate($this->lettingVoidRate, self::DEFAULT_LETTING_VOID_BPS);
    }

    /**
     * Repairs, inventory, gas safety and electrical checks, as a share of rent (zero unless let).
     *
     * Zero as well where the reader entered running costs, which already carry the repairs: the
     * rate yields to the reader's own figure, stated or not, so the repairs are charged once
     * (board card 0088). The result says so, naming the rate set aside.
     */
    public function lettingMaintenanceRate(): Percent
    {
        if ($this->repairsInRunningCosts()) {
            return Percent::zero();
        }

        return $this->lettingRate($this->lettingMaintenanceRate, self::DEFAULT_LETTING_MAINTENANCE_BPS);
    }

    /** Is this a let home whose repairs are carried by its running costs rather than the rate? */
    public function repairsInRunningCosts(): bool
    {
        return $this->isLet && $this->runningCosts !== null && $this->runningCosts->isPositive();
    }

    /**
     * The whole cost of letting, as a share of gross rent: management plus void plus maintenance.
     * Zero for a home the household lives in, so a residence is untouched by any of this.
     */
    public function lettingCostRate(): Percent
    {
        return Percent::fromBasisPoints(
            $this->lettingManagementRate()->basisPoints
            + $this->lettingVoidRate()->basisPoints
            + $this->lettingMaintenanceRate()->basisPoints,
        );
    }

    /**
     * The letting-cost rates the ENGINE supplied because the reader left them blank, keyed by
     * which cost they are. Empty when the property is not let, or when every rate is the reader's
     * own. This is what the no-invisible-figures disclosure enumerates, so it names only the
     * figures nobody entered.
     *
     * @return array<string, Percent> one of `management`, `void`, `maintenance` => the rate applied
     */
    public function assumedLettingRates(): array
    {
        if (! $this->isLet) {
            return [];
        }

        return array_filter([
            'management' => $this->lettingManagementRate === null ? $this->lettingManagementRate() : null,
            'void' => $this->lettingVoidRate === null ? $this->lettingVoidRate() : null,
            'maintenance' => $this->lettingMaintenanceRate === null && ! $this->repairsInRunningCosts() ? $this->lettingMaintenanceRate() : null,
        ], static fn (?Percent $rate): bool => $rate !== null);
    }

    /** A let property's rate: the reader's own where given (zero included), else the default. */
    private function lettingRate(?Percent $given, int $defaultBps): Percent
    {
        if (! $this->isLet) {
            return Percent::zero();
        }

        return $given ?? Percent::fromBasisPoints($defaultBps);
    }
}
