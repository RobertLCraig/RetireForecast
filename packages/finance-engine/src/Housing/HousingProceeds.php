<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Dto\CgtHistory;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Property\CgtPrivateResidenceCalculator;
use RetireForecast\FinanceEngine\Property\CgtResult;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * The decomposition of a home sale into the cash the household actually keeps. Net
 * proceeds are what is left after the costs of selling:
 *
 *   netProceeds = max(0, salePrice − outstandingMortgage − sellingCosts − capitalGainsTax)
 *
 * Holding every part beside the total is what makes the headline figure reconcilable:
 * whenever the sale clears its costs, salePrice == netProceeds + outstandingMortgage +
 * sellingCosts + capitalGainsTax exactly (the floor only bites in negative equity, where
 * there is simply nothing to keep). capitalGainsTax is £0 in v1 — the main home is fully
 * relieved by Private Residence Relief. This is the single source for the proceeds figure:
 * the buy/rent variants and any UI breakdown read it, so the parts can never drift from
 * the total they sum to.
 *
 * $sellingCostBreakdown decomposes $sellingCosts into its named components (estate agent,
 * legal/conveyancing, ...), each already resolved to £; their amounts sum to $sellingCosts
 * exactly, so a UI can show the breakdown and it reconciles to the total by construction. Most
 * lines are the reader's own; the engine appends one of its own — the 60-day capital-gains return
 * — on a disposal that owes CGT, because that cost is decided by the tax rather than entered.
 */
final class HousingProceeds
{
    /**
     * The engine's ALL-IN cost of selling when the reader itemises nothing: **4% of the sale
     * price**, covering the agent, leasehold conveyancing, the managing agent's pack, the licence
     * to assign and the notice fees, the energy certificate and the removal van.
     *
     * It was 2%, which is an estate agent's fee and very little else — a freehold-house figure. A
     * leasehold flat pays for three things a house does not (a management pack the buyer's
     * solicitor cannot exchange without, a licence to assign, and notice of transfer / deed of
     * covenant fees), its conveyancing is materially dearer for the same reason, and the household
     * still has to physically move. Selling costs come straight off the net proceeds, and the net
     * proceeds are what the whole buy-versus-rent comparison is built on, so understating them by
     * half flatters every sell plan by real money.
     *
     * **Adverse by rule, editable by design** (Rob's standing default rule): this is the catch-all
     * for a sale nobody itemised. A reader who has real quotes enters them as
     * {@see SellingCostComponent} lines instead, and those always win — the builder ships an
     * itemised set, so this rate is what a scenario built any other way falls back to.
     *
     * **PUBLIC so a presenter can DISCLOSE the figure without restating it**, the
     * no-invisible-figures rule.
     *
     * **Source of record: the expert property review of 2026-08-19** (board card 0032), which puts
     * a realistic all-in figure for a leasehold sale plus a move "nearer 4%"; verified_on
     * 2026-08-19. That is a reviewer's judgement, NOT a published series, so a primary citation is
     * still owed. See docs/spec/ASSUMPTIONS.md §16.
     */
    public const DEFAULT_SELLING_COST_RATE_BP = 400;

    /** The label the 60-day capital-gains return is itemised under, so no caller restates it. */
    public const CGT_RETURN_LABEL = 'Capital gains return (60-day)';

    /**
     * What preparing the 60-day UK property capital-gains return costs: **£750**, charged only on
     * a disposal that actually owes CGT.
     *
     * A UK residential disposal on which tax is due must be reported and PAID within 60 days of
     * completion, on a separate return outside the normal self-assessment cycle. It is a statutory
     * obligation with a penalty regime behind it, and the computation (part-year Private Residence
     * Relief, the annual exempt amount, the rate that depends on other income) is not something a
     * household does for itself. So it is a real, unavoidable cost of that sale, and the model
     * charged nothing for it.
     *
     * It is deliberately NOT deducted from the gain. The incidental costs of disposal allowed
     * against a gain (TCGA 1992 s.38) are the costs of making the sale — the agent, the solicitor,
     * advertising — and the cost of COMPUTING the resulting tax is not one of them. Excluding it is
     * also what keeps the charge from being circular, since whether the fee applies is decided by
     * the tax.
     *
     * **Adverse by rule, editable by design**: an accountant's fee for a single 60-day return runs
     * roughly £300 to £1,000 depending on how tangled the ownership history is, and a partial-PRR
     * computation is the tangled end. A reader with a real quote enters it as an ordinary
     * {@see SellingCostComponent} line.
     *
     * **SOURCING GAP:** unlike the 60-day deadline itself, this figure is not cited to a published
     * fee survey — the unattended build loop has no web access. Board card 0092 carries pinning it.
     * See docs/spec/ASSUMPTIONS.md §16.
     */
    public const CGT_RETURN_FEE_PENCE = 750_00;

