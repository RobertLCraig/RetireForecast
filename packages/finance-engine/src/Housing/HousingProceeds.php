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
 * exactly, so a UI can show the breakdown and it reconciles to the total by construction.
 */
final class HousingProceeds
{
    /** The engine default cost of selling a home when the user enters none: 2% of the sale price. */
    public const DEFAULT_SELLING_COST_RATE_BP = 200;

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
            );
            $cgt = $cgtResult->tax;
        }

        // The household's share of each figure, so the waterfall (price − mortgage − costs − CGT) reconciles.
        $salePrice = $scale($salePriceWhole);
        $mortgage = $scale($mortgageWhole);
        $sellingCosts = $scale($sellingCostsWhole);
        $breakdown = array_map(fn (array $b): array => ['label' => $b['label'], 'amount' => $scale($b['amount'])], $breakdownWhole);

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
