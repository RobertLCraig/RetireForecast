<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Models\Scenario;
use Closure;
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
 *
 * **It remembers what it derived, for as long as the scenario has not moved.** Every public
 * method here re-derives the same chain — decrypt the base form-state, decrypt the parent's,
 * deep-merge the overrides, assemble the household — and the screens ask several times over: the
 * affordability screen runs the ladder, the care-stress ladder and a sustainable-spend bisection
 * for the base plan AND for every what-if child. The memo is on this INSTANCE and the container
 * hands out one instance per request (the `scoped` binding in AppServiceProvider::register), so it
 * lives exactly as long as the request that opened it. Nothing is written to a cache store and
 * nothing survives the response; persistent forecast caching is deliberately not built (card 0042).
 */
final class ScenarioForecaster
{
    /**
     * How many scenarios' derivations are held at once. A family page shows a base and its
     * what-if children; `scenarios:audit` walks every stored scenario in one process, and
     * without a ceiling it would hold a full projection for each of them at the end.
     */
    private const MEMO_SCENARIOS = 8;

    /** @var array<string, array<string, mixed>> scenario stamp => what was derived => the value */
    private array $memo = [];

    /**
     * A stamp recorded on each run so any stored result is auditable back to its inputs.
     * Bumped 2026-09-07 (lifetime-mortgage-redeemed-on-entry-to-care): an equity-release lifetime
     * mortgage now falls due when the LAST surviving borrower moves permanently into residential
     * care, which is a redemption event under every standard contract and which the projector used
     * to ignore, settling the balance only at the end of the path. The home is sold in that year,
     * the lender is paid first out of the proceeds and the residue is credited to the household's
     * liquid assets, so the resident's care charge that year is assessed on the new position with
     * no home ({@see PathProjector::equityReleaseRedeemedByCare}). Any stored plan that carries a
     * roll-up balance AND reaches a modelled care spell was shown living in the home to the end of
     * the plan with an estate under an earlier stamp: its home equity, its care assessment and its
     * estate are all wrong, in either direction, and the roll-up stops compounding at the sale. A
     * plan with no roll-up rate, or whose paths never reach care, is byte-identical, which is why
     * the Monte Carlo golden master did not move. See board card 0056.
     * Previous bump 2026-09-07 (rnrb-downsizing-addition): selling the home no longer deletes the residence
     * nil-rate band. A home disposed of during the plan (a year-0 sell variant or an in-projection
     * forced sale) is recorded, and the part of the band it would have sheltered is restored at the
     * final death as the statutory downsizing addition
     * (`InheritanceTaxCalculator::downsizingAddition`), capped at the non-home assets passing
     * to direct descendants and held under the tapered allowance. Any stored plan that SELLS its
     * home and models Inheritance Tax pays too MUCH tax under an earlier stamp, by up to £70,000 a
     * band, so the estate it leaves is understated and the whole comparison had a thumb on the
     * scale against downsizing. A plan that stays put, or that does not model Inheritance Tax, is
     * byte-identical. See board card 0053.
     * Previous bump 2026-09-07 (pension-credit-additions-per-entitlement): the two Pension Credit additions
     * are now decided by ENTITLEMENT rather than by a single flag. The severe-disability and carer
     * additions ride the CARE side of a disability award, so an award recorded as mobility-only or
     * as the lowest rate care component ({@see DisabilityAwardRate}) buys neither, where the bare
     * flag bought both; and the carer addition is counted PER CARER, so a couple where each cares
     * for the other now carries two of them where the projector used to stop at the first carer it
     * found. Any stored plan whose members BOTH care for each other is awarded too LITTLE Pension
     * Credit under an earlier stamp, so its wealth, depletion year and success odds are too
     * pessimistic. Nothing moves the other way: an award entered before the rate existed reads as
     * the qualifying care rate, which is what the flag alone has always meant, so a plan with one
     * carer or none is byte-identical. See board card 0051.
     * Previous bump 2026-09-06 (disability-award-split-in-care): a disability award is now recorded as its
     * CARE and MOBILITY components and the two are treated apart in a care placement. The care
     * component counts as income in the local-authority financial assessment, where before neither
     * component did, so a resident funding their own care was charged too LITTLE; and it stops 28
     * days into a placement the authority funds ({@see DisabilityBenefitInCare}), where before both
     * components ran on for the whole spell, so a funded resident banked income they would not have
     * had, and kept the Pension Credit severe-disability addition that rides it. An award entered
     * before the split reads wholly as the care component. Any stored plan holding a tax-free
     * disability income AND a modelled care spell moves under an earlier stamp, in either direction
     * depending on which side of the assessment it lands; a plan with no disability income, or one
     * whose paths never reach care, is byte-identical. See board card 0050.
     * Previous bump 2026-09-06 (pension-age-housing-benefit): a pension-age household that RENTS is now
     * awarded Housing Benefit where its income and capital qualify ({@see HousingBenefit}): the
     * whole eligible rent on Guarantee Credit, otherwise the rent less 65p for every pound of
     * weekly income above its Pension Credit guarantee, and nothing above the capital limit. It
     * comes off the rent, not into income. Alongside it, property held as CAPITAL for the same
     * means test is now valued at market value less the notional costs of sale before the mortgage
     * comes off ({@see CapitalAssessment::propertyCapital}), so a let home no longer inflates the
     * tariff or brings the capital cliff forward. Any stored sell-and-rent plan that reaches a
     * qualifying year spends TOO MUCH under an earlier stamp, so its wealth, depletion year and
     * success odds are too pessimistic and the whole buy-versus-rent ranking had a thumb on the
     * scale against renting; a plan with a let home moves through its Pension Credit award. A plan
     * that never rents and holds no let property is byte-identical. See board card 0048.
     * Previous bump 2026-09-06 (support-for-mortgage-interest): a household on Pension Credit Guarantee
     * Credit is now given Support for Mortgage Interest, which was in neither the engine nor the
     * config: DWP meets the interest on eligible mortgage capital up to a cap at its standard rate
     * ({@see SupportForMortgageInterest}), and, for a pension-age claimant, the service charge and
     * ground rent in full. It is a LOAN, so what is met comes off the household's spending and is
     * added to a second charge on the home, rolled up and repaid on sale or at death. Any stored
     * plan that reaches a Guarantee Credit year while it still owns a home spends TOO MUCH under an
     * earlier stamp, so its wealth, depletion year and success odds are too pessimistic, while the
     * equity it leaves behind is too high. A plan that never qualifies for Guarantee Credit, or
     * that has sold the home by the time it does, is byte-identical. See board card 0045.
     * Previous bump 2026-09-06 (forced-sale-redeems-the-years-balance): a forced sale now clears the mortgage
     * balance as it stands in the SALE year rather than the balance originally entered. The two
     * agree only for an interest-only loan, which is why it survived: a lifetime mortgage has
     * ROLLED UP by then, so the sale freed equity the household no longer had (its wealth,
     * depletion year and success odds are too FAVOURABLE under an earlier stamp), and a repayment
     * mortgage has AMORTISED down, so the sale freed less than the household really keeps (too
     * PESSIMISTIC). A plan with no forced sale, or one whose loan is interest-only, is
     * byte-identical. See board card 0041.
     * Previous bump 2026-09-05 (liquid-wealth-split-between-owners): liquid wealth no longer lands on whoever was
     * DECLARED FIRST. The proceeds of a jointly owned home, sold in year 0 or forced mid-projection,
     * are now credited to each owner in equal shares, and a year's banked surplus goes to whoever's
     * income produced it (evenly where nothing produced it, such as a Pension Credit award). The
     * care means test is deliberately individual, so the second-declared person used to reach care
     * with an empty balance sheet and be funded by the local authority years early. Any stored plan
     * with TWO people moves under this stamp wherever the split changes a per-person allowance or
     * assessment: care charges, Pension Credit, the capital gains annual exempt amount and the
     * savings and dividend allowances. A one-person household is byte-identical. See board card 0040.
     * Previous bump 2026-09-05 (drawdown-marginal-tax-on-full-income): an ad-hoc pension withdrawal is now priced
     * against the whole of the person's income for the year. Savings interest and dividends stack
     * ABOVE non-savings income in the band order, so a withdrawal pushes them across band
     * boundaries and halves the Personal Savings Allowance; the cost of it was read off the
     * non-savings leg alone and none of that reached the bill. A second draw in the same year also
     * restarted from the pre-drawdown income, so under a strategy that draws in more than one pass
     * every later draw was charged in a band the person had already left. Any stored plan that both
     * holds unwrapped savings or shares and draws a pension to meet its spending pays too LITTLE
     * tax under an earlier stamp, so its wealth, depletion year and success odds are too
     * FAVOURABLE; a plan whose taxable accounts are all ISAs and which never draws is
     * byte-identical. See board card 0037.
     * Previous bump 2026-09-05 (transition-year-proration): the year a person retires is a TRANSITION year, and
     * the income that replaces their salary now starts part way through it, as the salary already
     * stopped part way through it ({@see PathProjector::startFraction}). A State Pension starting in
     * November pays two months, not twelve; a Defined Benefit pension pays only the months after the
     * normal retirement age birthday; and National Insurance is charged on the earnings BEFORE the
     * State Pension age date instead of being switched off for the whole calendar year. All three
     * ran the household's way, in the one year an affordability cliff would show, so any plan with a
     * retirement inside its horizon has too much income and too little NI in that year under an
     * earlier stamp: its wealth, depletion year and success odds are too FAVOURABLE. A plan whose
     * members are all past State Pension age and normal retirement age in the base year is
     * byte-identical. See board card 0036.
     * Previous bump 2026-09-05 (db-escalation-per-scheme): a Defined Benefit pension now increases on the basis
     * the reader chose, and on a DIFFERENT basis while deferred from the one it uses in payment
     * ({@see PathProjector::escalateDbPensions}). Both dropdowns were previously collected and read
     * by nothing: every DB pension escalated at full CPI for ever. Any stored plan whose scheme is
     * NOT on plain CPI in both phases moves under this stamp, and the direction depends on what was
     * chosen: a frozen or capped pension banked income it was never promised, so its wealth,
     * depletion year and success odds are too FAVOURABLE; a deferred pension revalued at more than
     * its in-payment basis moves the other way. A scheme on plain CPI throughout is byte-identical.
     * See board card 0035.
     * Previous bump 2026-09-05 (year-zero-receipt-funding): the year-0 purchase-funding waterfall could see the
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
    public const ENGINE_VERSION = 'finance-engine/lifetime-mortgage-redeemed-on-entry-to-care';

    /**
     * The draw order every scenario is forecast under unless one is named. THE one home for it:
     * {@see WithdrawalStrategyComparison::CURRENT} reads this constant rather than
     * repeating the value, so the "your current order" baseline every saving is measured against
     * cannot drift from the order the rest of the page is actually showing. FLAGGED (board card
     * 0075): the reader cannot choose the order, and this default is not disclosed to them.
     */
    public const DEFAULT_DRAWDOWN_STRATEGY = DrawdownStrategy::TaxEfficient;

