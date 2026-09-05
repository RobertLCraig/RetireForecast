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
 * A buy above the net proceeds is funded from documented sources only: the household's
 * liquid savings, then an interest-only (RIO) mortgage when configured; any remainder is
 * an unfunded gap charged as a year-0 cost so the plan visibly fails ({@see fundingFor}).
 *
 * v1 simplifications (documented): the additional-property SDLT surcharge is not applied
 * (a straight replacement of the main residence).
 */
final class HousingComparison
{
    /**
     * PUBLIC so a presenter can DISCLOSE it without restating it. Any figure the engine supplies
     * for itself must be visible to the user and traceable to one home — a number the reader cannot
     * see or interrogate must not be able to move their result.
     */
    public const DEFAULT_MOVING_COSTS_PENCE = 200_000; // £2,000

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
    public const HOME_MAINTENANCE_RATE_BPS = 100; // 1.00% of value a year (PUBLIC so it can be disclosed)

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
            'buy_outright' => ['household' => $this->buyVariant($household, $action, $settings), 'settings' => $settings],
            'rent' => ['household' => $this->rentVariant($household, $netProceeds, $action, $settings), 'settings' => $this->rentSettings($settings, $assumptions, $action)],
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
     * Decompose the buy leg into how the purchase is funded (single source —
     * {@see HousingPurchase}). Public so the figures can be surfaced and reconciled rather
     * than recomputed: {@see buyVariant} reads it, and so does any UI breakdown.
     */
    public function buyOutcome(Household $household, HousingAction $action): HousingPurchase
    {
        return $this->fundingFor($household, $action)['purchase'];
    }

    /**
     * The ONE home of the purchase-funding waterfall. A purchase above the net sale
     * proceeds is funded from documented sources only, in order: the household's liquid
     * savings (drawn cash → GIA → ISA, never pensions — {@see SavingsFunding}), then an
     * interest-only (RIO) mortgage when a rate is configured; anything left is the
     * unfunded gap, which {@see buyVariant} charges as a year-0 one-off cost so the plan
     * visibly fails rather than being handed the home for free. Both {@see buyOutcome}
     * (the surfaced figures) and {@see buyVariant} (the projected household) read this,
     * so the reported decomposition and the accounts actually drawn can never disagree.
     *
     * @return array{purchase: HousingPurchase, accounts: list<Account>, realisedGains: array<string, Money>}
     */
    private function fundingFor(Household $household, HousingAction $action): array
    {
        $netProceeds = $this->saleProceeds($household, $action)->netProceeds;
        $buyPrice = $action->buyPrice ?? Money::zero();
        $sdlt = (new SdltCalculator($this->config))->compute($buyPrice)->total;
        $moving = $action->movingCosts ?? Money::fromPence(self::DEFAULT_MOVING_COSTS_PENCE);
        $totalCost = $buyPrice->plus($sdlt)->plus($moving);

        $gap = $totalCost->minus($netProceeds)->minZero();
        if (! $gap->isPositive()) {
            // The proceeds cover everything; the excess is the invested surplus.
            return [
                'purchase' => new HousingPurchase(
                    $netProceeds, $buyPrice, $sdlt, $moving,
                    surplus: $netProceeds->minus($totalCost),
                    mortgage: Money::zero(),
                    fundedFromSavings: Money::zero(),
                    unfundedGap: Money::zero(),
                ),
                'accounts' => $household->accounts,
                'realisedGains' => [],
            ];
        }

        // Savings first (own money before interest-bearing debt), then the mortgage takes
        // whatever the savings couldn't cover — only when a rate is configured. Any residue
        // is the unfunded gap, reported never absorbed.
        $funding = SavingsFunding::draw($household, $gap);
        $remainder = $gap->minus($funding->drawn);
        $mortgage = $action->buyMortgageRate !== null ? $remainder : Money::zero();

        return [
            'purchase' => new HousingPurchase(
                $netProceeds, $buyPrice, $sdlt, $moving,
                surplus: Money::zero(),
                mortgage: $mortgage,
                fundedFromSavings: $funding->drawn,
                unfundedGap: $remainder->minus($mortgage),
            ),
            'accounts' => $funding->accounts,
            'realisedGains' => $funding->realisedGains,
        ];
    }

