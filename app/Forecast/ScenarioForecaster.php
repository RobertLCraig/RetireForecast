<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Care\CareStressScenario;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Forecast\HistoricalBacktester;
use RetireForecast\FinanceEngine\Forecast\HistoricalBacktestResult;
use RetireForecast\FinanceEngine\Housing\HousingComparison;
use RetireForecast\FinanceEngine\Housing\Tenancy;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Turns a persisted {@see Scenario} into the engine's input DTOs and runs the
 * forecast for it. This is the one place the app assembles a forecast: it resolves
 * the household, assumption set, housing action, tax-year config and settings, then
 * hands them to the framework-free engine.
 *
 * The base year is taken from the scenario's tax year (e.g. 2026-27 -> 2026) so the
 * run is deterministic and clock-free, matching the engine's no-clock rule.
 */
final class ScenarioForecaster
{
    /**
     * A stamp recorded on each run so any stored result is auditable back to its inputs.
     * Bumped 2026-09-05 (year-zero-receipt-funding): the year-0 purchase-funding waterfall could see the
     * household's accounts and nothing else, so a documented capital receipt landing in the
     * purchase year was invisible to it and the plan borrowed for life beside money it already
     * had. A same-year receipt is now spent on the purchase FIRST, before savings are drawn and
     * before anything is borrowed, and only its unspent remainder is credited as that year's
     * income ({@see HousingComparison::buyOutcome}, whose decomposition names it as
     * `fundedFromReceipts`). Any buy plan stored under an earlier stamp that carries a receipt in
     * its base year borrows too much, so its spend, wealth, depletion year and success odds are
     * too PESSIMISTIC; every other plan is byte-identical. See board card 0034.
     * Previous bump 2026-09-05 (expenses-across-the-sell-boundary): two spend lines were filed under the wrong
     * heading. A home-ownership cost can now say how much of it BUYS UTILITIES
     * ({@see ExpenseProfile::$propertyCostsUtilities}), and that part is carried across a sale
     * instead of being deleted with the service charge, because a house or a park home still has to
     * be heated and plumbed. And buildings or contents insurance filed as discretionary now counts
     * in the ESSENTIAL floor ({@see HouseholdAssembler::tierOf}), because cover a lender requires is
     * not a nice-to-have. Any stored plan carrying such an insurance line has an essential floor
     * that is too low under an earlier stamp, so its "essentials always met" probability and its
     * capacity-for-loss reading are too favourable; the utilities figure is new input, so no stored
     * scenario carries one and no sell plan moves until somebody enters it. See board card 0033.
     * Previous bump 2026-09-05 (leasehold-selling-costs): selling a home now costs what selling a LEASEHOLD
     * flat costs. The engine's all-in default rate, applied when nothing is itemised, doubles to
     * {@see HousingProceeds::DEFAULT_SELLING_COST_RATE_BP} (it was an agent's fee and little else),
     * and a disposal that actually owes capital gains tax is charged
     * {@see HousingProceeds::CGT_RETURN_FEE_PENCE} for preparing the 60-day return, which is not
     * optional and was charged as nothing. Both come off the NET PROCEEDS the whole buy-versus-rent
     * comparison is built on, so every sell plan stored under an earlier stamp keeps money it would
     * never see: its wealth, depletion year and success odds are too favourable, and a stay-put plan
     * is byte-identical. See board card 0032.
     * Previous bump 2026-09-05 (tenancy-deposit): a sell-and-rent plan is charged the tenancy DEPOSIT as a
     * year-0 one-off ({@see Tenancy::deposit}, the Tenant Fees Act cap on the rent), where before it
     * was handed the tenancy for nothing. Every rent variant spends more in its first year under
     * this stamp, so its wealth and terminal figures stored earlier are very slightly too
     * favourable; no other variant moves, and the referencing flag beside it changes no figure at
     * all. See board card 0031.
     * Previous bump 2026-09-05 (letting-costs): a LET property no longer earns its rent gross. The agent's
     * fee, the empty weeks between tenants and the repairs and safety certificates come off it
     * ({@see Property::DEFAULT_LETTING_MANAGEMENT_BPS} and its
     * siblings, about a quarter of gross rent between them), and the let home's service charge is
     * deducted as a letting expense rather than taxed as profit. The Section 24 credit is now read
     * off that PROFIT instead of gross rent. Any let-to-let plan stored earlier banks rent it would
     * never receive and is over-relieved on it, so its wealth, depletion year and success odds are
     * too favourable, and its tax is understated where the letting costs exceed the credit lost.
     * A plan whose home is not let is byte-identical. See board card 0030.
     * Previous bump 2026-09-05 (single-property-house-risk): a per-property growth override now sets the MEAN
     * the sampled house path is centred on instead of replacing that path, and the home is moved over
     * a SINGLE-PROPERTY spread rather than an index one
     * ({@see AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE}). The central projection is
     * unchanged, so wealth, tax and depletion on the deterministic figures still reconcile; every
     * Monte Carlo band, success probability and capacity-for-loss reading on a plan that keeps or
     * buys a home is WIDER under this stamp, and an overridden home (a park home, a flat priced by
     * hand) carried no house risk at all before it. See board card 0029.
     * Previous bump 2026-09-05 (property-costs-default-growth): home-ownership costs (the while-owning-home
     * bucket: service charge, ground rent, levies) with no rate entered now escalate at the
     * disclosed default above CPI ({@see ExpenseProfile::DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS})
     * instead of riding plain CPI, and a dated one-off marked as a liability of owning the home
     * stops when that home is sold. Any stay-put plan carrying a service charge and no explicit
     * rate spends MORE under this stamp than it did before, most of all in its late years, so its
     * wealth, depletion year and success odds stored earlier are too favourable. See board card 0028.
     * Previous bump 2026-09-05 (nominal-mortgage-payment): a mortgage payment charged from the "Mortgage"
     * expense line (interest-only, RIO, buy-to-let, a serviced lifetime mortgage — every shape
     * except the amortisation schedule, which already had this treatment) is now FIXED NOMINAL and
     * NOT survivor-scaled, and the buy-to-let finance cost behind the Section 24 credit is nominal
     * interest too. Stored runs charged it CPI-indexed and cut it to the survivor factor at the
     * first death, so every borrowing plan's spend is overstated under the previous stamp and its
     * wealth, depletion and success odds are too pessimistic against selling. See board card 0024.
     * Previous bump 2026-08-29 (one-off-spend-split): a one-off CAPITAL lump the plan cannot fund (an
     * unfunded purchase, a mortgage redeemed from capital) is now charged the year's shortfall
     * first and judged on its own, so it no longer fails the all-or-nothing full-spend test. That
     * lump is a year-0 constant on every sampled path, so any full-spend probability stored under
     * the previous stamp for a plan with a funding gap reads 0.0% and is not comparable with one
     * stored after. Essentials, wealth, tax and depletion are unchanged. See board card 0025.
     * Previous bump 2026-08-23 (repeated-pcls-crystallisation): a SECOND lump sum out of the same pot used to
     * be debited from the residue the first one left behind, quietly turning that money
     * uncrystallised again and handing a later draw a tax-free quarter of it. The whole slice is now
     * designated before the cash is paid out of it, so a plan with more than one lump-sum row on a
     * pension pays more tax than it did under the previous stamp, under EVERY draw order. See
     * DECISIONS 2026-08-19 item 12.
     * Previous bump 2026-08-23 (pcls-crystallisation): tax-free cash taken as a planned lump sum now
     * CRYSTALLISES the three quarters of the pot left behind it, so a later fill-the-bands draw
     * from that pot no longer takes a second tax-free quarter of the same money. Any run stored
     * under the previous stamp for a plan with both a lump sum and a fill-the-bands draw understates
     * its tax. See DECISIONS 2026-08-19 item 8.
     * Previous bump 2026-08-23 (ufpls-fill-bands): an ad-hoc "fill the bands" pension draw is now taken
     * UFPLS-style (a quarter tax-free while the Lump Sum Allowance lasts) instead of being taxed on
     * 100% of the gross, so fill-the-bands tax stored earlier is too high; and flexible access now
     * caps later money-purchase contributions at the MPAA under EVERY draw order, so a run stored
     * earlier for a household with both a working member and an ad-hoc pension draw over-funds the
     * pot. See DECISIONS 2026-08-19.
     * Previous bump 2026-07-09 (btl-finance-cost): a let property's mortgage interest now yields the
     * basic-rate (20%) buy-to-let finance-cost tax reducer, so rental income on a mortgaged let
     * is no longer taxed with no relief for the interest — let-plan tax stored earlier is too high.
     * Previous bump 2026-07-08 (home-maintenance): a bought freehold home with no explicit upkeep now
     * carries a standard 1%-of-value maintenance running cost, so buy variants are no longer
     * modelled with zero upkeep — buy-plan figures stored earlier are too favourable.
     * Previous bump 2026-07-08 (care-means-test): each care year is charged at the household-borne
     * means-tested amount, not the gross self-funder fee — care figures (and success odds on
     * care-modelling runs) stored under the net-wealth stamp assume gross fees throughout.
     * Previous bump 2026-07-08 (net-wealth): total wealth became net of any outstanding
     * mortgage (home EQUITY, NNEG-floored) — wealth figures stored under the phase-3 stamp
     * are gross-property and not comparable.
     */
    public const ENGINE_VERSION = 'finance-engine/year-zero-receipt-funding';