    /**
     * @param  list<array{label: string, amount: Money}>  $sellingCostBreakdown
     */
    public function __construct(
        public readonly Money $salePrice,
        public readonly Money $outstandingMortgage,
        public readonly Money $sellingCosts,
        public readonly Money $capitalGainsTax,
        public readonly Money $netProceeds,
        public readonly array $sellingCostBreakdown = [],
        public readonly ?CgtResult $capitalGainsDetail = null,
    ) {}

    /**
     * Decompose a home sale into net proceeds and the costs netted off it, reconciled by
     * construction (the parts sum to the sale price whenever it clears its costs). This is the
     * ONE definition of the sale maths: {@see HousingComparison::saleProceeds} runs it on the
     * year-0 sale price, and {@see PathProjector} runs it
     * on the grown, redemption-year value for an in-projection forced sale, so the two can never
     * drift.
     *
     * Whole-property figures are passed ($salePriceWhole, $mortgageWhole); the household owns a
     * beneficial share ($ownershipShare, null = wholly owned), and HMRC apportions both the gain
     * and the sale proceeds by that share, so the household receives its share of
     * (price − mortgage − costs) and is taxed on its share of the gain. Scaling by the share
     * leaves the wholly-owned case untouched. CGT is £0 for a main home owned and lived in
     * throughout (full Private Residence Relief — the common case, and the default when no
     * $cgtHistory is given); a $cgtHistory drives a partial-PRR charge on the household's share
     * of the gain, split across its owners.
     *
     * @param  list<SellingCostComponent>|null  $components  null → the engine default rate
     */
    public static function compute(
        Money $salePriceWhole,
        Money $mortgageWhole,
        ?array $components,
        ?CgtHistory $cgtHistory,
        ?Percent $ownershipShare,
        TaxYearConfig $config,
    ): self {
        // Each selling-cost component resolves to £ against the sale price (a % of it, or a flat
        // fee). The total is their sum; the breakdown is carried so a UI can show it and it
        // reconciles to the total by construction. No components → the engine default rate.
        $components ??= [new SellingCostComponent('Selling costs', Percent::fromBasisPoints(self::DEFAULT_SELLING_COST_RATE_BP))];

        $sellingCostsWhole = Money::zero();
        $breakdownWhole = [];
        foreach ($components as $component) {
            $amount = $component->amount($salePriceWhole);
            $sellingCostsWhole = $sellingCostsWhole->plus($amount);
            $breakdownWhole[] = ['label' => $component->label, 'amount' => $amount];
        }

        // The household owns a beneficial share of the home (tenants in common); null = wholly owned.
        $scale = fn (Money $m): Money => $ownershipShare === null ? $m : $m->applyRate($ownershipShare);

        $cgtResult = null;
        $cgt = Money::zero();
        if ($cgtHistory !== null) {
            $gain = $scale($salePriceWhole
                ->minus($cgtHistory->purchasePrice)
                ->minus($cgtHistory->improvementCosts)
                ->minus($sellingCostsWhole)
                ->minZero());
            $cgtResult = (new CgtPrivateResidenceCalculator($config))->compute(
                $gain,
                $cgtHistory->ownershipMonths,
                $cgtHistory->mainResidenceMonths,
                $cgtHistory->higherRateOnSale,
                $cgtHistory->owners,
                $cgtHistory->absenceAnyReasonMonths,
                $cgtHistory->absenceWorkElsewhereUkMonths,
                $cgtHistory->absenceWorkAbroadMonths,
            );
            $cgt = $cgtResult->tax;
        }

        // The household's share of each figure, so the waterfall (price − mortgage − costs − CGT) reconciles.
        $salePrice = $scale($salePriceWhole);
        $mortgage = $scale($mortgageWhole);
        $sellingCosts = $scale($sellingCostsWhole);
        $breakdown = array_map(fn (array $b): array => ['label' => $b['label'], 'amount' => $scale($b['amount'])], $breakdownWhole);

        // A disposal that actually owes CGT has to be reported and paid inside 60 days, on its own
        // return, and somebody has to prepare it. Charged AFTER the gain is computed and outside
        // the whole-property scaling: it is a household's accountancy bill, not a share of a cost
        // the co-owners split, and it is not an allowable deduction from the gain (see the
        // constant). Appending it here rather than to $components is what keeps it out of the
        // gain — and therefore out of its own trigger.
        if ($cgt->isPositive()) {
            $fee = Money::fromPence(self::CGT_RETURN_FEE_PENCE);
            $sellingCosts = $sellingCosts->plus($fee);
            $breakdown[] = ['label' => self::CGT_RETURN_LABEL, 'amount' => $fee];
        }

        $netProceeds = $salePrice->minus($mortgage)->minus($sellingCosts)->minus($cgt)->minZero();

        return new self($salePrice, $mortgage, $sellingCosts, $cgt, $netProceeds, $breakdown, $cgtResult);
    }

    /** True when the sale cleared its costs, so the parts sum exactly to the sale price. */
    public function clearsCosts(): bool
    {
        return $this->salePrice->pence >= $this->outstandingMortgage->pence
            + $this->sellingCosts->pence
            + $this->capitalGainsTax->pence;
    }
}