    private function buyVariant(Household $household, HousingAction $action, ForecastSettings $settings): Household
    {
        ['purchase' => $outcome, 'accounts' => $accounts, 'realisedGains' => $gains] = $this->fundingFor($household, $action);
        // GIA gains realised by the year-0 savings draw, carried so the projector charges the
        // CGT in year 0 (against that year's annual exempt amount) — a disposal is never free.
        $realisedGains = array_filter($gains, fn (Money $gain): bool => $gain->isPositive());
        $mortgaged = $outcome->mortgage->isPositive();

        $newProperty = new Property(
            currentValue: $outcome->buyPrice,
            ownership: $mortgaged ? OwnershipType::Mortgaged : OwnershipType::Outright,
            isPrimaryResidence: true,
            outstandingMortgage: $mortgaged ? $outcome->mortgage : null,
            runningCosts: self::newHomeRunningCosts($household, $action, $outcome->buyPrice),
            // A bought home can grow at its own real rate, INCLUDING a negative one — a park home
            // depreciates. Null keeps the assumption set's house growth, as before.
            growthAssumptionOverride: $action->buyGrowthOverride,
        );

        // A mortgaged purchase carries an ongoing interest-only (RIO) payment for life; charge it
        // as the new home's mortgage cost (withHousing strips the old home's, so this is only the
        // new one). Null rate / cash-only buy → no cost. The balance stays owing (interest-only),
        // repaid from the estate on sale/death — the v1 "mortgage not netted from wealth" caveat.
        $interest = ($mortgaged && $action->buyMortgageRate !== null)
            ? $outcome->mortgage->applyRate($action->buyMortgageRate)
            : null;

        // Any unfunded part of the purchase is charged as a year-0 one-off cost: money the plan
        // does not have is never conjured into home equity — the projection shows the year-0
        // shortfall (unmet spend) and the plan visibly fails until the gap is funded. Keyed to
        // the FIRST person's base-year age, the one-off convention the projector fires on.
        $oneOffCost = $outcome->unfundedGap->isPositive()
            ? [
                'atAge' => $settings->baseYear - (int) $household->persons[0]->dob->format('Y'),
                'amount' => $outcome->unfundedGap,
                'label' => 'Unfunded purchase shortfall',
            ]
            : null;

        return $this->withHousing($household, $newProperty, $outcome->surplus, $interest, $accounts, $oneOffCost, $realisedGains);
    }

    private function rentVariant(Household $household, Money $netProceeds, HousingAction $action, ForecastSettings $settings): Household
    {
        // Starting a tenancy costs money before the keys change hands, and until board card 0031
        // this plan was handed one for free. The DEPOSIT is the part charged on top of the rent
        // line: capped by the Tenant Fees Act, held for as long as they rent, re-lodged at every
        // move, and only partly returned. The first month's rent in advance is NOT charged again —
        // a year of a monthly-in-advance tenancy is twelve payments and the rent line already
        // charges twelve — but it is named in the disclosure, because the day-one cash is what a
        // household actually has to produce. Keyed to the FIRST person's base-year age, the
        // one-off convention the projector fires on ({@see buyVariant}).
        $rent = $action->annualRent;
        $oneOffCost = $rent !== null && $rent->isPositive()
            ? [
                'atAge' => $settings->baseYear - (int) $household->persons[0]->dob->format('Y'),
                'amount' => Tenancy::deposit($rent),
                'label' => Tenancy::UP_FRONT_LABEL,
            ]
            : null;

        // No property; all proceeds invested.
        return $this->withHousing($household, null, $netProceeds, oneOffCost: $oneOffCost);
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
     *
     * PUBLIC and static so a presenter can DISCLOSE the figure by READING it rather than by
     * repeating the arithmetic. Two of the three branches hand the reader a number they never
     * typed, and a restated rule is one that drifts from the one actually charged.
     */
    public static function newHomeRunningCosts(Household $household, HousingAction $action, Money $buyPrice): Money
    {
        // An explicit figure wins over any derivation: some homes' costs bear no relation to their
        // value (a park home's pitch fee is a flat annual charge, which the 1%-of-value proxy below
        // can understate by thousands).
        if ($action->buyRunningCosts !== null) {
            return $action->buyRunningCosts;
        }

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
     * $accounts, when given, replaces the household's accounts — a savings-funded buy passes
     * the post-draw balances so the money spent on the home actually leaves the plan.
     * $oneOffCost, when given, is a dated lump charge appended to the profile — the unfunded
     * part of a purchase, charged so it surfaces as a shortfall instead of appearing for free.
     * $realisedGains carries the GIA gains a year-0 savings draw realised, per person, so the
     * projector charges the CGT in year 0.
     *
     * @param  array{atAge: int, amount: Money, label: string}|null  $oneOffCost
     * @param  array<string, Money>  $realisedGains
     */
    private function withHousing(Household $household, ?Property $property, Money $investedCash, ?Money $mortgageInterest = null, ?array $accounts = null, ?array $oneOffCost = null, array $realisedGains = []): Household
    {
        $accounts ??= $household->accounts;
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
        if ($oneOffCost !== null) {
            $profile = $profile->withOneOffCost($oneOffCost['atAge'], $oneOffCost['amount'], $oneOffCost['label']);
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
            relationshipStatus: $household->relationshipStatus,
            capitalReceipts: $household->capitalReceipts,
            realisedGainsAtStart: $realisedGains,
        );
    }
}