    /**
     * The draw order every scenario is forecast under unless one is named. THE one home for it:
     * {@see WithdrawalStrategyComparison::CURRENT} reads this constant rather than
     * repeating the value, so the "your current order" baseline every saving is measured against
     * cannot drift from the order the rest of the page is actually showing. FLAGGED (board card
     * 0075): the reader cannot choose the order, and this default is not disclosed to them.
     */
    public const DEFAULT_DRAWDOWN_STRATEGY = DrawdownStrategy::TaxEfficient;

    /** The central best-estimate forecast: median death ages, expected returns, no sampling. */
    public function deterministic(Scenario $scenario): ForecastResult
    {
        return $this->deterministicWith($scenario, $this->assumptions($scenario));
    }

    /**
     * The central best-estimate forecast under an explicit assumption set — the basis of
     * the compare-assumptions overlay, which runs this once per shipped set.
     */
    public function deterministicWith(Scenario $scenario, AssumptionSet $assumptions): ForecastResult
    {
        return (new DeterministicForecaster($this->config($scenario), new CohortLifeTable))
            ->forecast($this->household($scenario), $assumptions, $this->settings($scenario));
    }

    /**
     * The central best-estimate forecast for EACH housing strategy (stay put / buy cheaper /
     * sell & rent), so the cashflow ladder can show the year-by-year picture by strategy
     * rather than only the raw household. Each variant household + settings comes from
     * {@see HousingComparison::variantInputs()} — the SAME single source the Monte Carlo
     * comparison runs — so the deterministic ladder and the simulated comparison transform
     * the household for a sale identically and cannot drift. With the contingent-cost rule
     * the sell variants carry no property cost and no home value, and invest the freed
     * proceeds; `stay_put` is byte-identical to {@see deterministic()} (the raw household).
     *
     * @return array{stay_put: ForecastResult, buy_outright: ForecastResult, rent: ForecastResult}
     */
    public function deterministicVariants(Scenario $scenario): array
    {
        $assumptions = $this->assumptions($scenario);
        $variants = $this->housingComparison($scenario)->variantInputs(
            $this->household($scenario),
            $this->settings($scenario),
            $assumptions,
            $this->housingAction($scenario),
        );

        $forecaster = new DeterministicForecaster($this->config($scenario), new CohortLifeTable);

        return array_map(
            fn (array $variant): ForecastResult => $forecaster->forecast($variant['household'], $assumptions, $variant['settings']),
            $variants,
        );
    }