    /**
     * Derive $what for $scenario once, and hand back the same answer to everything that asks
     * again while the scenario stands still.
     *
     * @template T
     *
     * @param  Closure(): T  $derive
     * @return T
     */
    private function remember(Scenario $scenario, string $what, Closure $derive): mixed
    {
        $stamp = $this->stamp($scenario);

        if (isset($this->memo[$stamp]) && array_key_exists($what, $this->memo[$stamp])) {
            return $this->memo[$stamp][$what];
        }

        // Derive first and index afterwards: deriving one thing derives others (settings needs the
        // household, the ladder needs both), and those writes have to land before this one.
        $value = $derive();

        $this->memo[$stamp] ??= [];
        $this->memo[$stamp][$what] = $value;

        if (count($this->memo) > self::MEMO_SCENARIOS) {
            array_shift($this->memo);
        }

        return $value;
    }

    /**
     * What the scenario's derivation depends on, as one string. It changes the instant any of it
     * changes, which is what makes a stale answer impossible rather than unlikely.
     *
     * The two form-state columns go in as their **stored ciphertext**, not their decrypted
     * contents: reading the ciphertext costs nothing (it is the raw attribute already in memory),
     * where decrypting to compare is the very work this memo exists to avoid. Encryption is
     * randomised, so a re-save of an identical form-state produces a different string and simply
     * misses the memo — the safe way round. `updated_at` rides along for the same reason the card
     * asked for it, but it is not load-bearing: it is stored to the second, so two saves inside
     * one second would carry the same value, and the ciphertext is what actually separates them.
     *
     * A what-if child's effective state is its parent's overlaid with its overrides, so the
     * parent's stamp is part of the child's. The walk is capped where
     * {@see Scenario::effectiveBuilderState()} caps it, so a cycle in `parent_scenario_id` stops
     * here as well instead of looping.
     *
     * The assumption set goes in WHOLE, not as its id. It is the one input that lives off the
     * scenario row: an admin editing the figures inside a shared set moves every scenario pointing
     * at it while every one of those rows stands still, and keying on the id alone would answer
     * with figures computed under the assumptions before the edit.
     */
    private function stamp(Scenario $scenario): string
    {
        $parts = [];
        $node = $scenario;

        for ($depth = 0; $node !== null && $depth < 10; $depth++) {
            $raw = $node->getAttributes();
            $parts[] = json_encode([
                $node->id,
                $raw['builder_state'] ?? null,
                $raw['overrides'] ?? null,
                $raw['base_tax_year'] ?? null,
                $raw['variant'] ?? null,
                $raw['updated_at'] ?? null,
                $node->assumptionSet?->getAttributes(),
            ]);
            $node = $node->parent_scenario_id === null ? null : $node->parent;
        }

        return md5(implode("\n", $parts));
    }

