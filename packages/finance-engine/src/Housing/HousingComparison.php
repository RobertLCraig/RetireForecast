<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\CgtHistory;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\OwnershipType;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\Property\CgtPrivateResidenceCalculator;
use RetireForecast\FinanceEngine\Property\SdltCalculator;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Compares the household's housing options on identical Monte Carlo paths (same
 * seed), so any difference in the outcome is down to the housing choice alone:
 *
 *  - stay_put:     keep the current home.
 *  - buy_outright: sell, buy a cheaper home, invest the surplus.
 *  - rent:         sell, invest all the proceeds, pay rent for life.
 *
 * Net sale proceeds = sale price − outstanding mortgage − selling costs − CGT.
 * CGT is £0 on a main home owned and lived in throughout (full Private Residence
 * Relief); when the home was let / not the main residence for part of ownership, a
 * {@see CgtHistory} drives a partial-PRR charge
 * ({@see CgtPrivateResidenceCalculator}). Buying nets off SDLT and moving costs; the
 * surplus (or full proceeds when renting) goes into an invested account that then
 * follows the chosen allocation.
 *
 * v1 simplifications (documented): the additional-property SDLT surcharge is not applied
 * (a straight replacement of the main residence); a buy price above the net proceeds is
 * not modelled (downsizing is assumed).
 */
final class HousingComparison
{
    private const DEFAULT_MOVING_COSTS_PENCE = 200_000; // £2,000

    /**
     * The standard annual home-maintenance rate applied to a BOUGHT freehold home that has no
     * explicit running-cost basis — 1% of the home's value a year, the widely-used UK rule of
     * thumb (Checkatrade 2023: homeowners spent on average ~1% of property value a year on
     * maintenance; newer homes ~1%, older 1.5-4%, so 1% is conservative). It stops a bought home
     * being modelled with zero upkeep when the current home is a leasehold flat whose building
     * maintenance sat inside its service charge (empty runningCosts). A DEFAULT only: a current
     * home with its own runningCosts scales those instead, and a real chosen property's actual
     * costs would override it. verified_on 2026-07-08.
     */
    private const HOME_MAINTENANCE_RATE_BPS = 100; // 1.00% of value a year

    public function __construct(
        private readonly TaxYearConfig $config,
        private readonly CohortLifeTable $lifeTable,
    ) {}

    /**
     * @param  (callable(float $fraction): void)|null  $onProgress
     *                                                              Optional progress hook called with the overall fraction complete (0..1)
     *                                                              across the three variants. Throwing from it aborts the comparison.
     * @return array{stay_put: SimulationResult, buy_outright: SimulationResult, rent: SimulationResult}
     */
    public function compare(
        Household $household,
        ForecastSettings $settings,
        AssumptionSet $assumptions,
        HousingAction $action,
        int $nPaths,
        int $seed,
        ?callable $onProgress = null,
    ): array {
        $simulator = new Simulator($this->config);
        $variants = $this->variantInputs($household, $settings, $assumptions, $action);

        // Each variant fills one third of the overall progress bar.
        $variantProgress = static fn (int $i): ?callable => $onProgress === null
            ? null
            : static fn (int $completed, int $total): mixed => $onProgress(($i + $completed / $total) / 3);

        $run = fn (string $key, int $i): SimulationResult => $simulator->run(
            $variants[$key]['household'],
            $variants[$key]['settings'],
            $assumptions,
            $this->lifeTable,
            $nPaths,
            $seed,
            $variantProgress($i),
        );

        return [
            'stay_put' => $run('stay_put', 0),
            'buy_outright' => $run('buy_outright', 1),
            'rent' => $run('rent', 2),
        ];
    }

    /**
     * The three variant households + their settings, the single source of the housing
     * transforms: "stay put" keeps the current household; "buy outright" swaps in a cheaper
     * outright home and invests the surplus; "rent" sells, invests all proceeds and pays rent.
     * Both `compare()` (Monte Carlo) and a deterministic per-variant projection (the
     * per-strategy cashflow ladder) run these, so the transforms can't drift between the two.
     *
     * @return array{stay_put: array{household: Household, settings: ForecastSettings}, buy_outright: array{household: Household, settings: ForecastSettings}, rent: array{household: Household, settings: ForecastSettings}}
     */
    public function variantInputs(Household $household, ForecastSettings $settings, AssumptionSet $assumptions, HousingAction $action): array
    {
        $netProceeds = $this->saleProceeds($household, $action)->netProceeds;

        return [
            'stay_put' => ['household' => $household, 'settings' => $settings],
            'buy_outright' => ['household' => $this->buyVariant($household, $action), 'settings' => $settings],
            'rent' => ['household' => $this->rentVariant($household, $netProceeds), 'settings' => $this->rentSettings($settings, $assumptions, $action)],
        ];
    }

    /**
     * Decompose the sale of the current home into net proceeds and the costs netted
     * off it (single source — {@see HousingProceeds}). Public so the figure can be
     * surfaced and reconciled rather than recomputed.
     */
    public function saleProceeds(Household $household, HousingAction $action): HousingProceeds
    {
        // The reconciled decomposition lives on HousingProceeds (the single definition, also used
        // for an in-projection forced sale); here it runs on the year-0 sale price entered.
        return HousingProceeds::compute(
            $action->salePrice,
            $household->primaryResidence?->outstandingMortgage ?? Money::zero(),
            $action->sellingCosts,
            $household->primaryResidence?->cgtHistory,
            $household->primaryResidence?->ownershipShare,
            $this->config,
        );
    }