    /**
     * The same per-strategy deterministic ladder as {@see deterministicVariants}, but with an adverse
     * late-life care spell injected (the "if significant care is needed" stress). Shown beside the
     * care-free ladder so the plain-English affordability verdict is never "lasts for life" against a
     * silently care-free path (care is a Monte-Carlo-only risk absent from the central estimate). Uses
     * the same variant inputs, so care-free and care-stress differ only by the injected spell.
     *
     * @return array{stay_put: ForecastResult, buy_outright: ForecastResult, rent: ForecastResult}
     */
    public function deterministicCareStressVariants(Scenario $scenario): array
    {
        $assumptions = $this->assumptions($scenario);
        $variants = $this->housingComparison($scenario)->variantInputs(
            $this->household($scenario),
            $this->settings($scenario),
            $assumptions,
            $this->housingAction($scenario),
        );

        $forecaster = new DeterministicForecaster($this->config($scenario), new CohortLifeTable);
        $stress = CareStressScenario::adverseDefault();

        return array_map(
            fn (array $variant): ForecastResult => $forecaster->forecastWithCareStress($variant['household'], $assumptions, $variant['settings'], $stress),
            $variants,
        );
    }

    /**
     * The historical sequence-of-returns stress test: run the plan through every eligible
     * past starting year (replaying that year's real UK returns + inflation), so the results
     * page can show how it would have fared starting into 1929 / 1973-74 / 2000 / 2007.
     * Deterministic (no Monte Carlo run needed), so it shows immediately like the ladder.
     */
    public function historicalBacktest(Scenario $scenario): HistoricalBacktestResult
    {
        return (new HistoricalBacktester($this->config($scenario), new CohortLifeTable))
            ->backtest($this->household($scenario), $this->assumptions($scenario), $this->settings($scenario));
    }