    /** The central best-estimate forecast: median death ages, expected returns, no sampling. */
    public function deterministic(Scenario $scenario): ForecastResult
    {
        return $this->remember($scenario, 'deterministic', fn (): ForecastResult => $this->deterministicWith($scenario, $this->assumptions($scenario)));
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
        return $this->remember($scenario, 'deterministicVariants', function () use ($scenario): array {
            $assumptions = $this->assumptions($scenario);
            $variants = $this->allVariantInputs($scenario);

            $forecaster = new DeterministicForecaster($this->config($scenario), new CohortLifeTable);

            return array_map(
                fn (array $variant): ForecastResult => $forecaster->forecast($variant['household'], $assumptions, $variant['settings']),
                $variants,
            );
        });
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
        return $this->remember($scenario, 'careStressVariants', function () use ($scenario): array {
            $assumptions = $this->assumptions($scenario);
            $variants = $this->allVariantInputs($scenario);

            $forecaster = new DeterministicForecaster($this->config($scenario), new CohortLifeTable);
            $stress = CareStressScenario::adverseDefault();

            return array_map(
                fn (array $variant): ForecastResult => $forecaster->forecastWithCareStress($variant['household'], $assumptions, $variant['settings'], $stress),
                $variants,
            );
        });
    }

    /**
     * The household + settings for EVERY housing strategy. One home for the sale/purchase
     * decomposition the care-free ladder, the care-stress ladder and the sustainable-spend search
     * each used to rebuild for themselves.
     *
     * @return array<string, array{household: Household, settings: ForecastSettings}>
     */
    private function allVariantInputs(Scenario $scenario): array
    {
        return $this->remember($scenario, 'variantInputs', fn (): array => $this->housingComparison($scenario)->variantInputs(
            $this->household($scenario),
            $this->settings($scenario),
            $this->assumptions($scenario),
            $this->housingAction($scenario),
        ));
    }

