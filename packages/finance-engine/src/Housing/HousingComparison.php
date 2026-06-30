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
    private const DEFAULT_SELLING_COST_RATE_BP = 200;   // 2%

    private const DEFAULT_MOVING_COSTS_PENCE = 200_000; // £2,000

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
        // Each selling-cost component resolves to £ against the sale price (a % of it, or a
        // flat fee). The total is their sum; the breakdown is carried so a UI can show it and
        // it reconciles to the total by construction. No components → the engine default rate.
        $components = $action->sellingCosts ?? [new SellingCostComponent('Selling costs', Percent::fromBasisPoints(self::DEFAULT_SELLING_COST_RATE_BP))];

        $sellingCosts = Money::zero();
        $breakdown = [];
        foreach ($components as $component) {
            $amount = $component->amount($action->salePrice);
            $sellingCosts = $sellingCosts->plus($amount);
            $breakdown[] = ['label' => $component->label, 'amount' => $amount];
        }

        $mortgage = $household->primaryResidence?->outstandingMortgage ?? Money::zero();

        // CGT: £0 for a main home owned and lived in throughout (full Private Residence Relief —
        // the common case, and the default when no CGT history is given). When the home was let
        // or not the main residence for part of ownership, the gain (sale less purchase, less
        // improvement/acquisition costs, less the allowable selling costs) is taxed after partial
        // PRR by {@see CgtPrivateResidenceCalculator}, split across the owners.
        $history = $household->primaryResidence?->cgtHistory;
        $cgtResult = null;
        $cgt = Money::zero();
        if ($history !== null) {
            $gain = $action->salePrice
                ->minus($history->purchasePrice)
                ->minus($history->improvementCosts)
                ->minus($sellingCosts)
                ->minZero();
            $cgtResult = (new CgtPrivateResidenceCalculator($this->config))->compute(
                $gain,
                $history->ownershipMonths,
                $history->mainResidenceMonths,
                $history->higherRateOnSale,
                $history->owners,
            );
            $cgt = $cgtResult->tax;
        }

        $netProceeds = $action->salePrice->minus($mortgage)->minus($sellingCosts)->minus($cgt)->minZero();

        return new HousingProceeds($action->salePrice, $mortgage, $sellingCosts, $cgt, $netProceeds, $breakdown, $cgtResult);
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

        $surplus = $netProceeds->minus($buyPrice)->minus($sdlt)->minus($moving)->minZero();

        return new HousingPurchase($netProceeds, $buyPrice, $sdlt, $moving, $surplus);
    }

    private function buyVariant(Household $household, HousingAction $action): Household
    {
        $outcome = $this->buyOutcome($household, $action);

        $newProperty = new Property(
            currentValue: $outcome->buyPrice,
            ownership: OwnershipType::Outright,
            isPrimaryResidence: true,
            runningCosts: $this->scaledRunningCosts($household, $action, $outcome->buyPrice),
        );

        return $this->withHousing($household, $newProperty, $outcome->surplus);
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
        );
    }

    private function scaledRunningCosts(Household $household, HousingAction $action, Money $buyPrice): ?Money
    {
        $current = $household->primaryResidence?->runningCosts;
        if ($current === null || $action->salePrice->isZero()) {
            return $current;
        }

        return Money::fromPence((int) round($current->pence * $buyPrice->pence / $action->salePrice->pence));
    }

    /**
     * Rebuild the household with a different primary residence and the freed cash
     * added to a new invested (GIA) account for the first person.
     */
    private function withHousing(Household $household, ?Property $property, Money $investedCash): Household
    {
        $accounts = $household->accounts;
        if ($investedCash->isPositive()) {
            $accounts[] = new Account($household->persons[0]->id, AccountType::Gia, $investedCash);
        }

        return new Household(
            name: $household->name,
            region: $household->region,
            persons: $household->persons,
            // The current home is sold in both sell variants, so its housing-linked spend
            // (mortgage payment, service charge) stops — only "stay put" keeps it. This is
            // the contingent-cost rule that stops the buy/rent comparison being charged a
            // phantom mortgage on a property it no longer owns.
            expenseProfile: $household->expenseProfile->withoutPropertyCosts(),
            pensions: $household->pensions,
            accounts: $accounts,
            incomeStreams: $household->incomeStreams,
            primaryResidence: $property,
        );
    }
}