    /** One variant's Monte Carlo run (the scenario's household as it stands). */
    public function simulate(Scenario $scenario, int $nPaths, int $seed): SimulationResult
    {
        return (new Simulator($this->config($scenario)))->run(
            $this->household($scenario),
            $this->settings($scenario),
            $this->assumptions($scenario),
            new CohortLifeTable,
            $nPaths,
            $seed,
        );
    }

    /**
     * The buy-vs-rent headline: stay-put, buy-cheaper-outright and sell-and-rent run
     * on identical seeds, so any difference is the housing choice alone.
     *
     * @param  (callable(float $fraction): void)|null  $onProgress  overall 0..1; throwing aborts the run
     * @return array{stay_put: SimulationResult, buy_outright: SimulationResult, rent: SimulationResult}
     */
    public function compareHousing(Scenario $scenario, int $nPaths, int $seed, ?callable $onProgress = null): array
    {
        return $this->housingComparison($scenario)->compare(
            $this->household($scenario),
            $this->settings($scenario),
            $this->assumptions($scenario),
            $this->housingAction($scenario),
            $nPaths,
            $seed,
            $onProgress,
        );
    }

    /**
     * The housing-comparison engine for this scenario. Exposed so the deterministic sale
     * decomposition ({@see HousingComparison::saleProceeds} / {@see HousingComparison::buyOutcome})
     * can be surfaced on the results page and reconciled, rather than recomputed in the app.
     */
    public function housingComparison(Scenario $scenario): HousingComparison
    {
        return new HousingComparison($this->config($scenario), new CohortLifeTable);
    }

    /**
     * The household, settings and assumptions for ONE housing strategy — the scenario as that
     * plan actually leaves it (stayed put, bought cheaper, renting), not the raw household.
     * Reading a sell plan off the stay-put path is a live trap in this codebase, so anything that
     * stresses or searches a plan resolves it through here first.
     *
     * $strategy pins which variant to read; null falls back to the scenario's own stored choice,
     * which is what a caller outside the results ladder wants. An unknown strategy falls back to
     * stay-put rather than throwing, matching how the ladder degrades.
     *
     * @return array{household: Household, settings: ForecastSettings, assumptions: AssumptionSet}
     */
    public function variantInputs(Scenario $scenario, ?string $strategy = null): array
    {
        $assumptions = $this->assumptions($scenario);
        $all = $this->housingComparison($scenario)->variantInputs(
            $this->household($scenario),
            $this->settings($scenario),
            $assumptions,
            $this->housingAction($scenario),
        );

        $inputs = $all[$strategy ?? $scenario->effectiveBuilderState()['variant'] ?? 'stay_put'] ?? $all['stay_put'];

        return [
            'household' => $inputs['household'],
            'settings' => $inputs['settings'],
            'assumptions' => $assumptions,
        ];
    }