    /**
     * The historical sequence-of-returns stress test: run the plan through every eligible
     * past starting year (replaying that year's real UK returns + inflation), so the results
     * page can show how it would have fared starting into 1929 / 1973-74 / 2000 / 2007.
     * Deterministic (no Monte Carlo run needed), so it shows immediately like the ladder.
     */
    public function historicalBacktest(Scenario $scenario): HistoricalBacktestResult
    {
        return $this->remember($scenario, 'historicalBacktest', fn (): HistoricalBacktestResult => (new HistoricalBacktester($this->config($scenario), new CohortLifeTable))
            ->backtest($this->household($scenario), $this->assumptions($scenario), $this->settings($scenario)));
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
        return $this->remember($scenario, 'housingComparison', fn (): HousingComparison => new HousingComparison($this->config($scenario), new CohortLifeTable));
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
        $all = $this->allVariantInputs($scenario);

        $inputs = $all[$strategy ?? $scenario->effectiveBuilderState()['variant'] ?? 'stay_put'] ?? $all['stay_put'];

        return [
            'household' => $inputs['household'],
            'settings' => $inputs['settings'],
            'assumptions' => $assumptions,
        ];
    }

    public function config(Scenario $scenario): TaxYearConfig
    {
        return $this->remember($scenario, 'config', fn (): TaxYearConfig => TaxYearRegistry::for($scenario->base_tax_year, $this->household($scenario)->region));
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
        return $this->remember($scenario, 'assumptions', function () use ($scenario): AssumptionSet {
            $base = $scenario->assumptionSet?->toDto() ?? AssumptionSetLibrary::default();
            $overrides = $scenario->effectiveBuilderState()['assumptionOverrides'] ?? [];

            return AssumptionOverrides::apply($base, $overrides, $this->settings($scenario)->allocation());
        });
    }