    /**
     * Decompose the buy-cheaper leg into the surplus that ends up invested (single source —
     * {@see HousingPurchase}). Public so the figure can be surfaced and reconciled rather
     * than recomputed: {@see buyVariant} reads it, and so does any UI breakdown.
     */
    public function buyOutcome(Household $household, HousingAction $action): HousingPurchase
    {
        $netProceeds = $this->saleProceeds($household, $action)->netProceeds;
        $buyPrice = $action->buyPrice ?? Money::zero();
        $sdlt = (new SdltCalculator($this->config))->compute($buyPrice)->total;
        $moving = $action->movingCosts ?? Money::fromPence(self::DEFAULT_MOVING_COSTS_PENCE);
        $totalCost = $buyPrice->plus($sdlt)->plus($moving);

        // If a buy mortgage is available and the purchase costs more than the cash the sale
        // frees, the shortfall is borrowed (interest-only) rather than flooring the surplus to
        // zero and pretending the home was bought for free. Otherwise it is an outright buy: any
        // excess cash is the invested surplus, and an unaffordable buy stays flagged.
        if ($action->buyMortgageRate !== null && $totalCost->pence > $netProceeds->pence) {
            $mortgage = $totalCost->minus($netProceeds);
            $surplus = Money::zero();
        } else {
            $mortgage = Money::zero();
            $surplus = $netProceeds->minus($totalCost)->minZero();
        }

        return new HousingPurchase($netProceeds, $buyPrice, $sdlt, $moving, $surplus, $mortgage);
    }

    private function buyVariant(Household $household, HousingAction $action): Household
    {
        $outcome = $this->buyOutcome($household, $action);
        $mortgaged = $outcome->mortgage->isPositive();

        $newProperty = new Property(
            currentValue: $outcome->buyPrice,
            ownership: $mortgaged ? OwnershipType::Mortgaged : OwnershipType::Outright,
            isPrimaryResidence: true,
            outstandingMortgage: $mortgaged ? $outcome->mortgage : null,
            runningCosts: $this->newHomeRunningCosts($household, $action, $outcome->buyPrice),
        );

        // A mortgaged purchase carries an ongoing interest-only (RIO) payment for life; charge it
        // as the new home's mortgage cost (withHousing strips the old home's, so this is only the
        // new one). Null rate / cash-only buy → no cost. The balance stays owing (interest-only),
        // repaid from the estate on sale/death — the v1 "mortgage not netted from wealth" caveat.
        $interest = ($mortgaged && $action->buyMortgageRate !== null)
            ? $outcome->mortgage->applyRate($action->buyMortgageRate)
            : null;

        return $this->withHousing($household, $newProperty, $outcome->surplus, $interest);
    }

    private function rentVariant(Household $household, Money $netProceeds): Household
    {
        // No property; all proceeds invested.
        return $this->withHousing($household, null, $netProceeds);
    }

    private function rentSettings(ForecastSettings $settings, AssumptionSet $assumptions, HousingAction $action): ForecastSettings
    {
        return new ForecastSettings(
            baseYear: $settings->baseYear,
            baseTaxYear: $settings->baseTaxYear,
            drawdownStrategy: $settings->drawdownStrategy,
            allocation: $settings->allocation(),
            freezeEndYear: $settings->freezeEndYear,
            annualRent: $action->annualRent ?? Money::zero(),
            rentInflationReal: $action->rentInflationReal ?? $assumptions->rentInflation,
            modelCareCost: $settings->modelCareCost,
        );
    }

    /**
     * The running (upkeep) costs of the bought home. When the current home carries its own
     * runningCosts (a house with entered upkeep), scale them to the new home's value. When it
     * does not — the common case here, a leasehold flat whose building maintenance was inside
     * its service charge (a while_owning_home spend line, stripped on sale), so its runningCosts
     * is empty — fall back to the standard {@see HOME_MAINTENANCE_RATE_BPS} of the buy price, so
     * the freehold purchase is not modelled with zero upkeep. The maintenance default replaces
     * the service charge the sold flat no longer pays.
     */
    private function newHomeRunningCosts(Household $household, HousingAction $action, Money $buyPrice): Money
    {
        $current = $household->primaryResidence?->runningCosts;
        if ($current !== null && $current->isPositive() && ! $action->salePrice->isZero()) {
            return Money::fromPence((int) round($current->pence * $buyPrice->pence / $action->salePrice->pence));
        }

        return $buyPrice->applyRate(Percent::fromBasisPoints(self::HOME_MAINTENANCE_RATE_BPS));
    }

    /**
     * Rebuild the household with a different primary residence and the freed cash added to a
     * new invested (GIA) account for the first person. $mortgageInterest, when set, is the
     * ongoing interest-only payment on a mortgage taken to fund a buy above the proceeds; it is
     * added back as the new home's mortgage cost (the old home's was stripped below).
     */
    private function withHousing(Household $household, ?Property $property, Money $investedCash, ?Money $mortgageInterest = null): Household
    {
        $accounts = $household->accounts;
        if ($investedCash->isPositive()) {
            $accounts[] = new Account($household->persons[0]->id, AccountType::Gia, $investedCash);
        }

        // The current home is sold in both sell variants, so its housing-linked spend (mortgage
        // payment, service charge) stops — only "stay put" keeps it. This is the contingent-cost
        // rule that stops the buy/rent comparison being charged a phantom mortgage on a property
        // it no longer owns. A mortgaged buy then re-adds the new home's interest-only payment.
        $profile = $household->expenseProfile->withoutPropertyCosts();
        if ($mortgageInterest !== null && $mortgageInterest->isPositive()) {
            $profile = $profile->withMortgageCosts($mortgageInterest);
        }

        return new Household(
            name: $household->name,
            region: $household->region,
            persons: $household->persons,
            expenseProfile: $profile,
            pensions: $household->pensions,
            accounts: $accounts,
            incomeStreams: $household->incomeStreams,
            primaryResidence: $property,
        );
    }
}