    public function config(Scenario $scenario): TaxYearConfig
    {
        return TaxYearRegistry::for($scenario->base_tax_year, $this->household($scenario)->region);
    }

    /**
     * The economic assumptions the forecast runs against: the scenario's chosen sourced
     * preset (or the engine default), overlaid with any figures the user has edited into
     * a derived custom set ({@see AssumptionOverrides}). This is the ONE place overrides
     * are applied, so the deterministic forecast, the per-variant ladder, the Monte Carlo
     * and the frozen run snapshot all run against the same set and cannot drift.
     */
    public function assumptions(Scenario $scenario): AssumptionSet
    {
        $base = $scenario->assumptionSet?->toDto() ?? AssumptionSetLibrary::default();
        $overrides = $scenario->effectiveBuilderState()['assumptionOverrides'] ?? [];

        return AssumptionOverrides::apply($base, $overrides, $this->settings($scenario)->allocation());
    }

    private function household(Scenario $scenario): Household
    {
        return $scenario->toHousehold();
    }

    private function housingAction(Scenario $scenario): HousingAction
    {
        return $scenario->toHousingAction();
    }

    /**
     * The run settings (start year, allocation, drawdown strategy, freeze-end year). Public
     * so the results page can read the blended real return the invested proceeds grow at
     * (`settings()->allocation()->blendedRealReturn($assumptions)`) for the assumptions panel.
     */
    public function settings(Scenario $scenario, ?DrawdownStrategy $strategy = null): ForecastSettings
    {
        // A forced sale (a home whose mortgage is called for redemption and not refinanceable)
        // is modelled in place by the projector: it needs the entered post-sale rent and the
        // selling-cost basis, neither of which the projector can reach (it has no HousingAction),
        // so they ride on the settings here. Only a forced-sale scenario carries them; every other
        // run leaves rent null (an owner pays no rent) and the projector uses its default costs.
        $home = $this->household($scenario)->primaryResidence;
        $forcedSale = $home?->mortgageMaturityAction === MortgageMaturityAction::ForcedSale
            && $home?->mortgageRedemptionYear !== null;
        $action = $forcedSale ? $this->housingAction($scenario) : null;

        return new ForecastSettings(
            baseYear: (int) substr($scenario->base_tax_year, 0, 4),
            baseTaxYear: $scenario->base_tax_year,
            drawdownStrategy: $strategy ?? self::DEFAULT_DRAWDOWN_STRATEGY,
            annualRent: $action?->annualRent,
            rentInflationReal: $action?->rentInflationReal ?? ($forcedSale ? $scenario->assumptionSet?->toDto()?->rentInflation : null),
            modelCareCost: (bool) ($scenario->effectiveBuilderState()['modelCareCost'] ?? false),
            sellingCosts: $action?->sellingCosts,
            // Consume the (previously inert) IHT toggle. homeToDescendants unlocks the residence
            // nil-rate band on the final death; default true (the common case for a homeowner),
            // overridable in the builder (slice 4).
            modelIht: (bool) ($scenario->effectiveBuilderState()['ihtModelled'] ?? false),
            homeToDescendants: (bool) ($scenario->effectiveBuilderState()['homeToDescendants'] ?? true),
            // Use each person's unused ISA allowance on money already held in a taxable account
            // ("bed and ISA"). On unless the scenario says otherwise, because leaving it out
            // understates every plan that sells a home and invests the proceeds; disclosed on the
            // results page as an assumed figure, so it is a choice the reader can see and reject.
            useIsaAllowance: (bool) ($scenario->effectiveBuilderState()['useIsaAllowance'] ?? true),
        );
    }

    /**
     * The central deterministic forecast run under a specific withdrawal (drawdown) strategy,
     * on the same household + assumptions as {@see deterministic()}, so the withdrawal-sequencing
     * comparison can price each strategy on an identical basis. {@see WithdrawalStrategyComparison}.
     */
    public function deterministicUnderStrategy(Scenario $scenario, DrawdownStrategy $strategy): ForecastResult
    {
        return (new DeterministicForecaster($this->config($scenario), new CohortLifeTable))
            ->forecast($this->household($scenario), $this->assumptions($scenario), $this->settings($scenario, $strategy));
    }
}