    private function household(Scenario $scenario): Household
    {
        return $this->remember($scenario, 'household', fn (): Household => $scenario->toHousehold());
    }

    private function housingAction(Scenario $scenario): HousingAction
    {
        return $this->remember($scenario, 'housingAction', fn (): HousingAction => $scenario->toHousingAction());
    }

    /**
     * The run settings (start year, allocation, drawdown strategy, freeze-end year). Public
     * so the results page can read the blended real return the invested proceeds grow at
     * (`settings()->allocation()->blendedRealReturn($assumptions)`) for the assumptions panel.
     */
    public function settings(Scenario $scenario, ?DrawdownStrategy $strategy = null): ForecastSettings
    {
        return $this->remember(
            $scenario,
            'settings:'.($strategy?->name ?? 'default'),
            fn (): ForecastSettings => $this->buildSettings($scenario, $strategy),
        );
    }

    private function buildSettings(Scenario $scenario, ?DrawdownStrategy $strategy): ForecastSettings
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

        [$upratingBasis, $upratingUntilYear] = AssumptionOverrides::statePensionUprating(
            $scenario->effectiveBuilderState()['assumptionOverrides'] ?? [],
        );

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
            // How long the State Pension triple lock is assumed to hold, and the year it ends.
            // Absent = the full lock, so every scenario stored before board card 0038 reproduces
            // unchanged; the full lock is the optimistic branch, and is disclosed as such.
            statePensionUprating: $upratingBasis,
            tripleLockUntilYear: $upratingUntilYear,
        );
    }

    /**
     * The central deterministic forecast run under a specific withdrawal (drawdown) strategy,
     * on the same household + assumptions as {@see deterministic()}, so the withdrawal-sequencing
     * comparison can price each strategy on an identical basis. {@see WithdrawalStrategyComparison}.
     */
    public function deterministicUnderStrategy(Scenario $scenario, DrawdownStrategy $strategy): ForecastResult
    {
        return $this->remember($scenario, 'underStrategy:'.$strategy->name, fn (): ForecastResult => (new DeterministicForecaster($this->config($scenario), new CohortLifeTable))
            ->forecast($this->household($scenario), $this->assumptions($scenario), $this->settings($scenario, $strategy)));
    }
}
