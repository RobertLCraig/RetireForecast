<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Dto\Account;
use RetireForecast\FinanceEngine\Dto\AccountType;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
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
 * Net sale proceeds = sale price − outstanding mortgage − selling costs − CGT
 * (usually £0 on a main home via Private Residence Relief). Buying nets off SDLT
 * and moving costs; the surplus (or full proceeds when renting) goes into an
 * invested account that then follows the chosen allocation.
 *
 * v1 simplifications (documented): CGT on the main home is taken as £0 (PRR); the
 * additional-property SDLT surcharge is not applied (a straight replacement of the
 * main residence); a buy price above the net proceeds is not modelled (downsizing
 * is assumed). The let-property PRR edge is deferred.
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
        $netProceeds = $this->saleProceeds($household, $action)->netProceeds;

        // Each variant fills one third of the overall progress bar.
        $variantProgress = static fn (int $i): ?callable => $onProgress === null
            ? null
            : static fn (int $completed, int $total): mixed => $onProgress(($i + $completed / $total) / 3);

        $stayPut = $simulator->run($household, $settings, $assumptions, $this->lifeTable, $nPaths, $seed, $variantProgress(0));

        $buyResult = $simulator->run(
            $this->buyVariant($household, $action, $netProceeds),
            $settings,
            $assumptions,
            $this->lifeTable,
            $nPaths,
            $seed,
            $variantProgress(1),
        );

        $rentResult = $simulator->run(
            $this->rentVariant($household, $netProceeds),
            $this->rentSettings($settings, $assumptions, $action),
            $assumptions,
            $this->lifeTable,
            $nPaths,
            $seed,
            $variantProgress(2),
        );

        return ['stay_put' => $stayPut, 'buy_outright' => $buyResult, 'rent' => $rentResult];
    }

    /**
     * Decompose the sale of the current home into net proceeds and the costs netted
     * off it (single source — {@see HousingProceeds}). Public so the figure can be
     * surfaced and reconciled rather than recomputed.
     */
    public function saleProceeds(Household $household, HousingAction $action): HousingProceeds
    {
        $sellingRate = $action->sellingCostRate ?? Percent::fromBasisPoints(self::DEFAULT_SELLING_COST_RATE_BP);
        $sellingCosts = $action->salePrice->applyRate($sellingRate);
        $mortgage = $household->primaryResidence?->outstandingMortgage ?? Money::zero();
        $cgt = Money::zero(); // CGT on a main home is fully relieved by PRR in v1.

        $netProceeds = $action->salePrice->minus($mortgage)->minus($sellingCosts)->minus($cgt)->minZero();

        return new HousingProceeds($action->salePrice, $mortgage, $sellingCosts, $cgt, $netProceeds);
    }

    private function buyVariant(Household $household, HousingAction $action, Money $netProceeds): Household
    {
        $buyPrice = $action->buyPrice ?? Money::zero();
        $sdlt = (new SdltCalculator($this->config))->compute($buyPrice)->total;
        $moving = $action->movingCosts ?? Money::fromPence(self::DEFAULT_MOVING_COSTS_PENCE);

        $surplus = $netProceeds->minus($buyPrice)->minus($sdlt)->minus($moving)->minZero();

        $newProperty = new Property(
            currentValue: $buyPrice,
            ownership: OwnershipType::Outright,
            isPrimaryResidence: true,
            runningCosts: $this->scaledRunningCosts($household, $action, $buyPrice),
        );

        return $this->withHousing($household, $newProperty, $surplus);
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
            expenseProfile: $household->expenseProfile,
            pensions: $household->pensions,
            accounts: $accounts,
            incomeStreams: $household->incomeStreams,
            primaryResidence: $property,
        );
    }
}
