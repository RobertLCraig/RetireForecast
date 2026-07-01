# Decisions: RetireForecast

Append-only log of decisions and their rationale, newest first. Do not rewrite history;
supersede an old entry with a new one that links back to it.

## 2026-07-01 — Tax-efficient withdrawal sequencing: full-capability build approved (Lane C)
**Decision:** Build **tax-efficient withdrawal sequencing across wrappers** ("fill the band") — the top item from the
2026-06-30 full-market competitive scan (docs/RESEARCH-competitive-gap-analysis.md, Cluster A). Rob approved the **full
capability** (not a reduced slice): a new **`Forecast\DrawdownStrategy::FillBands`** (draw personal allowance → CGT
annual-exempt-amount → ISA → basic-rate pension → rest), **Pension-Credit-aware** (never draw pension income that claws
Guarantee Credit back £-for-£ — read `Benefits\PensionCreditCalculator`), stepping around the **60% PA-taper** band,
with **planner-timed PCLS**, a **lifetime-tax £-delta** surfaced in Compare (a neutral number always + an advice-gated
steer behind `personal_use`), and a **search-optimiser** sequenced last. Delivered additively on
`PathProjector::fundShortfall`, each slice green. Full build order + rationale: **docs/PLAN-withdrawal-sequencing.md**.
**Why:** RF already owns the penny-accurate HMRC engine, so sequencing is the highest-value, most on-brand net-new item
— no UK consumer tool optimises the ISA/SIPP/GIA draw order and quantifies the £ saved (RightCapital-style). The
now-live Pension Credit means-test makes the household-specific interaction a real correctness point: a naive
band-filler would silently claw the benefit back (the completeness class of bug the project guards against).
**Status:** in progress. Built + committed (green): the engine core (`FillBands` fill-order in
`PathProjector::fundShortfall`, Pension-Credit-aware, + engine tests), the PA-taper (resolved by the ordering, no
code), and the £-delta computation (`App\Forecast\WithdrawalStrategyComparison`, reconciliation-tested). Remaining:
the results-page panel + the advice-gated steer, planner-timed PCLS, the optimiser. Coordinate on the shared
`PathProjector` (see HANDOVER "Multi-agent coordination").

## 2026-07-01 — Stress-test panel: historical sequence-of-returns backtest (Lane A)
**Decision:** The stress-test panel is **historical sequence backtesting** — replay each past year's
*actual* UK returns + inflation over the current plan ("how would this plan have fared starting into 1929 /
1973-74 / 2000 / 2007?"). This is the sector standard (Timeline, the 4% rule) and directly tests
**sequence-of-returns risk**, which our Monte Carlo does not isolate. Engine: `HistoricalSequenceDraws` (a
third `PathDraws` alongside deterministic + Monte Carlo) overlays a start year's real path onto the existing
`PathProjector`, falling back to expected returns beyond the data tail; `HistoricalBacktester` runs every
eligible start year and reports the survival rate + worst start; a results-page panel shows the % of ~140
historical starts survived, the worst start, and named crises. `RepresentativeDeathAge` was extracted so the
forecast and backtest share one median-lifespan rule. See [[2026-07-01 — What-if sliders (explore the levers) on the results page]] for the sibling exploratory path.

**Data source (this was the whole gate — research in docs/RESEARCH-stress-test-and-official-sources.md):**
- Rob's steer was "official source (ONS/FCA)". Finding: **there is no ONS/FCA historical asset-return series**
  (ONS = inflation + demography; FCA = illustration/stress *methodology*, not data). I recommended the Bank of
  England millennium dataset (OGL, shippable) and Rob picked it, but on **downloading and inspecting the actual
  file** it holds only a share **price** index + bond **yields** — **no equity total return / dividends**, which
  are ~half of long-run equity return. BoE alone cannot back a credible backtest. Correction surfaced to Rob.
- **Chosen: Jordà–Schularick–Taylor Macrohistory database ("The Rate of Return on Everything", R6)** — measured
  UK equity/bond/bill **total returns** + dividend yield + CPI, 1871–2020, peer-reviewed (QJE 2019). Baked as the
  sourced `HistoricalReturns` engine data class (generated from the file, not hand-typed; real returns derived as
  (1+nominal)/(1+inflation)−1), cited with `verified_on: 2026-07-01`.
- **Licence is load-bearing:** JST is **CC BY-NC-SA 4.0 (non-commercial + ShareAlike)**. Fine for the current
  **private, personal-use** tool; it is a **flagged PUBLIC-RELEASE BLOCKER** (documented in `HistoricalReturns`)
  the same way `config('compliance.personal_use')` flags the regulatory line: before any public release the data
  must be swapped for an OGL/commercially-licensed source (BoE prices + a licensed dividend series, or DMS) or
  removed. This is why BoE (OGL, but no total returns) and DMS/Barclays (accurate, paid) were both set aside.

**v1 simplifications (flagged):** house-price and salary growth stay at the assumption's expected real rates in a
backtest (the stress is on market returns + inflation); a start year is eligible only with ≥10 years of real data
after it (so the early, sequence-risk-critical window is always historical), the deep tail reverting to expected
returns; the historical inflation path runs against the *current* frozen-to-2031 tax thresholds (realistic, but a
1970s-inflation overlay drags hard early). **Status:** built (engine + panel), green. Panel pending Rob's browser sign-off.

## 2026-07-01 — Care-cost + ONS-refresh data sources (Lane A; ONS-refresh built later)
**Decision:** For Rob's "pull from ONS" on both: **ONS-refresh is fully ONS** (national + past/projected cohort
life tables map onto `CohortLifeTable`; ready to build). **Care-cost is only partly ONS** — ONS gives self-funder
stats + health-state life expectancy (care *entry timing*) but **not** weekly fees or the probability/duration of
needing care. Rob agreed to source those from **LaingBuisson** (fees, ~£1,300/wk residential, £1,600/wk nursing)
and **PSSRU/LSE** (probability + duration), each cited with `verified_on`, with ONS health-state life expectancy
for timing. Neither is built yet; sources locked. See docs/RESEARCH-stress-test-and-official-sources.md.

## 2026-07-01 — Annuitisation: convert part of a DC pot into a lifetime income (Lane A)
**Decision:** A DC pension can buy a **lifetime annuity** with part of its pot at a chosen age: the pot falls by the
purchase amount and, from that age, pays a guaranteed income = **amount × rate** for life. A new `AnnuityPurchase`
DTO (`atAge`, `amount`, `rate`, `escalation`, optional `survivorFraction`) hangs off `DcPension` (null = keep in
drawdown); `PathProjector` buys it once (reducing the pot) and pays the income each year. **Level** (escalation
`None`) is a flat nominal income (falls in real terms); any other basis **escalates with inflation** from purchase —
the same proxy the engine already uses for DB escalation in payment. A **joint-life** annuity (`survivorFraction`
set) continues to the surviving partner at that fraction after the annuitant dies; **single-life** stops. The income
is taxable and counts as assessable income for the Pension Credit test, so it stacks correctly with the rest of the
forecast.
**Key choices:**
- **The rate is a user input** (builder default a sourced **~7.2%**, a rough level joint-life-at-65 guide), so **no
  fabricated age/rate/health table lives in the engine** — it only multiplies the pot by the rate. This is the same
  discipline as every other figure carrying a source; a real quote is age/health-specific and belongs to the user.
- **Income maps to the existing `other_taxable` source**, which the `YearResult` doc already names as covering
  annuity income — so **no change to `INCOME_SOURCES`** (avoiding churn on the source list Lane B had just grown to 9,
  and any mapper/UI change). Completeness is still guarded (a test shows an annuity demonstrably reaches the forecast).
- **The purchase amount is treated as nominal at the purchase age**, matching how planned withdrawals already work
  (a v1 simplification, flagged). **Buying is not a taxable event** (the income is taxed as it arrives). The pot loses
  drawable value on purchase — economically correct (capital exchanged for income), and consistent with DB (an income,
  not a pot); the annuity's longevity value is not capitalised into wealth (v1, flagged).
- **Builder storage is sparse** (annuity fields stored only when annuitising), mirroring the include-flag / selling-costs
  pattern, so a scenario predating the feature and a no-op what-if record no delta. See [[2026-06-30 — What-if sliders (explore the levers) on the results page]] for the sibling "explore levers" path; annuitisation is a saved plan input, not a throwaway lever.
**Why:** annuitisation is the one remaining decumulation policy the engine could not express — a household choosing
certainty (a guaranteed floor) over flexibility (drawdown) is a core retirement decision, and the buy-vs-drawdown
trade-off is exactly what this tool exists to show. Built engine-first (framework-free, tested to the penny), then
the builder, each committed green.
**Status:** built (engine + builder). Remaining Lane A backlog: the stress-test panel (gated on authoritative sourced
historical sequences), the ONS-refresh script, care-cost assumptions.

## 2026-07-01 — Surface investment (capital) growth separately from investment income
**Decision:** The cashflow ladder now shows the year's **capital growth** (share/fund appreciation left inside the
pots) as its own figure, beside the existing **investment income** (interest + dividends paid out and taxed each
year). New `YearResult::investmentGrowth` (nullable Money, real terms); `PathProjector::growState` returns the
year's nominal capital growth and the projection loop attaches it **deflated by NEXT year's price level**, so it is
the real purchasing-power gain that matches the real wealth line's progression (not the inflated nominal figure).
The ladder column shows only when growth occurs, with a note distinguishing the two, and the CSV carries it.
**Why (Rob):** "surface where the gains come from (interest / share growth)". The ladder already showed investment
income, but the larger part of a real return — capital growth in ISAs / pensions / GIA — was invisible: it silently
raised the wealth line, so a reader couldn't see why wealth grew (or held) in a drawdown year. Surfacing it closes
the explainability gap and lets income + growth reconcile to the wealth change. Engine-tested that it is honest: an
ISA shows real capital growth while the same cash shows ~none (cash's return is paid out as interest income, not
capital — no double count). Also this session (a feature, no separate decision): **how-to-claim Pension Credit**
guidance on the results page (`ResultPresenter::pensionCreditGuidance`, gov.uk-sourced, shown only when the forecast
credits Pension Credit), because it is means-tested (must be applied for) and heavily under-claimed.
**Status:** active.

## 2026-07-01 — A surviving partner inherits the deceased's assets (the stranded-wealth bug)
**Decision:** On a death, the surviving partner **inherits** the deceased's assets; the projection
transfers them so they stay drawable. **Model:** spouses inherit IHT-free and a DC pot passes to the
beneficiary, so `PathProjector::settleEstates()` moves the deceased's **cash / ISA / GIA** (with a
**CGT base-cost uplift** to the value at death — the heir is taxed only on later gains) and the
**remaining pension pot value** to the **first living person**, once each. The deceased's **scheduled
withdrawals and contributions do not carry** (they were the deceased's decisions — no schedule re-runs
on the heir), and **only the deceased's own assets move** (the survivor's own pot is never lost or
double-counted — Rob's ownership / no-double-dip constraint). On the **last** death there is no
recipient, so nothing transfers (terminal estate).
**Why (the bug it fixes):** `fundShortfall`'s drawdown skips a dead owner's accounts (`if (! $alive)
continue`), yet `liquidWealth` still **summed** them — so on the first death the deceased's savings,
investments and (for sell variants) the **entire** invested sale proceeds (which
{@see Housing\HousingComparison::withHousing} dumps into `persons[0]`) became **counted-but-undrawable**.
The survivor couldn't reach the money, `essentialsMet` went false, and the run read as "ran out" at the
first death with a full pot sitting idle. It hit **every couple forecast** — understating how long money
lasts and inflating "chance of running out" (the flagship number). Surfaced by the V2 review: the Monte
Carlo said "run out by 2032" while the deterministic Compare said 2044 (same variant, same projector) —
the gap was just the older partner's sampled-vs-median death year, each stranding the proceeds. With the
fix the two reconcile (both deplete 2039 on the repro household) and the proceeds are actually drawn
down. Pinned by `EstateInheritanceTest` (survivor spends the inherited cash; the pot is conserved and
ownership-respected). **v1 caveats (flagged):** inherited pension is drawn as taxable income (no
pre-/post-75 beneficiary split); no IHT is charged at the second death; the transfer is annual-granular
(the death year's partial income is not apportioned — the existing granularity).
**Status:** active.

## 2026-07-01 — V2 pressure-test: deferred-refinement resolutions (let-home capital, first-class tax-free-benefit type, income-ends-on-sale declined)
**Context:** working through the refinements deferred from the forced-housing-event workstream
([[2026-06-30 — Forced-mortgage pressure-test → a 3-feature workstream (benefits-in-forecast, mortgage-redemption event, feasibility flags)]]).
Three resolutions, each committed green:
- **A let home is assessable capital.** When the primary residence is **let** (the household lives elsewhere — the
  "let out & rent" strategy), its equity is no longer the exempt main residence, so `PathProjector` adds it to the
  Pension Credit **assessable capital**. Letting the flat therefore erodes benefit exactly as selling does (on V2:
  £0 Pension Credit let-out vs ~£41k kept when they occupy it). A new `Property::isLet` flag drives it.
- **The tax-free benefit is a first-class income type, not `type: other` + a flag.** The input-clarity plan
  ([[2026-06-30 — Input-expectation clarity: the input layer must catch a mis-entry, not model it away]], and
  DATA-MODEL's planned (D)) was to map a tax-free benefit to `IncomeStream{type: other, taxable: false}`. **Upgraded**
  to a dedicated `IncomeStreamType::DisabilityBenefit` whose tax-free-ness is **structural**: the assembler (the single
  conversion boundary) forces `taxable = false` regardless of the row's flag. Rationale: a mis-entered *taxable* DLA is
  a **double** error — income-taxed **and** counted as Pension Credit assessable income (docking benefit) — so making
  the type itself guarantee the disregard prevents both, where a mere default-untick could still be overridden. The PC
  assessment already counts only taxable income, so the disregard needed no calc change.
- **"Income ends when a named property is sold" (the planned `endsOnSale` flag) is declined.** DATA-MODEL's planned (D)
  last clause proposed linking an `IncomeStream` to a property so a sold flat's rent stops in the sell variants. **Not
  built:** the model has one property slot and income streams don't reference a property; rental income in a sell
  variant is, by construction, from a *different* (unmodelled) property, and the "let out & rent" rent is from the flat
  the household *keeps*. There is no "income tied to the sold home" case in the current single-property model, so the
  flag would add structure to fix a case that can't arise. Revisit only if multi-property (Lane D) lands.
**Still deferred (open):** stopping the bundled mortgage *payment* after a repay-from-capital redemption (the mortgage
payment is a `while_owning_home` cost that today keeps charging after the balance is cleared — a genuine gap needing a
`while_mortgaged` condition); the in-place forced-sale model. See HANDOVER "In progress".
**Status:** active.

## 2026-07-01 — What-ifs are the only way to express a variation; the individual report is single-strategy
**Decision:** An **individual forecast report = one scenario, one strategy**, read top to bottom. Every *variation*
— a different housing strategy (stay / buy / rent / let-out), a lever change (retire / spend / return / longevity)
— is a **specialised what-if scenario** (a delta-child of the base) that lives under the base and is compared on the
**Compare** page. The results page no longer bakes variations in:
- the **3-way headline cards** become a single card for the scenario's own strategy;
- the **"By housing strategy" comparison chart** + its table are **removed from the report** — the comparison is on
  Compare, across what-if scenarios, and the Compare burndown gains the same **milestone annotations** the
  single-scenario charts carry (so the comparison graph has the event context);
- the **cashflow-ladder strategy switcher** is gone — the ladder shows the scenario's own strategy, labelled;
- the **"Explore the levers" live sliders** (a throwaway, unsaved what-if baked into the page) become a
  **"Build a what-if"** control: set the levers, then **save** them as a delta-child (the QuickWhatIf pattern), to
  compare on Compare. The two charts were also moved to the **top** of the report and annotated with the milestones.
**Why (Rob):** "part of the reason the UI is so confusing is the conflation between partial what-ifs being built into
an individual forecast, where those should be specialised What-IF scenarios." A report doing double duty — *this
forecast* and *explore variations* — is the confusion; separating them (one clean report; variations as nameable,
saved, comparable scenarios) resolves it, and the delta-child + Compare machinery already existed to express it.
**Supersedes (for the report only):** the in-report 3-way comparison of
[[2026-06-30 — One-click "compare buy vs rent" (delta-child what-ifs + per-variant Compare)]] and the per-variant
ladder of [[2026-06-29 — Built #6: per-variant deterministic cashflow ladder + a results-page "on this page" nav]] —
the per-variant *engine* projection still exists and now drives Compare; only the report stops showing the 3-way
comparison. The what-if sliders of [[2026-06-30 — What-if sliders (explore the levers) on the results page]] are
superseded by the save-as-a-what-if control (no throwaway live preview).
**Also (Rob's ask):** a **"Let out & rent elsewhere"** strategy is added as a generated what-if (keep the flat, let
it, rent somewhere cheaper) — see its own entry.
**Status:** report strip + Compare annotations + sliders→make-a-what-if built, suite green; pending Rob's browser
sign-off. The let-out what-if + the engine treatment of a let home as assessable capital follow.

## 2026-06-30 — Input-expectation / guided-entry clarity (surfaced by the V2 pressure-test)
**Decision:** The V2 data foot-guns the pressure-test exposed are less user error than **UI-communication gaps**
(Rob: "these flags show where the input hasn't matched the expectation of usage, and where the UI needs to
communicate how to use it"). Each mis-entry maps to a concrete builder improvement:
- **Income pay-frequency.** DLA was entered as "£749/month" when the DWP pays **£600.00 per 4 weeks** (the real
  figure ≈ £9,747–£10,119/yr), and the rent reads £1,650/yr (almost certainly a monthly figure typed as annual).
  → a **pay-frequency selector** (weekly / 4-weekly / monthly / annual) on every per-period money input, converting
  to the stored annual figure. **4-weekly matters** specifically because DWP (State Pension, DLA/AA/PIP) pays that way.
- **Income type vs taxability.** DLA was entered as a **taxable "rental"** stream (double-counting, and taxed). →
  offer a **"tax-free benefit (DLA / AA / PIP)"** income type that sets `taxable = false`, with examples, so a
  disability benefit can't be mis-typed as taxable rental.
- **Missing retirement age.** An employed person with a blank `plannedRetirementAge` is modelled **earning for
  life** (here, +£30,000/yr forever → a ~£700k overstatement). → flag it in the builder / input-sanity notes.
- **One-off cost scope.** A one-off (the £[redacted] convert-to-residential deposit) was charged across **all** housing
  variants, including sell/rent. → let a one-off declare **which path(s)** it applies to (pairs with the
  feasibility-flag + mortgage-redemption work).
These extend the existing input-sanity notes [[2026-06-29 — Adviser-legibility: input-sanity notes (explain a "wild numbers" result back to its input)]]
and the feasibility flags of [[2026-06-30 — Forced-mortgage pressure-test → a 3-feature workstream (benefits-in-forecast, mortgage-redemption event, feasibility flags)]].
**Why:** the engine is only as trustworthy as its inputs, so the project's single-definition / no-silent-failure
discipline (applied so far to *outputs*) must reach *inputs* — a mis-entry should be caught and explained **at
entry**, not silently produce a plausible-but-wrong forecast. The V2 case is the proof: four ordinary-looking
entries inflated the result to ~£700k+ until corrected against the real DWP figures.
**Status:** recorded; build folds into the feature workstream (input-clarity track).

## 2026-06-30 — Forced-mortgage pressure-test → a 3-feature workstream (benefits-in-forecast, mortgage-redemption event, feasibility flags)
**Decision:** Pressure-testing the engine against a **real forced-housing case** (the "V2" couple Rob has been
building) set the next workstream. The case: both about to be retired (one retired on **DLA**, one in her final
working year); they **live in a flat that is on a buy-to-let mortgage** — the occupation is itself the breach, so
the BTL cannot continue, and a residential remortgage fails on age + income; the BTL is **due for redemption
December 2026** with no extension, and converting to residential needs **~£100k** they don't have, so the realistic
outcome is sell-or-repossession. Equity ≈ £[redacted] (a stale £[redacted] valuation, 13+ weeks unsold) − £[redacted] ≈ **£[redacted] gross
/ ~£[redacted] net** ([redacted]-yr lease, ~£4k to extend; **partial PRR** — secondary then **primary ~4–5 yrs**, joint names).
Guaranteed income floor ≈ **£[redacted]/yr** (State Pension ~£230/wk + ~£188/wk + **DLA £749/mo, tax-free**), plus one
**£[redacted]** DC pot. **Finding:** the engine already answers the *core* — buy-cheaper-outright vs sell-and-rent on
identical seeds, partial-PRR CGT (occupation-driven, joint owners — near purpose-built for this flat), the
income-floor + per-year surplus/shortfall + safety floor, and the longevity horizon. But the **lump-sum tax shock,
the flagship output, barely applies** (a £[redacted] pot is inside the personal allowance), while the three things that
actually decide this couple's path are **not modelled in the forecast**:
1. **Means-tested benefits are a standalone snapshot, not in the cashflow.** {@see Benefits\CapitalAssessment}
   correctly models the pensioner capital tariff (£10k disregard, £1/wk per £500, the £16k Council Tax / Housing
   Support cliff) but is referenced **only inside `Benefits/` + an audit page** — never by `PathProjector` /
   `app/Forecast`. So the forecast does not **credit** Pension Credit Guarantee Credit / Council Tax Support as
   income, does not **erode** it year-by-year as capital or income change, does not fire the **£16k cliff**
   dynamically, and models no **disability addition** or **DLA/AA passporting**. For an asset-poor, low-income,
   disabled household this interaction is *the* decision: sell → hold ~£130k → lose Council Tax Support + most
   Pension Credit; keep / buy-cheaper → little assessable capital → keep them. **DLA income itself already reaches
   the forecast** as a tax-free `IncomeStream` (the completeness rule, {@see PathProjector} L250-251); the gap is
   the *award* + the capital cliff.
2. **No mortgage maturity / redemption / refinance concept.** A mortgage is a perpetual `outstandingMortgage` that
   surfaces only as a lump at sale, plus an ongoing `while_owning_home` cost charged **forever**. So the engine will
   happily project a **"stay put" path that is physically impossible** here (a BTL that must be redeemed in months),
   and never flag it — exactly the plausible-but-wrong failure the project guards against. Today the real choice can
   only be faked by hand-adding a one-off cost in a what-if (the £100k convert-to-repayment what-if Rob already hit
   in [[2026-06-30 — What-ifs can add and remove items (delta represents structural changes)]]).
3. **No feasibility flags.** {@see Housing\HousingComparison} silently **floors a buy price above net proceeds**
   ("downsizing is assumed") — but ~£130k may not buy a mortgage-free replacement, and "stay" needs £100k they
   don't have. These impossibilities should surface as **input-sanity notes**, not be modelled away.

**The workstream (Rob: "do all of it; I care about the final result, not the order"):**
- **(A) Means-tested benefits in the live forecast.** A sourced engine `PensionCreditCalculator` (Guarantee Credit
  tops assessable income up to the Standard Minimum Guarantee; + Severe Disability / Carer additions; tariff income
  from capital reuses `CapitalAssessment`), wired into `PathProjector` as a **household-level income source each
  year** (new `YearResult` income source `means_tested_benefit`), eroding as capital/income change and firing the
  £16k cliff in-projection. Per-source **completeness** test (the benefit demonstrably reaches the result) +
  **reconciliation** (award + tariff math). Council Tax Reduction is locally-set, so v1 models the **£16k cliff /
  Pension-Credit passport** rather than a precise CTR award (flagged). A **disability flag** is added to drive the
  Severe Disability addition + the DLA/AA passport.
- **(B) Mortgage-redemption event** as first-class state: a redemption/maturity **year** on the home + a
  **maturity action** {refinance at a rate · repay from capital · forced sale}, handled in `PathProjector`
  (track the mortgage balance; at maturity apply the action — inject capital, switch to a repayment cost, or
  transition to the sell transform). Generalises to interest-only maturities and fixed-term ends.
- **(C) Feasibility flags:** when buy price > net proceeds, or "stay" needs capital not held, raise an input-sanity
  note instead of silently flooring.
- **Validation:** a runnable forced-mortgage scenario exercises A–C. The committed test fixture is **synthetic**
  (the "no hardcoded client data" rule); the couple's real figures are run only locally (throwaway), never committed.

**Why:** the project exists for exactly this "older couple, forced housing decision" problem (PRD flagship), and
pressure-testing it on a real case is the intended way to find where it's thin. All three gaps are **general**
(the downsizing benefit-trap, interest-only maturities, infeasible-option flags), not one-off hacks. Recording the
direction now (Rob's ask to update the docs) so the multi-step build stays anchored; each feature lands green with
its own DECISIONS entry + PLAN/DATA-MODEL update.
**Sources (benefits figures — to verify against gov.uk on build, per the verified_on discipline):** gov.uk
**/pension-credit** (Standard Minimum Guarantee single/couple; Severe Disability & Carer additions),
**/council-tax-reduction**, **/disability-living-allowance-adults** & **/attendance-allowance** (tax-free, not
means-tested; the passport). Capital rules already verified 2026-06-27 ({@see TaxYear\BenefitsParameters}).
**Status:** direction recorded; build sequenced next (A → C → B, value-first), each green. **Supersedes nothing.**

## 2026-06-30 — What-if sliders (explore the levers) on the results page
**Decision:** An "Explore the levers" panel with live sliders — retire ± years, spend ± %, investment return ±
percentage points, live ± years — runs a **throwaway deterministic re-forecast** with the adjustment applied and shows
the outcome (money lasts / runs short, spendable wealth at end, spending met). Exploratory and **never saved** (build a
what-if to keep one). Applied via the same levers the quick what-ifs + editable assumptions use (on a transient
scenario through `ScenarioForecaster::deterministic`), so a slider and a saved what-if move the forecast identically.
**Why (Rob):** "err on the side of more flexibility to change values; rebuilds are okay." Sliders make sensitivity
tangible without committing a what-if.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Salary is prorated in the retirement year, not dropped
**Decision:** The engine paid full salary while `age < plannedRetirementAge` and **nothing** from the year the person
reached that age — dropping the whole final year's earnings. It now **prorates** the retirement year: the person stops
on their birthday (when they turn the age), so salary (and its NI) is **birth-month ÷ 12** of the year. Uses the DOB
already captured — no new input.
**Why (Rob):** "salary stops at the point of retirement, so would not be paid for a full calendar year if you leave in
July." The old whole-year drop was conservative but wrong; true month-level proration needs the retirement month, which
the birthday approximates from existing data. An explicit retirement-*month* override remains a possible refinement.
**Status:** built, suite green.

## 2026-06-30 — Per-year surplus/shortfall + a configurable usable-money safety floor
**Decision:** The cashflow ladder classifies each year as **surplus** (regular income covers spend), **drawing** (dipping
into savings to meet spend) or **shortfall** (spend not met) — on **usable money** — and flags any year usable funds fall
below a **safety buffer** (default **2 months of essentials**, user-configurable in the Spending step via
`Scenario::safetyBufferMonths()`, passed to `ResultPresenter::ladder`). A headline says whether usable money stays above
the buffer, dips below it (year), or runs out (year); rows are tinted by status. This **replaces** the academic "neutral
diagnostics" (withdrawal/critical-yield/replacement-rate) backlog idea, which Rob found unhelpful.
**Why (Rob):** "highlight years where they have a shortfall and years where they have a surplus … I'm more interested in
usable money than total net worth … they HAVE to pick a path that never drops below [a floor of] usable funds (2×
monthly essentials in my view)." The buffer is configurable because the right reserve is personal.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Source-freshness guardrail for the verified_on discipline
**Decision:** A **`figures:freshness`** command (over a pure, unit-tested `App\Finance\FigureFreshness`) reports each
supported tax year's gov.uk verification date and **flags any verified more than `--months` ago (default 12)**, exiting
non-zero so CI or a periodic run catches aging statutory figures. `TaxYearRegistry::SUPPORTED_TAX_YEARS` is the single
source of the year set.
**Why:** the project's trust spine is "every figure cites a source + verified_on"; this extends the one-off gov.uk
verification pass into an ongoing guardrail ("verified once" → "noticed when it ages"). Built as a **command, not a
phpunit test**, so the check is not date-dependent/flaky; the date arithmetic is unit-tested against a fixed reference.
**Status:** built, suite green.

## 2026-06-30 — Longevity distribution surfaced from the Monte Carlo (first post-v1 backlog item)
**Decision:** The Monte Carlo now surfaces a **longevity distribution** (a `LongevityDistribution` on `SimulationResult`),
read off the **same joint-life mortality sampler** the wealth paths already run, framed around the **last survivor** (how
long the money must last for a couple): last-survivor age p10/p50/p90, the planning horizon in years (p50 + p90, the
"plan to roughly here" figure), and the probability at least one of the household reaches **95 / 100**. Shown as a neutral
**"How long the money may need to last"** results-page panel (descriptive, not a recommendation). Nullable on
`SimulationResult` so runs persisted before it rehydrate as null (mapper back-compat).
**Why:** the engine already sampled per-path death ages but only used them for cashflow; surfacing the spread is the
cheapest high-value output (it answers "how long might we live / how long must the money stretch", and pairs with the
longevity lever + the deterministic modelled-death age). First of the post-v1 "outputs that exploit results we already
compute" backlog (docs/PLAN.md "External review triage").
**Status:** built, suite green, pending Rob's browser sign-off (needs a completed run — local DB has 0).

## 2026-06-30 — Partial Private Residence Relief CGT on selling a let former home
**Decision:** Capital Gains Tax on selling a former main home that was also let is now modelled (it was hard-coded to
£0). It is driven by **occupation, not the mortgage type** (gov.uk HS283): relief = gain × (main-residence months +
final 9 months) ÷ months owned; the remainder is chargeable, less **each owner's £3,000 annual allowance**, at **18%**
(basic band) / **24%** (higher). Lettings relief is **shared-occupancy only since 6 April 2020**, so a moved-out
whole-property BTL gets none. CGT is **per-individual**, so a jointly-owned home **splits the gain across the owners**
(two allowances + each their own rate). A `CgtHistory` on the engine `Property` (null = full PRR / £0, the common case)
carries it; the builder captures it via a **"Capital gains on sale" wizard** (purchase price, year bought, buying/
improvement costs, jointly-owned + higher-rate toggles, a lived-in vs let **period timeline**) with a live readout, and
the sale waterfall shows the working. Supersedes the "main-home CGT taken as £0" v1 simplification of
[[2026-06-24 — Modelling depth and scope (from approved plan)]].
**Why (Rob):** for a couple selling a former-BTL, £0 CGT is wrong and overstates the proceeds. What determines the
relief is whether they **lived in it as their main home** (occupation), not the mortgage — so living in a home on a BTL
mortgage still counts as main-residence for those months.
**Sources (links):** gov.uk **HS283** (Private Residence Relief); **/tax-sell-home** (+ /absence-from-home,
/let-out-part-of-home); **/capital-gains-tax/rates** (rates + £3,000 AEA, already in the engine, verified 2026-06-27).
**Caveats (flagged in code):** deemed-occupation absences are entered by hand (mark a qualifying absence as "main home"),
not auto-computed; one 18%/24% rate per owner (not a split of a single owner's gain across the band boundary from exact
income); shared-occupancy lettings relief not modelled; the timeline is year-granular (the final 9-month exemption is
still applied exactly).
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — What-ifs can add and remove items (delta represents structural changes)
**Decision:** A delta-child what-if may now **add or remove a list row** (a person, pension, account, income,
one-off cost or pension withdrawal), not only change existing values. The delta stores an **added row whole** at its id
path (`oneOffCosts.<id>` => the row map) and a **removed row** as a sentinel (`accounts.<id>` => `BuilderStateDelta::REMOVED`);
`merge()` appends the adds and drops the removals. An add is kept distinct from an **orphaned value override** (a leaf
whose row the base later deleted) because an add carries the whole row while a value override is a leaf path — so
orphan detection (`orphans()`) still works. The old `structurallyDiffers()` guard and the "A what-if only changes
values…" save refusal are **removed**. The builder's change-highlight now pairs base rows by **id** (not index), so
add/remove/reorder no longer mis-highlights. This **supersedes** the value-only constraint of
[[2026-06-25 — Phase C2 delta-child what-ifs]] (the storage limitation, not the single-source principle).
**Why (Rob):** the refusal "made no sense" — it blocked a legitimate what-if (add a one-off **mortgage deposit** to
model converting a buy-to-let to a repayment mortgage and stay). The block was a storage limitation leaking to the user
as a rule; a what-if is exactly where you explore "what if we also had / dropped this". The base stays the single
source (the child is still a sparse delta, edits flow through), so the C2 principle holds — only the artificial
value-only restriction is lifted.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Personal-use advice mode (the education/guidance line, flagged for later)
**Decision:** While RetireForecast remains a **private, local-first tool for the owner's own use** (not a public
release), the education/guidance-only posture is **relaxed** so it can give the best possible *direct* advice (Rob:
"flag the education line in the code so we can come back to it later; for now focus on giving the best possible
experience and advice for personal use, not public"). The single switch is **`config('compliance.personal_use')`**
(default **true**): when true the `interpret` Gate allows everyone (no admin grant) and the walled-off
`App\Compliance\Interpretation` layer's advice-style readouts show — including the new buy-vs-rent **"why" narrative**
({@see Interpretation::compareNarrative}) that ranks the compared plans and says which to lean towards. This
**supersedes, for personal use only**, the public guidance-only stance of [[2026-06-24 — Regulatory posture: guidance only]]
and [[2026-06-25 — banned-phrasing partition]] — it does not remove them.
**Why:** personal pension/drawdown advice is FCA-regulated, so the guidance-only posture is right for a public release;
but for the owner's own decision-support there is no regulatory bar, and a tool that won't say which option is stronger
is needlessly coy. Keeping it behind ONE documented config key (the "flagged line") makes the relaxation reversible and
auditable: flip `personal_use` to false and the full partition (banned-phrasing lint + per-user `can_interpret` grant)
re-applies. **The suite runs with the flag false** (the public posture stays the tested default); personal-use mode is
exercised by opt-in tests. **Before any public release: set `COMPLIANCE_PERSONAL_USE=false`.**
**Status:** active (personal-use mode on). Marked in code: config/compliance.php, the `interpret` Gate in
AppServiceProvider, CLAUDE.md.

## 2026-06-30 — One-click "compare buy vs rent" (delta-child what-ifs + per-variant Compare)
**Decision:** A **"Compare buy vs rent"** button generates the alternative housing strategies for a base as ordinary
**delta-child what-ifs** (variant-only overrides via `BuyVsRentCompare` + `BuilderStateDelta::diff`, the QuickWhatIf
pattern) and opens Compare. Only **meaningful** strategies are offered (buy needs a buy price, rent needs an annual
rent; the base's own strategy is skipped), and a strategy that already has its generated child is not recreated (no
duplicates on repeat clicks). **`ScenarioCompare` now projects each plan on its OWN variant** via the #6 single source
`deterministicVariants($plan)[$plan->variant->value]` (was the raw stay-put `deterministic()` basis), so the
buy / stay / rent columns actually differ instead of showing identical numbers under different labels.
**Why (Rob):** chose one-click compare over leaving the always-on 3-way comparison, or fully focusing the report on one
strategy. The results page already compared the three strategies, but baking them into every report is what the plan
moves away from; as deliberate what-ifs they are nameable, independently editable (e.g. a different rent assumption)
and read via the existing Compare infra. The Compare-basis fix was required for correctness — without it the
comparison was a mirage (identical figures under different labels).
**Status:** mechanism built, suite green, pending Rob's browser sign-off. **Next decision:** the per-option
plain-English **"why"** narrative (rule-based from the figures/milestones, lint-safe / guidance-only).

## 2026-06-30 — Per-line include/exclude toggle for spend lines (real-time cost toggles, #7)
**Decision:** Each spend line gains an **"Include this cost in the forecast"** checkbox. Switching it off keeps the
line in the form-state (so it can be switched back on) but excludes it from **every** forecast total — the assembler
drops excluded lines once in `household()`, so essential, discretionary, contingent costs and saved self-investment all
exclude them uniformly; the live preview moves as you toggle. An **absent flag means included** (back-compat); the flag
is **stored only when a line is off** (sparse), so a scenario predating the toggle and a what-if that changes nothing
record no spurious delta. Rob chose the **persisted** toggle (saved with the scenario) over an ephemeral preview-only
mode. This is workstream item #7; "real-time" was already delivered by the live preview, so the toggle is the
incremental affordance ("what if I drop this cost?" without deleting it).
**Why:** a quick on/off is friendlier than zeroing or deleting a line (and reversible), and pairs with the live
preview for instant feedback. Filtering once at the assembler boundary keeps the exclusion from leaking into one total
but not another (completeness — the sibling of reconciliation).
**Status:** built, suite green, pending Rob's browser sign-off. **Completes the editable-assumptions workstream
(slices a–e).** Next: buy-vs-rent as a deliberate what-if/Compare.

## 2026-06-30 — Per-line cost-condition override exposed in the builder (completes option b)
**Decision:** Each spend line in the builder gains an **"Applies"** control — *Auto* (classify by description),
*Always*, *Only while you own this home*, *Only while you are working* — the per-line override that option (b) of the
contingent-cost fix had specified but not yet surfaced (the engine already read `condition` from the form-state; only
the control was missing). On *Auto* a hint shows what the label infers, single-sourced from the same
`HouseholdAssembler::autoCondition()` the forecast uses. Hidden for saved self-investment (never a contingent cost).
**Why:** auto-classification handled the common labels, but a user must be able to pin an unusual line (e.g. a mortgage
they will keep, a cost that ends at retirement) without renaming it to trip the classifier.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Selling costs are a per-component breakdown, each on a %/£ basis
**Decision:** The single "selling cost %" is replaced by a breakdown of named components — **estate agent**,
**legal/conveyancing**, **EPC & removals** — each entered on the basis its real-world quote uses: a **% of the sale
price** or a **flat £**. The basis is the value's type in the engine (`SellingCostComponent` holds a `Percent|Money`,
resolved against the sale price); their sum is the total netted off the proceeds, and `HousingProceeds` carries a
**reconciled breakdown** (sum of components == total, asserted). No components → the engine's existing 2% default, so
untouched scenarios are unchanged; the legacy single `sellingCostRate` maps back-compat to one estate-agent component
(total preserved), and the two shapes never co-persist (one home per figure). Defaults: agent 1.25%, legal £1,500,
EPC & removals £800 — editable assumptions, not statutory figures.
**Why (Rob):** "does it have to be fixed as % or £? Some scenarios will be % and some will be flat fee — that's just
how the world works." Estate agents quote a percentage, conveyancing quotes a flat fee; forcing a single basis
misstates one of them and was a foot-gun in the browser walkthrough (a 20%-not-2% entry).
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — Live in-builder preview (verdict + end wealth) and the modelled age at death
**Decision:** The builder gained a sticky **live preview** — one cheap deterministic forecast run on a transient
scenario assembled from the current form-state (never saved), recomputed each round-trip — headlining the
does-the-money-last **verdict** plus **spendable / total wealth at end** (Rob chose verdict + end wealth over either
alone). It invites completion while the inputs are too incomplete to forecast. The same forecast drives a per-person
**modelled age at death** shown beside each lifespan lever (from `ForecastResult::deathCalendarYears`), so a
"peer / +10 years" setting resolves to a visible age and year.
**Why:** the wizard gave no feedback before a full Monte Carlo run; ProjectionLab's "edit and watch it move" is the
free-tool pattern (docs/RESEARCH-editable-assumptions-ux.md). Single-sourced from `ScenarioForecaster::deterministic()`
so the preview can never drift from the full run; pure server render (no JS), so it is CSP-safe and progressive.
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-30 — The builder highlights a what-if's inputs that differ from the base (and shows the base value)
**Decision:** When editing a what-if in the builder, each input whose value differs from the base plan is **ringed in
amber** *and shows the base value it diverged from* ("was £18,000"), with a one-line "fields you change from the base
are highlighted" banner — so the difference is obvious *and* the original figure is visible while editing (Rob: "would
be good to see the original figure that we have diverged from"). The server computes the changed form-state leaves
mapped to their base value (`ScenarioBuilder::changedFromBase()`, a positional diff of the live `builderState()` vs the
base's `effectiveBuilderState()`, **index-based** so the keys match each input's `wire:model`; base values formatted by
the shared `WhatIfChanges::formatValue()` so the builder hint and the results-page changes format identically), renders
them on the form (`data-builder-diff` + a `data-changes` object), and a bundled script (`resources/js/builder-diff.js`)
rings each matching input (`.builder-diff-changed`) and shows its base value via the field wrapper's `::after`
(`.builder-diff-field[data-original]`) — **not an injected node**, so it never confuses Livewire's morph.
**Why (the load-bearing choice):** there are ~70 inputs across the wizard, so annotating each one server-side was a
non-starter (huge, fragile diff). Instead one server-computed set + one script that matches inputs by their existing
`wire:model` path covers **every** input uniformly, including ones added later, with four small files touched. It is
**pure progressive enhancement** (the form is fully usable without JS; the ring is visual-only), so JS-only is the
right tradeoff here — unlike a result figure, a highlight carries no data. CSP-safe (bundled, not inline; the paths
travel in a data attribute, not an inline script) and morph-aware (re-applied on the Livewire `commit` hook, like
`toc.js`, since a morph rewrites inputs from server HTML that has no ring). The diff is **positional** because a
what-if child cannot reorder/add/remove rows (the delta rule), so index positions align with the base; the
auto-generated name and the wizard step are excluded (not real input changes). On first load of an existing what-if
the changed fields highlight immediately; live-as-you-type refresh follows the deferred `wire:model` round-trips.
[[2026-06-29 — A what-if highlights what it changed from its base (results panel, dashboard tags, Compare chips)]] [[2026-06-29 — One-click "quick what-ifs" (retire later / live longer) generated as ordinary delta-children]]
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-29 — One-click "quick what-ifs" (retire later / live longer) generated as ordinary delta-children
**Decision:** Added **one-click what-if presets** for the two questions a reader most often asks of a forecast —
**"Retire 2 years later"** and **"Live 10 years longer"** — as buttons on the base's results page and on each
dashboard base row. Each posts to `QuickWhatIfController`, which uses `App\Forecast\QuickWhatIf` to edit the base's
people (retire-later bumps each *working* person's `plannedRetirementAge` by 2, clamped to the builder's 50–80;
live-longer moves each person onto a +10-year `offset_years` longevity lever, relative to whatever the base already
models) and stores the result as an **ordinary delta-child**, then opens its results.
**Why:** the what-if highlighting made the gap obvious — exploring "what if we retire/live longer" shouldn't need a
full rebuild. Generating the child through **`BuilderStateDelta::diff()`** against the base (not a hand-written
override map) is the load-bearing choice: the delta is automatically **minimal** (only changed leaves) and
**structurally identical** to the base (it only retunes existing people, never adds/removes a row), so a quick
what-if is byte-for-byte the same shape as a hand-built one — it shows its changes through `WhatIfChanges`, compares,
and edits like any other. A preset that would change nothing (e.g. a lone retiree for "retire later") **builds and
creates nothing** and says so (no empty what-if, no silent no-op); repeated presets get distinct names; the endpoint
is owner-scoped. The longevity preset is the first UI use of the existing per-person longevity lever (the
editable-assumptions plan will surface it directly too).
[[2026-06-29 — A what-if highlights what it changed from its base (results panel, dashboard tags, Compare chips)]] [[2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if]]
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-29 — A what-if highlights what it changed from its base (results panel, dashboard tags, Compare chips)
**Decision:** On Rob's ask ("what-ifs need to highlight what's changed from the base, and add these as tags in the
dashboard"), a delta-child what-if now **shows its `overrides` as readable changes** in three places: a "What this
what-if changes" **panel** at the top of the what-if's results page (each change as **base → new**, plus a "what-if
of <base>" line in the header), compact **change tags** on each what-if row in the **dashboard**, and per-plan
**change chips** in the **Compare** table. One presenter, `App\Forecast\WhatIfChanges`, turns the sparse override
map into `{label, from, to}`: the base value an override replaces is read back through a new
**`BuilderStateDelta::valueAt()`** (the read mirror of `setPath`, descending maps by key and row-lists by stable
id), and each dot-path is humanised — top-level fields, assumption/housing/property figures, and **list rows named
by their own label/identity** ("Essentials · amount", "DC pension · current value", "P1 · gross salary"). Money is
shown as £, rates with %, enums readably; meta fields (the auto-name, the wizard step) are excluded.
**Why:** trust-through-explanation (the workstream's governing principle) applies to what-ifs too — a what-if that
looks identical to its base except for buried numbers can't be reasoned about. Reusing the existing **`overrides`
delta** as the single source (not a separate "what changed" store) keeps one home per fact: the highlight is a pure
projection of the delta, so it can never drift from what the what-if actually overrides, and a base edit flows
through. Orphaned overrides (a base row the child still targets) are surfaced in the panel too (no silent drop).
[[2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if]]
**Status:** built, suite green, pending Rob's browser sign-off.

## 2026-06-29 — Built the editable-assumptions layer (core): a user-derived custom set from a sourced preset
**Decision:** Built the first slice of the "everything user-editable" direction — the **economic assumptions** are
now editable in the builder. The six figures the read-only assumptions panel already surfaces (investment growth
blended-real, CPI, house growth, rent growth, salary growth, income yield) each get an optional input on step 1,
**defaulting to the chosen preset** (shown as the placeholder + named in the hint); a typed value derives a
**custom set**. Stored as a **sparse `assumptionOverrides` delta** in `builder_state` (only filled figures; an empty
box keeps following the preset, so a re-source still flows through — the same base ⊕ overrides discipline as a
delta-child, and it composes with one for free via `BuilderStateDelta`). The engine `AssumptionSet` gained pure,
immutable `with*` derivations; `App\Forecast\AssumptionOverrides::apply()` overlays the delta; and
**`ScenarioForecaster::assumptions()` is the ONE place it is applied**, so the deterministic forecast, the
per-variant ladder, the Monte Carlo and the **frozen run snapshot** all run the same customised set and cannot
drift. The results panel labels a tuned set **(customised)** and marks **which figures are the user's own**.
**Why (design choices):** (1) **Investment growth is a blended-real return over three asset classes**, not a single
field, so "growth = X%" is applied as a **uniform shift across the asset classes that lands the blend on X**
(`AssumptionSet::withRealReturnShift`) — because the weights sum to 1, the deterministic blend and the per-class
Monte Carlo draws move by the same amount, with **no divergence**; volatility/correlations (risk) are left alone
(the user edits return, not risk). (2) **Sparse delta, key omitted when empty** — never store the preset's value
back (one home per figure), and a what-if child records no spurious assumption delta. (3) **Loose validation bounds**
(e.g. inflation 0–30%, real growth −15–30%) keep an obvious typo out without second-guessing a deliberate stress
test. Reconciliation-tested: no overrides ⇒ the preset unchanged; an edit demonstrably reaches the forecast
(completeness — a lower growth leaves less terminal wealth); the blend lands on the target under any allocation.
**Still to build in this layer:** live in-builder preview; the longevity-lever UX (surface the existing per-person
lever + show the modelled death year); decomposed editable **cost components** (estate agent + legal + EPC/removals);
the **per-line cost-condition override UI** (#1's remaining piece); real-time cost toggles (#7).
**v1 gotcha (engine, recorded so it is not re-hit):** a Blade **block `@php … @endphp`** mis-compiles when the file
already contains an inline **`@php(...)`** form — Blade's non-greedy raw-block regex pairs the inline `@php` with the
block's `@endphp`, silently leaving the opening `@php` literal and emitting a stray `?>` (a parse error far away).
Fix: keep view metadata in the component (`render()` view data), not a `@php` block. Sibling of the earlier
"`@if` glued to a word never compiles" trap.
[[2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if]] [[2026-06-29 — Built #6: per-variant deterministic cashflow ladder + a results-page "on this page" nav]]
**Status:** active (core built, suite green, pending Rob's browser sign-off). Next: the remaining editable items
above, then buy-vs-rent as a deliberate Compare.

## 2026-06-29 — Built #6: per-variant deterministic cashflow ladder + a results-page "on this page" nav
**Decision:** Built the per-strategy cashflow ladder (the legibility item #6). `ScenarioForecaster::deterministicVariants()`
runs each housing strategy through `DeterministicForecaster` on the variant household + settings from
**`HousingComparison::variantInputs()`** — the *same single source* the Monte Carlo comparison runs, so the
deterministic ladder and the simulated comparison transform the household for a sale identically and cannot drift
(`stay_put` is byte-identical to the old `deterministic()`). The results page gained a **strategy selector** driving
the ladder + its milestones (default = the scenario's own variant); the deferred **house-sale milestone** now lands
(year 0, household-level, no per-person age) for a sell strategy; the **PDF** ladder follows the scenario's variant
too (it had the same stay-put-only bug). The displayed-figure provenance invariant (panel == CSV == PDF, one
source) still holds, now on the *selected* variant.
**Why (presentation choices):** (1) **Switch, not side-by-side** — the ladder table is very wide; three side by
side are unreadable, so a selector that swaps the single table is the legible choice. (2) **Only meaningful
strategies are offered** — stay-put always; buy-cheaper only with a buy price; rent only when a sale is configured
(the same gating the sale explainer / assumptions panel already use), so a £0-home or phantom-sale ladder never
shows. (3) **Income-floor / input-sanity notes stay on the raw (stay-put) projection** — they are separate
"household" readouts higher up the page; only the adjacent milestones+ladder block follows the selector, keeping
the blast radius small. Extendable to per-strategy later.
**Also:** the results page is long, so it gained a sticky **"On this page" side nav** (a 2-col grid on `lg+`,
hidden on mobile). It lists only the sections actually present this render (built from the same flags the sections
render under — one source) as **real anchor links that work without JS**; a bundled, CSP-safe `IntersectionObserver`
(`resources/js/toc.js`) highlights the section in view, with a defensive Livewire `commit`-hook re-init for
sections that appear/disappear (a no-op if the hook API differs). Browser-verified by Rob (desktop); mobile
deferred.
**Status:** active. Next in the workstream: the editable-assumptions layer (everything user-editable), then
buy-vs-rent as a deliberate Compare. Builds on [[2026-06-29 — Built #1: contingent-cost placement]] (the variant
households #6 projects are exactly where #1's cost rules bite).

## 2026-06-29 — Built #1: contingent-cost placement (option b) — engine + data-model + auto-classify
**Decision:** Built the correctness fix. An expense line now carries a **condition** (`always` /
`while_owning_home` / `while_working`), **auto-classified by label** (mortgage / service charge / ground rent →
while-owning; commute / season ticket → while-working; else always) with an **explicit per-line override** honoured
first (option b). The engine charges each cost only while its condition holds:
- **`ExpenseProfile`** gains `propertyCosts` + `employmentCosts` — the contingent portions, carried as a **marked
  subset** of essential/discretionary (not a second total, so no drift), with a `withoutPropertyCosts()` that removes
  the housing-linked costs from the essential floor.
- **`HousingComparison`**: the new public **`variantInputs()`** is the single source of the three variant households
  (`compare()` now runs them, and the per-variant ladder #6 will too); the **sell variants (buy/rent) build with
  `withoutPropertyCosts()`**, so the mortgage / service charge stop when the current home is sold — killing the
  phantom-cost bias the buy-vs-rent comparison had.
- **`PathProjector`**: employment-linked costs (commute) are **dropped in years no one earns** (`anyoneWorking()`
  mirrors the earnings condition), so they stop from the retirement year, in every variant.
- **`HouseholdAssembler`** does the label auto-classification (+ override) and aggregates the two markers; *saved*
  self-investment is never contingent (it is not spend).
- **PLSA** comparable spend now **excludes property costs** too (PLSA assumes outright ownership), so the benchmark
  and the variants treat the mortgage on one consistent basis.
**Why:** this is the data-integrity rule applied to contingent expenses — one home per cost, charged only while its
condition holds, with the spend each year equal to the sum of the lines *active* that year (no phantom charge, no
silent drop). Guarded by reconciliation tests: property costs appear **only** in stay-put (zeroed in the sell
variants); the commute **falls by its full amount at the retirement year**; the auto-classify + override + saved-
exclusion + PLSA-exclusion each pinned. **v1 simplifications (flagged):** employment costs stop when the *last*
earner retires (not tied to a specific commuter); contingent costs are treated as essential (removed from the
essential floor first). **Not yet built:** the **builder UI** for the per-line override (the condition is read from
`builder_state` but no control sets it yet — auto-classification gives the defaults); a lifelong-*single*
household's spend is scaled by the survivor factor every year (a pre-existing oddity, noted, left out of scope).
[[2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if]] [[2026-06-29 — Contingent costs have one home tied to what they depend on (housing costs belong with the decision, not shared spending)]]
**Status:** active (built, suite green). Next: the **per-variant deterministic cashflow ladder (#6)** (using
`variantInputs()`) to *show* the corrected per-strategy numbers + the house-sale milestone, then the builder
override UI as part of the editable-assumptions layer.

## 2026-06-29 — Direction from Rob's browser pass: everything user-editable; contingent costs auto-classified (option b); buy-vs-rent as a deliberate what-if
**Decision:** From Rob's browser review of the new explainer layer, four directional calls that reshape the rest of
the workstream:
1. **#1 contingent-cost placement → option (b).** Each expense line is **auto-classified by category/label**
   (mortgage, service charge, ground rent → *while owning the home*; commute → *while working*; everything else →
   *always*) with a **per-line override** in the builder. Rob picked this over a blank per-line dropdown (option a) or
   a fixed set of named contingent lines (option c).
2. **Make all thresholds/assumptions user-editable in the website — nothing hardcoded.** Investment growth, inflation,
   house/rent growth, the **age of death / longevity**, and the selling-cost components must be editable in the UI, not
   baked-in constants. Keep the sourced presets (FCA / DMS / OBR) as *starting points* that derive a user-tweakable
   **custom set**. The per-variant ladder and input-sanity thresholds are likewise user-set, not hardcoded.
3. **Move buy-vs-rent out of the always-baked-in single report into deliberate what-if scenarios** (reusing the
   existing delta-child + Compare infrastructure); the primary report focuses on one chosen strategy.
4. **Show costs as real figures with a breakdown** (estate agent + legal/conveyancing + EPC/removals, SDLT, CGT,
   moving), each editable — not a single opaque 2% rate.
**Why:** Rob's standing principle is trust-through-explanation *and* user control — "I can't trust a number I can't
see the basis of, or change." Research into how existing **free** tools handle this (Boldin, ProjectionLab, the NYT
rent-vs-buy calculator, Guiide, the Actuaries Longevity Illustrator, Honest Math) backs every call: the universal
pattern is **sensible sourced defaults + every assumption overridable + live update**, buy-vs-rent as its *own*
focused comparison, and costs as editable line items. Full findings + the free-tools shortlist:
[docs/RESEARCH-editable-assumptions-ux.md](docs/RESEARCH-editable-assumptions-ux.md). Notably we are already *ahead*
on longevity (ONS cohort mortality + the per-person lever + the on-screen modelled death year) — the gap there is UX
(surface + edit), not modelling.
**Sequencing (proposed, to confirm):** (1) #1 contingent costs (option b) — the correctness fix that unblocks an
honest buy-vs-rent; (2) per-variant deterministic ladder (#6); (3) the editable-assumptions layer (custom set +
longevity + cost components, live preview); (4) buy-vs-rent as a deliberate Compare + the per-option narrative.
**Also fixed this pass:** a Blade `@if` glued to a word ("price@if") never compiled and leaked onto the page — the
selling-costs label is now built in the presenter (`saleExplainer` → `sellingCostsLabel`) and guarded by a test; the
ambiguous "Rent" is relabelled as the *projected cost of renting after selling* (not current rent).
[[2026-06-29 — Contingent costs have one home tied to what they depend on (housing costs belong with the decision, not shared spending)]] [[2026-06-29 — Adviser-legibility: the explainer / show-your-working layer (sale waterfall, assumptions panel, itemised spend)]]
**Status:** agreed direction + research recorded; the build (option-b #1 first) not yet started — pending Rob's
confirmation of the sequencing.

## 2026-06-29 — Adviser-legibility: input-sanity notes (explain a "wild numbers" result back to its input)
**Decision:** Added **input-sanity notes** on the results page — a neutral "A note on your inputs" heads-up, placed
above the figures it affects, explaining when an entered value produced a drastic modelling consequence, so a
surprising result is understood rather than collapsing silently. Two cases, both live-edit foot-guns from Rob's
walkthrough: (a) an **employed** person whose **retirement age is at/below their current age** → no salary is
modelled (the note states both ages); (b) a person **modelled to die in the base year**, which a longevity/health
age below the current age produces (the engine floors a death age at the current age) — read from the new
single-source `ForecastResult::deathCalendarYears`. Factual and lint-safe; empty when nothing is amiss (no noise).
**Why:** the "wild numbers" that triggered this workstream were Rob's own live edits doing exactly these two things
with **no on-screen feedback** — the trust-killer. A note at the point of surprise closes that gap.
`ResultPresenter::inputNotes` + a presenter test (each case fires; a sensible household raises nothing). **Still
open (the rate/£ half of the plan's input-sanity item):** a live £-for-a-rate readout and out-of-range flagging in
the builder (the sale waterfall already shows the selling-cost rate beside its £, so the 20% case is at least
visible on the results page).
[[2026-06-29 — Adviser-legibility: life-event milestones ("when does each event happen")]]
**Status:** active (built, suite green; pending Rob's browser sign-off).

## 2026-06-29 — Adviser-legibility: life-event milestones ("when does each event happen")
**Decision:** Built the life-event **milestones** timeline on the results page — a dated, aged list of *when* the
major events happen across the projection: each person retires, takes their first planned pension withdrawal, their
State Pension starts, and their modelled death. It answers Rob's "what is the 2040 event?" by making the drivers of
the cashflow ladder's step changes legible. Read-only, factual, lint-safe. Every date traces to **one source** —
DOB + the relevant age (planned retirement age; SPA from the engine's `StatePensionAge`; the earliest DC withdrawal
age) — or the engine's new single-source death year: **`ForecastResult::$deathCalendarYears`** (personId → birthYear
+ death age), computed once in `PathProjector` from the draws' death age, so "when does each person die" is no longer
buried inside the projection. Only events within the projection window show (someone already past an event has no
upcoming milestone). The **house-sale milestone is deferred** to the per-variant ladder (it is a variant transform;
the raw-household ladder does not sell).
**Why:** continues the explainer / show-your-working layer — *trust comes from explanation*. Rob's confusion (the
2040 income/spend crossover; "P2 dies in 2027") was precisely a *when* gap: the events are modelled but never shown.
The death year needed the only engine change (additive field, default `[]`, one construction site); everything else
derives from existing inputs/helpers, so the engine stays the single source. Guarded by a presenter test (the
events, their order, the retired-person exclusion) and an assertion that the death milestones ARE the engine's
`deathCalendarYears`, not a re-derivation.
[[2026-06-29 — Adviser-legibility: the explainer / show-your-working layer (sale waterfall, assumptions panel, itemised spend)]]
**Status:** active (built, suite green; pending Rob's browser sign-off). Next: the per-strategy cashflow ladder +
the contingent-cost placement correctness fix (#1) — which also lands the house-sale milestone.

## 2026-06-29 — Adviser-legibility: the explainer / show-your-working layer (sale waterfall, assumptions panel, itemised spend)
**Decision:** Built the first slice of the adviser-legibility workstream — the **explainer / show-your-working
layer** (the option Rob chose over starting with the correctness fix), all deterministic so it renders before any
Monte Carlo run, all factual and lint-safe:
(1) **House-sale waterfall** (`ResultPresenter::saleExplainer`): the proceeds decomposition (sale − mortgage −
selling costs − CGT = net) and where the money goes per option (sell & rent: the full net invested; sell & buy
cheaper: net − buy − SDLT − moving = surplus invested). The selling-cost **rate** is shown beside the £ figure so
an out-of-range entry is visible on screen (the real couple's 20% = £70k vs the ~2% typical). It reads the engine's
single-source `HousingProceeds` plus a new reconciled **`HousingPurchase`** value object for the buy-side surplus;
`HousingComparison::buyVariant` now reads `buyOutcome()` so that figure has one home (behaviour-preserving — the
surplus is identical, the Monte Carlo tests are unchanged-green).
(2) **Assumptions panel** (`ResultPresenter::assumptionsPanel`): the economic assumptions every figure rests on —
the blended **real** investment return (the engine's own `PortfolioAllocation::blendedRealReturn`, with the asset
mix described from the weights so the figure can't become a black box), CPI inflation, house/rent/salary growth
(each **real**, above inflation) and the investment income yield (**nominal**) — each row labelled real-vs-nominal
so the two are never confused, plus the housing-decision inputs and the set's name + sourcing.
(3) **Itemised per-year spend**: the cashflow ladder now splits each year's spend into its essential floor and the
discretionary remainder (= `spendTarget − essentialSpend`), so the spend is traceable rather than one opaque
number; the CSV carries the two new columns.
**Why:** Rob's guiding principle — *trust comes from explanation*; every headline figure must trace on screen to
its inputs. The sale waterfall makes the three compounding trust-killers (the 20% selling cost, the phantom
housing costs, the under-stated rent) self-evident, and the assumptions panel states the basis so a figure is never
unexplained. Each new figure carries a **reconciliation guard** (sale parts sum to the total; ladder split sums to
the spend) and a **real-vs-nominal labelling guard**, per the data-layer integrity rule; the displayed-figure
provenance test was extended to the two new CSV columns (panel == CSV, one figure one home). This is the
low-risk, presentation-only slice that makes the current problems visible and every later fix verifiable.
[[2026-06-29 — Contingent costs have one home tied to what they depend on (housing costs belong with the decision, not shared spending)]] [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active (built, suite green, Pint clean; pending Rob's in-browser visual sign-off). Next in the
workstream: the **per-strategy cashflow ladder** (the ladder still runs the raw household, so it does not yet
reflect the sale/rent legs) paired with the **contingent-cost placement** correctness fix it acts on.

## 2026-06-29 — Contingent costs have one home tied to what they depend on (housing costs belong with the decision, not shared spending)
**Decision:** From Rob's browser walkthrough of a real couple, a cost belongs **wherever the thing it depends on
lives**, and is charged **only while that thing holds** — not as a flat lifelong line in shared `expenseProfile`:
- **Housing-linked costs** (ongoing mortgage payment, service charge / ground rent, owner maintenance) belong
  with the **property / housing decision**, so selling the home removes them. They must **not** be charged in the
  *sell & rent* or *buy outright* variants, where that property is gone.
- **Status-linked costs** (e.g. commute fuel) are tagged to the status that creates them (employment) and **stop
  when it ends** (P1 retires → no commute).
- **General living costs** (food, utilities, cars, leisure, insurance) stay in spending — they are the same
  whichever housing option is chosen.

**Why:** `expenseProfile` is shared across all three housing variants (`HousingComparison::withHousing` passes it
through unchanged) and `PathProjector` charges `targetAnnualSpend()` in every variant. With mortgage + service
charge (~£22.9k/yr for the test couple) sitting in essential spending, *sell & rent* was paying a **phantom
mortgage + service charge on a flat it no longer owns, plus rent**, and *buy outright* a phantom mortgage on a
home owned outright — silently **biasing the headline buy-vs-rent comparison against selling**. This is the
single-definition / completeness rule applied to **contingent expenses**: an expense line can carry a *condition*
(while-owning / while-working / age-bounded), and the spend charged in a year must equal the sum of the lines
**active** that year — no phantom charge, no silent drop. The idea was already foreseen for the parked import work
("the mortgage ends, commuting stops, the spending smile"); this **promotes it to a core data-model concept**.
Guarded by reconciliation tests (property costs in zero post-sale years; commute zero from the retirement year).

**Also recorded this session (not bugs — verified):** the engine is deterministic (repeated runs byte-identical)
and cohort mortality is correct (median death age is conditional on current age); the dramatic swings Rob saw mid-
session were his **live input edits** — a retirement age at/below current age zeroes the salary, and a longevity
*offset* below current age floors at current age ("dies within the year"). These motivate the **legibility
workstream** in docs/PLAN.md "Adviser-legibility workstream (2026-06-29)": life-event milestones (when retire /
SPA / sale / death happen), a house-sale explainer (proceeds decomposition + where the money is invested), input-
sanity notes, and a per-option plain-English "why". The 2040 "shortfall then rapid recovery" was confirmed a
**correct** income/spend crossover (triple-locked State Pension overtaking flat real spend as a thin cash buffer
empties), not a glitch.
[[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** agreed direction; not yet built. Sequencing: the cost-placement fix (#1) lands before the legibility
layers, which should explain *correct* numbers.

## 2026-06-29 — Results charts: spendable (excl-home) money is the default basis; the strategy comparison is over-time, not a terminal bar
**Decision:** From live browser use, the two Monte Carlo charts on the results page were reworked:
(1) **Spendable money (excl. home) is the default basis**, with an **"Include home value" toggle** (off by
default) flipping both charts and their tables. The headline cards still show both figures as text.
(2) The buy-vs-rent **comparison is now a line chart over time** — each housing strategy's **median spendable
money by calendar year**, overlaid — replacing the single terminal-wealth **bar** chart, which is gone. The
per-strategy run-out stats stay in a table beside it (a high line must never hide a high shortfall risk).
(3) The **fan chart** plots the spendable series by default and gains a £0-anchored, `forceNiceScale` y-axis
plus a `£`-abbreviating axis/tooltip formatter (attached in `charts.js`, since a JS function can't travel
through the JSON options).
(4) **Engine support:** `MonteCarlo\SimulationResult` gained a **per-year usable fan** (`usableFanChart`)
beside the per-year total `fanChart` — same `liquid + pension` definition as the cashflow ladder/burndown,
guarded by a `usable ≤ total` per-year reconciliation test; it round-trips through `SimulationResultMapper`
(empty for runs persisted before this change).
(5) **End-of-life rise is explained, not hidden** (`partials/tail-note`): the over-time lines can climb sharply
at the far right for two real reasons, verified against the engine (per-year `paths` collapses from ~1,700 to
single digits over the last decade; the median drifts up, e.g. total £1.05M→£1.22M, usable £510k→£644k): the
**sample thins** to a handful of very-long-lived futures (so the median is noisy), and a long survivor's
**guaranteed income covers their reduced spending so the pot keeps compounding**. The note states the far tail is
indicative, not precise. **Why:** the charts plotted **total wealth including the home**,
so they read as flat and near-identical — a large, illiquid house value dominates and barely moves, squashing
the spendable variation into a thin band and making stay/buy/rent look the same even though their *spendable*
paths diverge sharply (≈£437k / £609k / £660k). For a couple **not planning to sell again**, the home can't
pay day-to-day bills, so excl-home is the honest "will it last" view. A terminal bar also dropped the time
dimension Rob actually needs ("if I live to 100, which strategy keeps the most usable money?"); a line per
strategy answers that directly. Median lines are paired with the run-out table so the level-vs-risk tension
(e.g. rent's high median beside its 55% shortfall chance) stays visible, not hidden.

**Follow-ups (same review loop):**
(6) **Person ages on the axis + tables.** The calendar-year axis and both chart tables now show each person's
age that year (age = `calendarYear − birthYear`, exactly the engine's `YearResult::ages` = baseAge + yearIndex,
reconciled to the cashflow ladder in a test — one age definition). Small, but it makes "when" legible. The axis
formatter lives in `charts.js` (a two-line label: year, then "age 82 / 84"), since a JS function can't travel
through the JSON options.
(7) **Stale-run prompt, not silent fallback.** A run computed *before* this change has no `usableFanChart`, so
the spendable view falls back to total. Rather than silently drawing total wealth as if it were spendable (which
read as "the toggle does nothing / the title is stuck on Total wealth"), the page now shows a neutral re-run
prompt driven by a `usableFanAvailable` flag — no silent failure. Existing runs must be re-run to get the
spendable view.
[[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active (built, green, and signed off by Rob in the browser on 2026-06-29).

## 2026-06-28 — Statement-driven onboarding + document import: deterministic core, LLM only as a walled-off assist (PARKED)
**Decision:** A planned post-v1 feature is designed and recorded before building: the wizard will
**ingest uploaded documents** (bank statements, credit-card statements, payslips, benefit/State-Pension
statements), **pre-fill** every extractable field, and **ask only the remainder**, building the budget from
the household's **actual** spending rather than "average user" national figures. Design + sector evidence:
[docs/RESEARCH-document-import.md](docs/RESEARCH-document-import.md); plan entry: docs/PLAN.md
"Statement-driven onboarding + document import". The load-bearing calls:
(1) **Transfer-matching is deterministic-only.** Rob's £1,258 case — a card-payment credit matched by an
equal-and-opposite current-account debit — is an **internal transfer** and must be **excluded from spend**,
matched by rules (opposite sign, equal pence, date window), **user-confirmed**, with a reconciliation
invariant + a real-file golden fixture carrying a known transfer pair. An LLM is the **wrong tool** here
(non-deterministic, unreliable arithmetic, non-auditable). Dedup uses a stable `imported_id`.
(2) **Categorisation is rules-first; an LLM is an optional, walled-off, LOCAL-only assist** for the long
tail of unknown merchants (a merchant-map + string rules cover 60–80% at perfect accuracy). A mis-tier
moves a pound between tiers but **never changes the grand total** (completeness holds); statement data
**never leaves the machine**.
(3) **Documents pre-fill different builder sections** (bank/CC → expense lines + recurring income +
transfers; payslip → gross salary / pension contributions / NI / tax code; benefit statement →
`IncomeStream` with **taxable vs tax-free** classified), extending the existing `PayAndExpenditures` mapping.
(4) **Actuals = the input baseline; PLSA stays the benchmark, not the input** — imported spend is *today's*
cost; the wizard marks which lines continue into retirement and the forecast adjusts.
(5) **Architecture:** an extension of `app/Import/` (a statement profile family →
`ImportResult::expenseLines` + `reconciliation`), app-layer only (engine stays dependency-free), writing
`builder_state.expenseLines` (the existing single source of truth). **Open Banking (regulated AISP, online)
is out of scope** for the local-first v1; file import (CSV/OFX/QIF; PDF+OCR a flagged sub-phase) is the path.
**Why:** this is the correct framing of Rob's "could a local Ollama AI do the forecasting?" question — the
answer being that a model has **no place in the trusted numeric path** (it would break HMRC-to-the-penny,
reproducibility, sourcing and no-silent-failure), but **wrangling and explaining** imported documents is a
genuine fit, and the **privacy** argument (sensitive bank data, local-only) is strong for *this* feature
specifically. The transfer-matcher is kept deterministic because the £1,258 double-count is precisely the
inconsistent-aggregation bug class the project was burned by — and the tax-free classification on benefit
statements is the completeness sibling (the DLA bug). Each phase delivers value alone; the model is the last,
optional layer, so the whole feature lands without any AI at all.
[[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]] [[2026-06-25 — Forecast income completeness: count every source, no silent drop]] [[2026-06-25 — Expenditure: 3-tier line items (essential / discretionary / self-investment) + spent-vs-saved]] [[2026-06-25 — `.xlsx` import via PhpSpreadsheet; a bespoke profile for the personal workbook]]
**Status:** active (design decision recorded; the feature itself is **parked, post-v1** — not started).

## 2026-06-28 — Engine: income-tax thresholds un-freeze after freezeEndYear (homogeneity, not config rebuild)
**Decision:** The forecast now models UK income-tax thresholds **un-freezing** after
`ForecastSettings::$freezeEndYear` (April 2031): frozen until then, indexed with inflation afterwards. It is
implemented in `PathProjector` via the income-tax function's **degree-1 homogeneity** in (income, all of its
monetary thresholds): post-freeze, `indexedTotalPence()` taxes income **deflated** to the freeze-end price level
against the frozen base-year thresholds and **re-inflates** the result — mathematically equal to taxing under the
inflated thresholds, but without rebuilding the band config per year per path in the 10k-path hot loop. The
threshold factor is `1.0` during the freeze (and for any caller passing the default), so the HMRC worked-example
unit tests and all freeze-period years are the **exact identity** (unchanged). The factor is threaded through both
tax call sites — the main per-person pass and the drawdown grossing-up (`marginalTax`/`grossUpPension`) — so the
tax paid and the withdrawal sizing share one basis.
**Why:** previously the projector kept thresholds frozen for the *whole* projection, which **overstated post-2031
fiscal drag** on every retirement-length forecast, and `ForecastSettings::$freezeEndYear` was documented-but-dead
(its docblock contradicted the projector's). This was finding #2 of the 2026-06-28 re-review; Rob chose to
**implement** rather than just document the conservative bias. The homogeneity approach was chosen over rebuilding a
scaled `TaxYearConfig` per year because the latter would allocate config objects in the hot loop — the very cost the
recent `totalPence()` perf work removed — whereas homogeneity is pure arithmetic on the income before the existing
lean tax call, at a penny-level rounding cost that is immaterial in a multi-year forecast (and zero during the
freeze). **Verification:** `ThresholdFreezeTest` pins identical tax within the freeze window, strictly lower tax
after it, and lower cumulative drag overall; the Monte Carlo tests are a determinism check (no committed percentile
snapshot), so nothing needed regenerating. The deflate→tax→re-inflate path rounds income by the factor, so it is a
deliberate (tiny) approximation versus exact scaled thresholds — acceptable because the HMRC-exact paths use factor
1.0 and the projection is already nominal/real with rounding throughout.
**Status:** active

## 2026-06-28 — Phase D Tier-2: a11y verification sweep — 3 real contrast fixes + the toolchain reality
**Decision:** A local accessibility sweep was run (build + `php artisan serve` + axe). It **fixed three genuine
WCAG AA contrast failures**: `text-gray-400` on the builder *Discard* button and the dashboard *what-if* label
(≈2.9:1, below the 4.5:1 floor) → `text-gray-600`, and a `text-gray-300` Compare separator (≈1.6:1) →
`text-gray-500` + `aria-hidden`. On the **tooling**, the scaffolded Pa11y CI is downgraded to a coarse CI-only
regression smoke, and the **authoritative a11y check is in-browser axe DevTools / Lighthouse** (documented in
docs/A11Y.md). The `runners` were set to **axe only** (HTML CodeSniffer crashes — `checkControlGroups` — under
current Chrome), the diagnostic `@axe-core/cli` dep was removed, and the config was trimmed to the verified-working
URLs (public pages + `/welcome`).
**Why:** two hard environment facts surfaced. (1) npm here runs with **`ignore-scripts=true`**, so no headless
browser binary (puppeteer Chromium, chromedriver) ever downloads — no headless a11y runner can fetch a browser
locally (CI on Linux is unaffected). (2) Pa11y CI 3.1.0 pins pa11y 6 → **axe-core 4.2 (2021)**, which emitted a
**false positive** here (a contextless "color-contrast" error on the public pages, which carry no sub-AA text — a
real axe violation always names the offending node). A gate that cries wolf is worse than none, so the trustworthy
current-axe DevTools pass is made authoritative and Pa11y CI is kept only as a self-contained CI smoke. The three
contrast fixes were found by static review of the colour classes (trustworthy, tool-independent) and are real
regardless of tooling.
**Status:** active

## 2026-06-28 — Phase D Tier-2: accessibility CI — Pa11y CI (axe + HTMLCS), scaffolded
**Decision:** The a11y gate is automated with **Pa11y CI** running **axe-core + HTML CodeSniffer** against
**WCAG2AA** over the rendered pages. The page list + login scripting live in `.pa11yci.json` (public pages run
with no setup; the authed shell pages — `/welcome`, `/dashboard`, `/scenarios/create` — are reached by scripting a
login + disclaimer acknowledgement with the seeded **demo** account). `pa11y-ci` is a devDependency with an
`npm run a11y` script; `.github/workflows/a11y.yml` runs the same sweep on push/PR (dormant until the repo has a
GitHub remote, since it is local-first today); `docs/A11Y.md` documents the local run + how to extend coverage.
**Why:** accessibility is a hard project constraint (every figure also rendered as text + an accessible table, skip
link, landmarks, `aria-*` on forms), so it deserves a machine guard, not just discipline. Pa11y CI with both
engines is the standard headless WCAG checker and reuses the demo seeder for authed coverage. **Honesty caveat:**
this is **scaffolded, not yet run green** in this environment — it needs a headless Chrome + the served app, which
is the real-browser verification pass this work always required; the config/workflow are correct-by-construction
and documented as unrun. The rendered ApexCharts canvases stay a manual real-browser check (only the accessible
tables/text are machine-checkable). **Status:** active

## 2026-06-28 — Phase D Tier-2: PDF results export — dompdf reusing the on-screen presenter
**Decision:** A scenario's results are downloadable as a PDF via `App\Http\Controllers\ScenarioPdfController`
(`GET /scenarios/{scenario}/results/pdf`, owner-scoped, inside the disclaimer-acknowledged group; a draft 404s),
rendering `resources/views/pdf/results.blade.php` with **`barryvdh/laravel-dompdf`** (pure-PHP dompdf — no headless
browser or binary, app-layer only so the engine stays dependency-free). The report is built from the **same
`ResultPresenter`** the on-screen page uses (lump-sum tax shock, income floor, 3-tier budget, PLSA benchmark, the
cashflow ladder, and — if a completed run exists — the Monte Carlo headline summary), so the print **cannot drift**
from the screen (the displayed-figure provenance rule). The data assembly is a public `data()` method so the
view-render test exercises the exact data the controller produces. The PDF carries the guidance-only disclaimer +
signposting (and passes the banned-phrasing partition lint). The full per-year income-by-source split stays in the
CSV export; the PDF shows the wealth trajectory (tax/spend/usable/total), keeping the table portrait-friendly and
every column a presenter-provided string (no in-view derivation).
**Why:** a shareable/printable summary is a standard go-live want, and dompdf is the lightest way to get one that
is fully testable headlessly (the route streams a real `%PDF`, the view renders the figures + disclaimer). Reusing
the presenter rather than re-deriving figures is mandatory under the data-integrity rules. **Residual:** a
real-browser/PDF-viewer eyeball of layout fidelity (the figures + structure are tested; the visual rendering is
not). Suite 348 → 353 green. **Status:** active

## 2026-06-28 — Phase D Tier-2: two-factor enrolment UI — a Livewire page driving Fortify's actions
**Decision:** Two-factor authentication enrolment is delivered as a full-page Livewire component,
`App\Livewire\AccountSecurity` (at `/account/security`), that drives **Fortify's own actions**
(`EnableTwoFactorAuthentication`, `ConfirmTwoFactorAuthentication`, `GenerateNewRecoveryCodes`,
`DisableTwoFactorAuthentication`) directly rather than posting to Fortify's HTTP endpoints, so enrolment is
one fluid page (turn on → scan QR / type setup key → confirm a code → recovery codes shown; regenerate; turn
off). The `User` model gains Fortify's `TwoFactorAuthenticatable` trait (the 2FA columns were already
migrated); `two_factor_secret`/`two_factor_recovery_codes` are stored encrypted and read raw by the trait, so
they are **not** given an Eloquent `encrypted` cast (that would double-encrypt) and are added to the model's
`$hidden`. `FortifyServiceProvider` now wires the two previously-missing views: the login **two-factor
challenge** and the **password-confirmation** screen. Because the component calls the actions directly (not the
endpoints that carry Fortify's `confirmPassword` middleware), the page **route** is placed behind the
`password.confirm` middleware — a "sudo" step that is the equivalent protection for the direct-action approach.
The security page sits **outside** the guidance-only disclaimer gate (like the GDPR controls): account
management is not withheld pending acceptance of the forecast framing. A "Security" link is added to the authed
nav.
**Why:** Fortify's 2FA feature was enabled (`config/fortify.php`, with `confirm` + `confirmPassword`) and the
columns migrated, but no screens existed, so no user could enrol — a real shipped-surface security gap, and one
that matters for the possible public release. Calling the actions from Livewire (the Jetstream pattern) gives a
clean single-page UX and is cleanly testable headlessly; the QR/recovery-code/TOTP machinery is all provided by
the already-installed `pragmarx/google2fa` + `bacon/qr-code`. Chosen as a Tier-2 item because the whole flow is
verifiable without a browser: `TwoFactorAuthenticationTest` enables + confirms with a computed current TOTP,
rejects a wrong code, regenerates recovery codes, disables, drives the full login challenge to completion, and
asserts the security page demands a confirmed password. **Test gotcha recorded:** Fortify rejects reuse of a
TOTP within its window, so a test that both confirms enrolment and later completes a login challenge must not
spend the same current code twice — the enrol helper stamps `two_factor_confirmed_at` directly instead of
burning a code. **Residual:** a real-browser eyeball that the QR renders and an authenticator app round-trips
(the SVG + flow are headless-tested, the visual scan is not). Suite 340 → 348 green.
**Status:** active

## 2026-06-28 — Phase D Tier-2: security headers — a compatible-by-construction CSP on the web group
**Decision:** A new `App\Http\Middleware\SecurityHeaders` (appended to the `web` group in `bootstrap/app.php`)
sets a **Content-Security-Policy** plus a small set of static hardening headers on every response of the public
surface (landing, Fortify auth screens, the Livewire forecast UI). The policy and its toggles live in one home,
`config/security.php`, so the test asserts against the same definition the middleware reads. The CSP is
**compatible-by-construction** with the current self-hosted stack: `default-src 'self'`, self-hosted Vite bundle +
Bunny fonts (`font-src 'self'`, `img-src 'self' data:`, `connect-src 'self'`), with `script-src`/`style-src` keeping
`'unsafe-inline'`/`'unsafe-eval'` because Livewire injects an inline init script, Alpine evaluates expressions via the
Function constructor and ApexCharts injects inline styles. The high-value structural directives are enforced
regardless of inline handling: `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'none'`.
The static headers are `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
`Referrer-Policy: strict-origin-when-cross-origin` and a restrictive `Permissions-Policy`. Two env toggles:
`SECURITY_HEADERS_ENABLED` (master switch) and `SECURITY_CSP_REPORT_ONLY` (send `Content-Security-Policy-Report-Only`
to stage a rollout). The Filament `/admin` panel is **deliberately out of scope** — it runs its own middleware stack
(not the `web` group), manages its own asset loading (incl. its own font source) and is admin-only.
**Why:** a CSP + hardening headers are a standard go-live requirement. The policy is shipped enforcing (not
report-only) because it is permissive exactly where this self-hosted stack needs it, so breakage risk is low while
the structural protections are real. Tightening `script-src`/`style-src` to nonce-based (dropping `'unsafe-inline'`
and `'unsafe-eval'`) needs Alpine's CSP build and a real-browser verification pass, so it is left as the residual
go-live item rather than shipped untested. Applying only to the `web` group keeps Filament's own CSP/asset story
intact (a `web`-group CSP with `font-src 'self'` would otherwise break Filament's default Bunny-hosted font). Chosen
as the next Tier-2 item because the server-side policy + toggles are fully verifiable headlessly (the only
browser-dependent part — confirming ApexCharts/Livewire still render under the CSP — is the documented eyeball).
Tested by `SecurityHeadersTest` (header present on web + auth routes, structural + stack directives, exact match to
config, static headers, report-only swap, disabled-switch). Suite 332 → 340 green.
**Status:** active

## 2026-06-28 — Phase D Tier-2: Monte Carlo 10k-path perf — a lean integer tax twin + JIT for the worker
**Decision:** The 10k-path run is sped up two ways, both proven to leave results byte-identical. (1) **In-repo:**
profiling showed `PathProjector::project()` is **93%** of per-path time and within it the income-tax calculator
dominates, yet every projector tax call reads only `->total->pence`. So `IncomeTaxCalculator` now exposes a lean
`totalPence(TaxableIncome): int` that shares the **same private band core** (`bandedTax()`) as `compute()` but skips
the `Money`/`lines` decoration the hot loop discards — one computation, two presentations. The allowance taper also
moved to an integer home (`grantedAllowancePence()`) that the public `personalAllowance()` Money method now delegates
to. The projector's main per-person pass and `marginalTax()` route through `totalPence()`. (2) **Deployment:** the
queue worker that runs the full simulation should start PHP with **OPcache JIT enabled** (it is off by default on
this machine: `opcache.enable_cli=0`, `opcache.jit=disable`) — see How to pick up for the exact flags.
**Why:** the engine is the product and the 10k run is its slowest path; tuning it is the Tier-2 perf item. The
calculator is trust-critical, so the rule was *no behaviour change* — the integer per-slice rounding mirrors
`Money::applyRate` exactly, and a new `IncomeTaxTotalPenceTest` pins `totalPence($i) === compute($i)->total->pence`
across a 1,120-cell grid (every band crossing, taper window, PSA tier, dividend allowance, both tax years), so the
two presentations can never silently diverge — the one-definition-one-home rule applied to a perf split. The rich
`compute()` result is consumed only by the composite test (production reads only the total), so the blast radius is
small. **Measured** (the `comfortable` MC couple, 10k paths): interpreted **13.9 s → 8.9 s** (1.57×) from the
refactor alone; with function-mode JIT **→ 4.75 s** (2.9× vs the original), the leaner allocation profile compounding
with JIT. Memory unchanged (~16 MB). JIT is a *startup* setting, so it is surfaced as a documented worker invocation
rather than silently written into Rob's global Herd `php.ini`.
**Status:** active

## 2026-06-28 — Phase D Tier-2: demo preset is an opt-in, production-safe seeder over the canonical shape
**Decision:** The demo "preset" the plan owes at step 5 is delivered as a seeder, not a hardcoded record or a
separate sample format. `App\Demo\DemoScenario` is the one home for an obviously-fictional sample plan expressed
in the **canonical `builder_state` shape**, so it assembles to the engine DTOs and runs exactly like a user-built
scenario (no parallel representation that could drift). `Database\Seeders\DemoScenarioSeeder` persists it as a
**base plan + one delta-child what-if** ("retire two years earlier"), the child derived via `BuilderStateDelta::diff`
so it stores only the override and the base stays the single source. It is **opt-in** (not wired into
`DatabaseSeeder`, so it never fires in the normal dev/test seed), **idempotent** (matched by owner + name, drops
stale runs on re-seed), and **release-safe**: outside production it provisions a fictional `demo@example.com` /
`password` account; in production it **refuses** to mint a default-credential account unless `DEMO_USER_EMAIL`
names an existing user (loud `RuntimeException`).
**Why:** the locked decisions require any first-run sample to be obviously fictional and forbid client data in the
repo, and "do not design accounts out, just defer them" leaves possible public release on the table — so a demo
account must never ship default credentials by accident. Building the demo on the canonical shape makes it double
as a living end-to-end integration smoke (assemble → forecast → results), the highest-confidence way to keep the
sample honest. Chosen as the first Tier-2 item because it is fully verifiable headlessly (CSP + a11y CI both need
real-browser eyeballing).
**Status:** active

## 2026-06-28 — Phase D Tier-1: import reconciliation surfaced to the user (the panel, completing Tier-1)
**Decision:** The data-layer integrity rule's "surface every imported/aggregated total; a mismatch must be a
*visible* failure, not a silent one" is now enforced at the **user-facing** layer, not only in tests. A new
`App\Import\ReconciliationLine` value object pairs the figure that went **into the form** (`imported`) with the
sheet's **own independent figure** for the same quantity (`stated`, nullable) — a TOTAL row, or the sum of the
line items the importer did not take as primary. Equality is judged in **exact pence** (`reconciles()` /
`mismatch()`), so formatting can neither mask nor invent a divergence; `stated = null` means the layout offers no
second figure, so the value is surfaced for eyeball review and never reported as a mismatch. `ImportResult` carries
`reconciliation: list<ReconciliationLine>`, the three calibrated profiles emit it (`PayAndExpenditures` captures
the sheet's own Total row it previously discarded; `ConsciousSpendingPlan` reconciles each bucket's stated `… TOTAL`
against its line-item sum; `RetireForecastTemplate` surfaces each category with `stated = null`), and the Blade
import panel renders each pair, turning red + `role=alert` on any divergence.
**Why:** the importers already *resolve* discrepancies internally (CSP trusts the stated TOTAL; PayExp uses the
summed lines) but the user never saw that a second figure existed or whether the two agreed — exactly the
silent-aggregation blind spot that burned a past project. Showing both figures side by side makes the resolution
auditable before the user saves. The test layer enforced reconciliation; the UI did not, so this closes the gap.
**One latent correctness fix fell out:** to make the CSP line-item sum a *faithful* cross-check, the parser now
skips the `NET WORTH`/`INCOME` sections (their Investments/Savings rows shared the bucket keywords and inflated the
contributions sum). No imported figure changes — the stated TOTAL stays authoritative — but a CSP file lacking
bucket TOTAL rows would no longer mis-import balance-sheet assets as monthly contributions.
**Status:** done — this is the **last Tier-1 (trust) item**, so Tier-1 is COMPLETE. Suite 309 → **320 green / 1626
assertions** (app 172 → 183; engine 137); pint clean. Proof the failure is visible, not silent: a deliberately
-inconsistent golden fixture (`csp-inconsistent-bucket-total`, a £9,999/mo TOTAL vs £3,000/mo of line items) plus
its Livewire twin assert the panel flags it. [[2026-06-25 — Data-layer integrity: one definition, one home + reconciliation/completeness tests]]

## 2026-06-27 — Phase D: admin-panel access gated on an is_admin flag (go-live lockdown)
**Decision:** `User::canAccessPanel()` no longer returns `true` for every authenticated user; it is gated on a
new **`is_admin`** boolean (migration, default false, cast on the model). The first admin is bootstrapped from
the CLI (`php artisan user:make-admin {email}`, `--revoke` to undo); once one exists, admins toggle others via an
**Admin access** `ToggleColumn` on the Filament Users resource. A non-admin hitting `/admin` gets a 403.
**Why:** the advice-style `interpret` capability is admin-granted from inside the panel, so "any authenticated
user can reach the panel" was a privilege-escalation path: a public user could self-grant `can_interpret` and turn
on directive, advice-style output — exactly the regulatory line the compliance layer exists to hold. Admin access
must therefore be the *tighter* gate, set out-of-band (CLI), not self-serve. A flag beats an email allowlist
because the existing `UserResource` already manages per-user capabilities; `is_admin` sits beside `can_interpret`
as one more admin-managed boolean. The CLI command follows the no-silent-failure rule (unknown email fails loudly;
an already-correct state is a reported no-op). [[2026-06-25 — Compliance: directive-only lint + partition test + interpretation toggle]]
**Status:** done. Suite 298 green (app 164 → 169: a non-admin-403 test + a 4-case command test; the three existing
panel tests moved to an `admin()` factory state). **Local migration note:** existing DBs need `php artisan migrate`
then `php artisan user:make-admin {email}` to restore admin access.

## 2026-06-27 — Phase D: gov.uk figure-verification pass completed (Tier-1 trust gate)
**Decision:** Ran the build-time **figure-verification pass** the plan required before any figure is "shown as
real". Every statutory figure carrying a ⚠️ marker was re-confirmed against gov.uk on 2026-06-27 and its
`verified_on` / `VERIFIED_ON` stamp moved to 2026-06-27; the ⚠️ docblocks were rewritten to record the specific
confirmation + source. **No figure value changed — every one was already correct.** Confirmed:
- **Income tax / NI / dividends / savings** (PA £12,570, 20/40/45, taper £100k→£125,140 frozen to Apr 2031; NI
  8%/2% on £12,570–£50,270; **dividends 26/27 = 10.75/35.75/39.35** + £500 allowance; PSA + starting-rate band).
- **Pensions:** LSA £268,275, LSDBA £1,073,100, AA £60k, MPAA £10k, tapered-AA £200k/£260k + £10k floor; NMPA
  55 → **57 on 6 Apr 2028**.
- **State Pension** new SP £241.30/wk (26/27) + **SPA 66→67 over DOB 6 Apr 1960–5 Mar 1961** (Pensions Act 2014).
- **CGT** residential 18%/24% + **£3,000 AEA**; final 9 months always relieved + lettings relief shared-occupancy-only (HS283).
- **SDLT** bands 0/2/5/10/12 + **5% surcharge**; **benefits** £10k disregard / £1-per-£500/wk / £16k HB cut-off;
  **care** £23,250/£14,250 + £1-per-£250/wk.
- **IHT** £325k NRB (frozen to 5 Apr 2031), £175k RNRB (frozen to 5 Apr 2030), £2m taper, 40% — and the
  **April-2027 unused-pensions-in-estate change is now ENACTED** (Finance Act 2026, Royal Assent 18 Mar 2026,
  deaths on/after 6 Apr 2027), upgraded from "proposed"; stays behind the toggle.
- **PLSA Retirement Living Standards:** all 12 figures match the published 2026 table **exactly**.
- **`investmentIncomeYield` (2%):** reviewed and kept, but reclassified in the docblocks as a **modelling
  assumption, not a statutory figure** (anchored to the global-equity dividend yield ~1.3–2%); it is not
  gov.uk-verifiable, so it carries a "reviewed 2026-06-27" note rather than a verified-against-gov.uk claim.
**Out of v1 scope, deliberately NOT verified** (the region resolver throws rather than guessing): **Scottish
income-tax bands** and **LBTT/LTT** (Welsh/Scottish property taxes). The FCA/DMS/ONS *assumption-source*
sign-off (docs/ASSUMPTIONS.md, docs/MORTALITY.md) stays at its 2026-06-24 sign-off — it is a separate academic/
regulatory review, not part of this gov.uk statutory-figure pass.
**Coupled tests updated** (the pass changed provenance dates, not figures): the PLSA `VERIFIED_ON`, the
benchmark readout `verifiedOn`, the Filament audit "Verified …" string, and the `taxyear_config_version`
fixtures (it is the config's `verifiedOn`, SimulationRunner.php:39) all moved 2026-06-26/24 → 2026-06-27.
**Why:** Rob's hard rule is no figure shown as real without a sourced, dated confirmation. A re-verification
that finds everything already correct is the *expected good outcome* — it converts "believed right" into
"checked right on a known date", and catches the one thing that did move (pensions-in-IHT is now law, not a
proposal). Per the data-integrity rule, the stamp is the audit trail.
**Status:** done. Suite 293 green (no value changed, only provenance + 4 coupled date assertions). **Next: Phase
D go-live polish** (a11y CI, CSP header, `canAccessPanel()` lockdown, perf, PDF, 2FA UI).

## 2026-06-27 — A5: how GIA/cash tax is modelled (income paid out + taxed; capital → CGT on disposal)
**Decision:** Phase D started with **A5** (GIA/cash income tax + CGT-on-disposal), the modelling deferred from
the rebuild. Rob chose the **full** scope (annual income tax AND CGT on disposal). The modelling, decided to
avoid the double-count that caused the deferral:
(1) A GIA's/cash's **total return is split into income + capital growth**. The income (cash interest as savings,
GIA dividends as dividend income) is **paid out to net cash and taxed each year** via the existing combined
income-tax pass (PSA + dividend allowance stacking); the asset then **grows at capital only** (total return
minus the income yield). So income paid out + capital growth == total return, **never double-counted** — the
exact failure mode that made shipping this hastily a trust bug. ISA stays tax-free and reinvests at total
return. Conservation is asserted by a test (the taxed, capital-only GIA can never out-grow an equal tax-free
ISA).
(2) The income yield is a **new sourced figure** `AssumptionSet::$investmentIncomeYield` (nominal, **2.0%**,
uniform across the three sets for v1), anchored to the global-equity dividend yield (FTSE All-World ~1.3-2%).
⚠️ flagged for the go-live figure-verification pass (read 2026-06-27, like the PLSA figures). Per-account
`Account::$yield` overrides are reserved for a later refinement (balances are aggregated per person in the
projector, so honouring per-account yields needs de-aggregation).
(3) **Capital gains → CGT only on disposal** (the next A5 step): when a GIA is drawn to fund a shortfall the
pro-rata gain is realised and taxed (shared £3k AEA, 18/24% by band — reusing `CgtParameters`, whose residential
rates have equalled the share-gain rates since the Oct-2024 Budget). Basis is tracked through contributions and
disposals; losses are not relieved in v1.
[[2026-06-25 — Rebuild: keep the engine, rebuild storage to the new world; ratify LW4+SQLite; defer GIA/CGT]]
**Why:** This is the trust pass: an unwrapped holding must carry its real tax drag (income tax + CGT on
disposal), and the income/capital split is the only way to add it without taxing the same return twice. The new
yield is sourced + verified-flagged like every other external figure (no magic numbers); the conservation
invariant is guarded by a test (no silent double count), as is CGT incidence (a gainful GIA is taxed where a
no-gain one is not).
**Status:** done (income side committed at `937413b`; CGT-on-disposal + basis tracking complete — GIA gains
realised pro-rata on drawdown, shared £3k AEA, 18/24% by band, reusing `CgtParameters`; v1 omits loss relief
and judges the CGT band on non-savings income, both flagged). A5 fully closed; GIA/CGT no longer deferred.

## 2026-06-26 — C4: PLSA Retirement Living Standards benchmark (placement, basis) + engine-isolation guard
**Decision:** Built the **PLSA Retirement Living Standards benchmark** (the one remaining C1-list item, the
core of C4) and added an **engine-isolation guard test**. Calls made:
(1) **The sourced figures live in the engine** (`RetireForecast\FinanceEngine\Benchmark\RetirementLivingStandards`
+ `RetirementLivingStandardsResult`), framework-free and golden-master tested, alongside the other sourced
reference data (tax config, assumption sets, mortality) — they carry `SOURCE` + `EDITION` + `VERIFIED_ON`
(read 2026-06-26 from retirementlivingstandards.org.uk) per the "no magic numbers" rule. **⚠️ flagged for the
go-live figure-verification pass** because they were read via an automated WebFetch, not yet eyeballed against
the published table.
(2) **The comparison is put on the PLSA basis** (PLSA's own definition: excludes rent + mortgage — assumes the
home is owned outright — but *includes* everyday home running costs). So comparable spend = the household's
**lifestyle spend** (`ExpenseProfile::targetAnnualSpend()` = essential + discretionary, already excluding the
*saved* self-investment) **plus owned-home running costs**, with rent excluded by construction (rent lives in
`HousingAction`, not the `Household`). This reuses the *same* `ExpenseProfile` the forecast runs on, so the
benchmarked figure cannot drift from the projection (data-integrity reconciliation; tested in
`PlsaBenchmarkTest`). Presentation (composition single/couple, the housing-leg adjustment, wording) lives in
`ResultPresenter::plsaBenchmark()`; the engine stays neutral facts only.
(3) **London is not modelled as a region**, so the (lower) **outside-London** figures are used and the higher
London cut is flagged in the readout caveat. (4) **Wording stays neutral** ("reaches the Moderate standard",
"a general yardstick, not a recommendation") — passes the `OutputPhrasing` partition lint.
(5) **Added `EngineIsolationTest`** (engine test suite) that scans `packages/finance-engine/src` for any
`use App\…` / `use Illuminate\…` import. **Prompted by a real near-miss this session:** Pint's
`fully_qualified_strict_types` fixer turned a `{@see ResultPresenter::…}` docblock cross-reference into a real
`use App\Forecast\ResultPresenter;` import in the engine — a silent breach of the framework-free boundary that
no test would otherwise have caught. The cross-reference was removed; the guard now makes any future breach a
visible failure. [[2026-06-26 — C1 fast-follow: income-floor definition, importer line population, longevity lever scope]]
**Why:** A recognised external benchmark is exactly the kind of "no magic numbers" figure the project requires
sourced + verified, and exactly the kind of aggregation that must reconcile to the forecast it sits beside (not
a second, drifting definition of "spend"). The engine-isolation guard closes a hard-rule gap (CLAUDE.md: the
engine must never `use App\…`/`Illuminate\…`) that had no automated enforcement — the same "loud guard, no
silent failure" discipline applied to the trust boundary itself.
**Status:** active

## 2026-06-26 — C1 fast-follow: income-floor definition, importer line population, longevity lever scope
**Decision:** Three design calls while building the Phase C1 fast-follow (results 3-tier display + income-floor
readout + importer line-population + the per-person longevity builder lever). (1) **Income-floor "secure
income" = DB pension + State Pension + annuity/other + tax-free income**, measured at the **last year everyone
is still alive** (the mature floor, by when every guaranteed source is in payment and salary has ended). It is
deliberately the *complement* of the pot-dependent sources (salary, pension lump sum, pension drawdown, asset
drawdown), and **tax-free income (DLA-type) is included** — excluding it would repeat the exact completeness
bug the project was burned by. Essential spending it is compared against is the new **`YearResult::essentialSpend`**
(real terms, incl. rent / property running costs), surfaced from the figure the projector already computes, so
the readout and the cashflow ladder read one definition, not a re-derivation. The readout reports coverage as a
fact (a %, a surplus or a gap met from savings) and never says whether it is *enough* (no recommendation).
(2) **Importers emit per-line `expenseLines` where the source supports it, but CSP stays one line per bucket.**
RetireForecast (per-row) and PayAndExpenditures (per-outgoing) carry real line items with their labels; the IWT
CSP importer emits **one line per bucket** using the bucket's authoritative "… TOTAL" rather than re-expanding
the bucket into its items — re-expansion would re-risk the per-bucket-TOTAL double-count the reconciliation
guard exists to catch. The flat `expense` total is kept as the reconciliation anchor and the gotcha-A guard now
also asserts the line sums reconcile to it. (3) **The builder longevity lever exposes peer / fixed_age /
offset_years only** — the engine's `mortality_multiplier` mode stays engine-side (too technical for the form in
v1). The two form fields (`longevityMode`+`longevityValue`) ride the existing C2 delta, so a child what-if
overrides lifespan for free; an end-to-end completeness test proves the form lever reaches and moves the
forecast. [[2026-06-25 — Engine enrichments for the new world (contributions, longevity, usable wealth, income-by-source)]]
**Why:** Each respects the data-layer integrity rule (one definition per quantity; completeness — no input
silently dropped, no figure able to drift from its source) without over-reaching into modelling that needs the
trust pass (phased spend, GIA/CGT) or a C4 feature (PLSA benchmark).
**Status:** active

## 2026-06-25 — Rebuild: keep the engine, rebuild storage to the new world; ratify LW4+SQLite; defer GIA/CGT
**Decision:** Rob authorised a clean rebuild treating the existing code as a prototype, with a key
liberation: **no existing user data, DB layout or data shape must be preserved** — build storage to match
the new world directly. Concretely: (1) **Keep the framework-free engine** (penny-accurate, now 113 tests)
and the sound app code; **rebuild the data/storage layer freely** (no migration, no backward-compat — this
removes gotcha G). (2) **Ratify the real stack** — Livewire 4 + Filament 5 + SQLite + db/sync queue — over
the plan's stale Livewire 3 + Redis/Horizon (reverting LW4→3 would fight Filament 5 for no gain). (3)
**Interleave trust fixes with features** rather than sequencing (Rob: "not too worried about first focus…
build to a natural slightly beyond MVP"). (4) **Defer the GIA/cash income-tax + CGT-on-disposal modelling
(A5) to the trust pass (Phase D):** the projector grows GIA/cash at the assumption set's *total* real
return, so taxing a yield on top would **double-count returns** — it must be done by decomposing total
return into a taxed income yield + deferred capital growth (CGT on disposal with AEA + rates), alongside the
gov.uk figure verification. Shipping it hastily would itself be a trust bug.
**Why:** The engine is the trustworthy, costly-to-recreate asset; the prototype builder/storage was always
disposable (it existed to get a usable app for feedback). Freeing the rebuild from data migration lets the
new shape (builder_state source of truth, delta children, line items, account contributions, longevity) be
built cleanly instead of bolted on. The prototype is preserved at tag **`prototype-v1`** (commit a8f1f68)
for recovery (no remote). [[2026-06-25 — Scenario model: base plan + delta what-if children + compare]]
**Status:** active

## 2026-06-25 — Engine enrichments for the new world (contributions, longevity, usable wealth, income-by-source)
**Decision:** Built the engine capabilities the sector-informed rebuild needs (Phase A), each golden-master /
reconciliation tested, all additive and backward-compatible: (1) **ongoing contributions** on `Account` (new
field) and DC pensions (the DTO already carried `ongoingContribution`/`employerContribution` but the projector
ignored them) — funded from surplus only, so saving stops once the household is in net drawdown; (2) a
per-person **`LongevityAdjustment`** (peer / fixed age / ±years / mortality multiplier) feeding both the
deterministic representative death age and the Monte Carlo sampler (q(x) multiplier on the cohort table); (3)
**terminal usable wealth** (excl. home) reported alongside total on `ForecastResult`/`SimulationResult` (fixes
the asset-rich / cash-poor "wealth left" paradox, gotcha P) at the engine boundary; (4)
**`YearResult::incomeBySource`** — every year's inflows split across the canonical sources (salary, DB, State
Pension, annuity/other, tax-free, pension lump sum, pension drawdown, savings drawn), powering the cashflow
ladder and the per-source completeness guard (gotcha Q).
**Why:** These are the prerequisites the new-world features (line items, drill-down, lifespan/contribution
what-ifs, honest wealth reporting) consume; building them first keeps the engine the single source of truth
and lets the app layer be rebuilt against a stable, tested surface. v1 simplification flagged: pension
contributions are funded from net surplus with no tax relief modelled (slightly understates the pre-retirement
pot), to revisit in the trust pass. [[2026-06-25 — Expenditure: 3-tier line items (essential / discretionary / self-investment) + spent-vs-saved]] [[2026-06-25 — Per-person longevity / health adjustment (new engine input)]]
**Status:** active

## 2026-06-25 — Forecast income completeness: count every source, no silent drop
**Decision:** The forecast must count **every** income source that should reach a household's spendable
cash, and a regression test guards each one. Found via live use: `PathProjector::incomeStreamsNominal`
summed only **taxable** streams and the tax-free branch was never added anywhere, so **DLA / any tax-free
income was silently dropped** — understating income and overstating the chance of running out. Fixed
(tax-free streams counted untaxed into net cash) + a regression test. The durable guard is a **per-source
completeness test** (salary, DB, State Pension, taxable + tax-free income streams, DC withdrawals, asset
drawdown each demonstrably contribute).
**Why:** This is the **completeness** sibling of the data-layer integrity discipline — reconciliation
catches double-counting (sum of parts == total); completeness catches the opposite (a part silently
dropped). Both are "no silent failure" applied to the maths, and both are exactly the class of bug Rob has
been burned by. The drill-down's **income-by-source** view is the visual guard that makes such gaps obvious
(docs/PLAN.md gotcha Q). [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active

## 2026-06-25 — Scenario model: base plan + delta what-if children + compare
**Decision:** Adopt the cashflow-modelling sector's standard shape (Voyant): a **base plan** that spawns
**named "what-if" child scenarios** created from a plain **"Create child" button**, each **overriding
anything the user changes** (often just 1–2 — rent, council tax, a healthcare savings amount, a person's
expected lifespan; sell-vs-stay, buy-vs-rent), with a **side-by-side Compare**. A child is
stored as a **delta — only its overridden parameters — on top of the base**; the effective inputs are
the base's persisted form-state (`builder_state`) **overlaid with the child's overrides**, resolved by
**one merge function** (with a round-trip test). It is **not** a full copy of the base. The child editor
is the **full builder pre-filled from the base** — whatever the user changes becomes an override (curated
levers like "retire 2 years later" are just shortcuts, **not** a limit), and **list items (expense lines,
pensions, accounts) gain stable IDs** so an override targets the right row across base edits (people
already have ids). Editing a saved scenario, spawning a child, and comparing all build on the persisted
form-state: edit reloads it, a child stores overrides against it, compare runs base + child.
**Why:** Confirmed with Rob — lightweight "tweak 1–2 parameters" children are exactly the what-if UX he
wants, and it is the market-leader pattern (Voyant's copy-on-write — "changing an item breaks the link
for that item only"; see [docs/RESEARCH-cashflow-modelling.md](docs/RESEARCH-cashflow-modelling.md) §1).
**Delta over full-copy specifically to avoid forking:** a full copy duplicates the whole base into every
child, so a later base fix leaves children stale — they **fork** — the exact "same quantity in two places
that drifts" the data-layer guardrails exist to prevent. A delta keeps the base as the single source; a
child holds only its tweaks and otherwise tracks the base. _(This **corrects an initial full-copy lean**
taken earlier the same day, recorded here so the plan does not fork — per Rob's "don't accidentally fork
ourselves".)_ The new bite to guard is **override resolution** (`effective = base ⊕ overrides`) and
**orphaned overrides** if the base shape changes — both covered by the merge function + tests. The
engine DTO stays a **derived** artifact regenerated from the resolved inputs, so inputs keep one source
of truth. The data-shape change this needs is **authorised** — Rob confirmed the clean rebuild even
though it reworks yesterday's prototype builder, which served to get a usable app for feedback; the UI
wins (person names, the State Pension shortcut) carry over and the draft mechanism folds into
`builder_state`.
Generalises [[2026-06-24 — Forecast services: run = 3-variant comparison, deterministic on demand]]; full
build order in docs/PLAN.md "Sector-informed build plan (2026-06-25)". [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active — **Phase B BUILT (2026-06-25):** `scenarios.builder_state` is the single source of
truth, the engine DTOs are derived from it (no reverse-mapper), structural columns are a projection, and a
saved forecast is editable in place (owner-scoped update-or-create that invalidates stale runs); the
`households`/`scenario_drafts` tables + their models/mappers are dropped (the draft is a `draft`-status
scenario). **Phase C2 BUILT (2026-06-26):** a child holds `parent_scenario_id` + an encrypted `overrides`
delta (no `builder_state`); the one merge fn is `App\Forecast\BuilderStateDelta` (`diff`/`merge`/`orphans`/
`structurallyDiffers`, round-trip + id-stability tested), resolved by `Scenario::effectiveBuilderState()`.
**List rows gained stable ids** (people kept p1/p2). The builder's child mode pre-fills from the base and
saves only the delta; a **structural add/remove is refused** (a delta cannot fork the base — gotcha N), a
**base edit propagates** to children (refresh + drop their stale runs), and a base delete **cascades**.
**Compare** runs base + children on their deterministic projection, side by side, never ranked. **v1
boundary recorded:** a child overrides *values* only; adding/removing a person/pension/account belongs to
the base or a new forecast (keeps the delta honest, no fork). The per-person longevity lever is wired into
the engine already (Phase A2); surfacing it as a builder what-if field is a C1 fast-follow (the merge
handles it for free).

## 2026-06-25 — Expenditure: 3-tier line items (essential / discretionary / self-investment) + spent-vs-saved
**Decision:** Replace the flat essential/discretionary totals with **line items as the source of truth**:
`{id, label, amount(annual), category, savedAsAsset}`, category ∈ **essential** (needs, the floor) /
**discretionary** (wants, can-drop) / **self-investment**. Essential/discretionary **totals become the sum
of the lines** (derived — reconciliation discipline). Self-investment is a **first-class tier** (learning,
tuition, books, savings plans, personal investments) — **not** derivable from contributions. Each
self-investment line carries a **`savedAsAsset` flag** (default false = *spent*): *spent* lines
(courses, books) are expenditure; *saved* lines (savings/investments) are a **contribution that builds net
worth**, which needs a small addition — **ongoing contributions on savings accounts** (as DC pensions
already have). **One home per pound:** a saved line **is** the contribution, never also entered as an
account balance.
**Why:** A budget that forces prioritisation (keep essentials / drop discretionary / invest in the future)
is the sector-standard income-&-expenditure model and feeds the income-floor ("essentials covered by
guaranteed income"). Self-investment can't be derived (Rob: it spans learning/tuition/books that never
touch an account), so it is a tagged tier; the spent/saved flag keeps the **forecast correct** (spent
reduces wealth, saved is retained + grows) and **double-count-safe**. The split is framed as **the goal,
not a fixed percentage** (50/30/20 vs 60/20/20 vary everywhere, and a prescribed target reads as advice →
trips the lint). Importers populate the lines (the IWT profile already routes Fixed→essential,
Guilt-Free→discretionary, Investments+Savings→saved). [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active — **CORE BUILT (2026-06-26, Phase C1):** `builder_state.expenseLines` (`{id, label,
amount, category, savedAsAsset}`) is the source; the `HouseholdAssembler` derives essential (Σ essential) and
discretionary (Σ discretionary + *spent* self-investment), and a *saved* self-investment line becomes a
balance-zero contributing ISA (`ongoingContributions`, applied from surplus by the existing engine —
**no engine change needed**), counted once (one home per pound). Flat totals dropped when lines exist;
legacy/imported scenarios seed lines from their flat totals on load. Reconciliation + completeness tested
(`ExpenseLineReconciliationTest`). **Implementation note:** the saved line is a synthetic ISA (a designated
single home for the saved amount), not a user-named wrapper — revisit if a real wrapper choice is wanted.
**Deferred (C1 fast-follow):** the results 3-tier display, the income-floor readout, importers emitting real
lines (they still emit flat totals → seeded into 2 generic lines), and the PLSA benchmark.

## 2026-06-25 — Per-person longevity / health adjustment (new engine input)
**Decision:** Add a **per-person longevity adjustment** so a what-if can model someone not expected to
reach peer-average age (e.g. known health conditions). It feeds the cohort-table `JointLifeSampler` as one
of: a **fixed assumed death age**, a **±years offset** to life expectancy, or a **mortality multiplier /
rated age** (insurer-style). Exact mechanism chosen at build; ships with a golden-master test.
**Why:** Rob wants to tweak expected lifespan in a child what-if; mortality is currently derived only from
DOB + sex with no health lever. This is a genuine **engine** feature (not a form-state override of an
existing field), so it is planned as its own small piece, keeping the engine framework-free + tested.
[[2026-06-24 — Mortality model: embed ONS cohort life tables]]
**Status:** active

## 2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures
**Decision:** Treat data-layer consistency as a hard, **tested** requirement, not a hope.
Concretely: (1) **one definition, one home** for every quantity — totals are **derived from their
parts**, never stored alongside them (e.g. `ExpenseProfile::targetAnnualSpend()` sums
essential+discretionary; ages derive from DOB; the engine DTOs stay the single source of truth that
storage and UI map to/from). (2) **Reconciliation invariants** must be asserted in tests:
sum(imported monthly line items)×12 == reported essential spend; net sale proceeds == sale −
mortgage − costs − CGT; per-variant terminal wealth == liquid + property. (3) Every spreadsheet
profile must be verified against a **sanitised real-file golden fixture** — a structurally faithful
copy of the real workbook (same layout traps: decoy "take home" rows, merged headers,
total/remainder rows) with the figures replaced by fakes, committed to the suite — because the
synthetic happy-path test alone let **two real double-counting bugs through** (`PayAndExpenditures`),
caught only by running on the real file by hand. (4) Every figure a view shows must trace to **one
computed value**; the panel, the CSV export and the interpretation read the same field, pinned by a
test. (5) Aggregated/imported totals are **surfaced for review** (no-silent-failure applied to
*counting*), so a mismatch is visible, never absorbed.
**Why:** Rob was burned on a past project not by hallucinated numbers (those were verifiable) but by
the data layer **inconsistently counting the same information** — the same quantity aggregated
differently in different places. The integer-pence rule, the DTO single-source-of-truth and the
round-trip equality tests already defend the *transport* boundary (a stored value decrypts to an
identical DTO), but they do **not** defend the *aggregation* boundary, where this class of bug lives.
The two `PayAndExpenditures` double-counting bugs are direct evidence this project is not immune; the
only thing that caught them was a manual real-file run, which CI does not repeat. Making
reconciliation an explicit, tested invariant — plus a committed real-shaped fixture — turns "verified
once by hand" into "verified every build." **Implemented for the importers (2026-06-25):**
`tests/Fixtures/Import/GoldenWorkbooks.php` (sanitised real-file fixtures, layout-faithful, fake
figures) + `tests/Unit/Import/ImportReconciliationTest.php` reconcile each profile's output to the
sheet's own stated totals. On its first run the guardrail immediately caught — and we fixed — **two
live wrong-aggregation bugs** in the IWT `ConsciousSpendingPlan` importer that the synthetic tests
missed: a per-bucket "… TOTAL" row was summed on top of its line items (essential came out ~2×), and
the `NET WORTH` Investments/Savings rows were miscounted as monthly contributions. The fix makes a
bucket's own TOTAL authoritative. Still to do: the displayed-figure-provenance test (#4, panel == CSV
== interpretation) and the import reconciliation panel (#5). [[2026-06-25 — `.xlsx` import via PhpSpreadsheet; a bespoke profile for the personal workbook]] [[2026-06-24 — Persistence: one encrypted payload per row, mappers in the app]]
**Status:** active

## 2026-06-25 — `.xlsx` import via PhpSpreadsheet; a bespoke profile for the personal workbook
**Decision:** Read `.xlsx` uploads with **`phpoffice/phpspreadsheet`** (an **app-layer** dependency —
the engine stays framework- and dependency-free). Profiles no longer take a raw string; they take a
sheet-aware **`Spreadsheet`** value object (sheetName → string rows) built by **`SpreadsheetReader`**
from CSV or XLSX. XLSX is read **data-only**, taking the values Excel last **cached** (no
recalculation), so unsupported formulas don't break the import. Multi-tab workbooks get a **tab
picker** (`updatedImportFile` lists sheet names; `Spreadsheet::select` narrows to the chosen one
before parsing). A bespoke **`PayAndExpenditures`** profile reads Rob's scenario tabs: the expenditure
block is anchored on **"% of Take Home Pay"** (the only heading unique to it — bare "take home" and
"Expenditure Item" both collide with rows/headers above it), summing monthly outgoings → essential;
the income block above the deductions header maps **State Pension → a state pension, DLA → tax-free
income, salary → gross, and a pension named in a later column → an annuity**. Imported income lands on
**Person 1 with no start age** — the sheet carries neither ages nor a person split — and that is
**flagged in the import summary**, not guessed. Everything imports as **essential** (no per-line split
yet). **Each mapping was verified by running the profile on Rob's real workbook**, not just synthetic
fixtures.
**Why:** Rob supplied real `.xlsx` files and wants to upload them directly. Reading cached values keeps
a formula-heavy personal workbook importable without a calc engine. Anchoring on the unique header and
verifying on the real file caught two double-counting bugs that synthetic tests alone missed (Rob
flagged the over-confidence) — so "trustworthy / no silent failure" is upheld by surfacing every
imported total and every unset field for review rather than fabricating ages or a discretionary split.
Refines [[2026-06-25 — Scenario builder is a wizard; spreadsheet import via a profile registry]]. The
**line-item expense categories** data model and **Nischa** (a 50/30/20 dashboard) stay deferred.
**Status:** active

## 2026-06-25 — Scenario builder is a wizard; spreadsheet import via a profile registry
**Decision:** (1) The builder is a **five-step, free-navigation wizard** (About & people; Pensions &
income; Your net worth = savings + the home; Spending; The decision). Stepping is **server-side**
(`@if($step===N)` + `wire:click` nav), not Alpine `x-show`, because the existing tests drive the
component by setting properties and calling `save()` (never the DOM), so server-side steps stay fully
unit-testable without a browser and the property/`save()` contract is unchanged. A failed `save()`
catches `ValidationException`, sets `$step` to the first step owning an errored field (a static
field→step map) and re-throws, so the user lands on the problem. Accessibility (`aria-current`,
focusable headings + error summary via dispatched events, `aria-invalid`/`aria-describedby`,
double-submit guard, a new `endAge ≥ startAge` rule) is built into the restructure rather than bolted
on. (2) **Spreadsheet import** is an `App\Import\ImportProfile` **registry**: each profile turns one
known layout's contents into a partial form state (`ImportResult`), money summed as **exact integer
pence** (`MoneyText`, mirroring the assembler's no-float rule), monthly figures ×12 to annual. The
**RetireForecast CSV** profile is the one calibrated reader; it pre-fills only spending + the main
salary and reports honestly what the household still needs by hand (budgets carry cashflow, not the
balance sheet). **IWT / Nischa ship as registered `UncalibratedProfile` stubs** that refuse with a
reason until a real sample export maps their cells — no guessing a layout we have not seen. XLSX
(needs phpoffice/phpspreadsheet) and line-item expense categories are deferred to Rob's call.
**Why:** A wizard was Rob's explicit UX ask and the single long form was the a11y pain point; keeping
it server-side preserves the test suite and avoids a browser dependency overnight. The profile
registry makes import extensible and honest — the popular third-party templates are first-class once
calibrated, and "no silent failure" holds (every refusal carries a reason). Reusing the integer-pence
rule keeps money lossless across the import boundary. [[2026-06-24 — UI: hand-rolled Livewire + a separate assembler, charts as enhancement]] [[2026-06-25 — External-review triage: what we adopt, and three declines]]
**Status:** active

## 2026-06-25 — Compliance layer built: partition lint, acknowledgement gate, walled-off interpretation
**Decision:** Implemented the regulatory layer (Phase 2 step 4) with these concrete choices.
(1) The banned-phrasing guard is `App\Compliance\OutputPhrasing` holding **directive-only** regex
patterns ("you should", "the best option", "is better for you", …) — never the bare nouns — so neutral
disclaimers that use "recommend"/"advice" in negated form ("not a recommendation", "does not tell you
what to do") pass. (2) The build test is a **path/namespace partition**: it scans every Blade view plus
all app PHP and asserts zero violations, with exactly two exemptions — the `App\Compliance` namespace
(the lint patterns + the `Interpretation` service) and any view whose filename contains
`interpretation`. A separate assertion proves the partition is load-bearing (the `Interpretation`
service *does* contain directive phrasing and is the only thing exempt). (3) The first-run
acknowledgement is a **middleware gate** (`EnsureDisclaimerAcknowledged`) redirecting unacknowledged
users to a dedicated screen and storing `users.disclaimer_acknowledged_at` — **not** a JS modal (a
server-side gate is testable and cannot be skipped); GDPR/account routes sit **outside** the gate
(data-subject rights are not withheld pending acceptance). (4) Per-result disclaimer + signpost are
reusable Blade components (`<x-disclaimer.result>`, `<x-signpost>`); every CSV export is prefixed with
the guidance-only disclaimer. (5) The interpretation capability is a `users.can_interpret` boolean
behind an `interpret` Gate, set via a Filament `UserResource` `ToggleColumn`; the gated partial and the
`Interpretation` service are the sole homes of directive wording. (6) Deleted the stock Laravel
`welcome.blade.php` (unused — the landing is `home.blade.php`; it tripped the lint with marketing copy).
**Why:** Directive-only patterns + a path partition keep the lint precise (no false positives on
disclaimers, no escape hatch for real recommendations) and make the walled-off advice mode auditable
rather than ad hoc. A middleware gate honours "no silent failure" and is provable in tests. Implements
[[2026-06-25 — Optional per-user "interpretation" (advice-style) output, admin-granted, off by default]]
and [[2026-06-24 — Regulatory posture: guidance only]]. Also folded in the tagged "no silent failure"
hardening: GDPR `export()` now includes the user's runs+results, `RunScenarioSimulation::failed()`
lands a dead worker's run in a terminal Failed state, and `ScenarioResults::currentRun()` is
owner-scoped against a forged `$runId`.
**Status:** active

## 2026-06-25 — Optional per-user "interpretation" (advice-style) output, admin-granted, off by default
**Decision:** Add an optional capability ("interpretation mode" / "what this suggests") that, when
enabled for a specific user, renders directive plain-language readouts ("under these assumptions,
buying lasts longer; renting runs out in N% of paths") alongside the neutral figures. It is **off by
default, the public default stays neutral guidance**, and it is **granted only by an admin** (a per-user
boolean on `users` behind a Gate ability, set from Filament) — never self-serve. The directive
sentences are produced by a single walled-off `Interpretation` service **from the computed numbers,
not hard-coded into the result Blade templates**. The banned-phrasing build test is therefore reframed
from "no banned phrasing anywhere" to a **partition check**: the neutral result/warning templates,
default formatter and exports must stay clean, and directive phrasing may exist **only** inside the
gated interpretation layer. Every output/export is labelled with the mode that produced it.
**Why:** For Rob's own and family use the directive framing is genuinely clearer, and giving it
privately is outside the FCA perimeter (not by way of business). Walling it off + admin-gating +
neutral-by-default keeps a live public deployment on the guidance side of the line, so the planned
public release survives the feature rather than being blocked by it. The toggle must **not** be
grantable to arbitrary public users on a live deployment (self/family only); doing so would be a
deliberate, separate regulated-perimeter decision. Refines, does not supersede,
[[2026-06-24 — Regulatory posture: guidance only]]; raises the priority of tightening
`User::canAccessPanel()` and the run-ownership scoping before public release.
**Status:** active

## 2026-06-25 — External-review triage: what we adopt, and three declines
**Decision:** A second-opinion review (MS Copilot, from the doc set) was triaged into the post-v1
backlog in [docs/PLAN.md](docs/PLAN.md) ("External review triage"). We **decline** three of its
suggestions as over-engineering or misaligned for a local-first single-user tool: (1) **per-row /
envelope encryption** — the blast-radius case assumes a multi-tenant server, but the whole SQLite DB
*and* the Laravel app key live on one personal machine, so app-key `encrypted:array` is right-sized;
revisit only on a public multi-user release; (2) a **native Monte Carlo accelerator** (Rust/WASM/SIMD)
— premature, and it breaks the framework-free pure-PHP ethos that makes the golden-master trustworthy;
10k PHP paths are already responsive; (3) **automated gov.uk scraping** of tax tables — fragile, and the
figure set is small, so manual sourcing with a `verified_on` date is *more* trustworthy, not less.
We also flag that the review's adviser-style metrics (implied withdrawal rate, critical yield,
replacement rate, narrative report, capacity-for-loss) may only be adopted **behind the
`OutputPhrasing` banned-phrase lint** and stated as neutral facts/definitions — never as targets or
benchmarks (e.g. no "safe 3–4% withdrawal range"), to stay on the guidance side of the line.
**Why:** Keeps the security/perf posture proportionate to an on-machine personal tool, protects the
engine's isolation, and holds the education-only constraint that is a hard project rule.
[[2026-06-24 — Regulatory posture: guidance only]] [[2026-06-24 — Engine is framework-free, in a path package]]
**Status:** active

## 2026-06-24 — UI: hand-rolled Livewire + a separate assembler, charts as enhancement
**Decision:** The scenario builder and result views are hand-rolled Livewire 4 components (Filament
stays admin-only). Form input becomes engine DTOs in a standalone `HouseholdAssembler` (not inside
the Livewire component), so the string→DTO conversion is unit-testable and reusable (the demo preset
later). Money the user types is parsed to exact integer pence by a string parser (split on `.`, pad
to 2dp), never `(float) * 100`, keeping "no float in money" true at the UI boundary. Every figure a
chart plots is also rendered as headline text and inside an accessible `<table>` (in a `<details>`)
with a CSV download; the ApexCharts canvas (bundled via npm, mounted by a small reduced-motion-aware
Alpine `chart` wrapper) is a progressive enhancement, never the source of truth.
**Why:** The plan mandates a hand-rolled Livewire builder and WCAG 2.1 AA charts where headline
numbers are text first. Separating the assembler keeps the lossless shape conversion provable in
isolation (it rebuilds the rich `HouseholdFixture` exactly). [[2026-06-24 — Persistence: one encrypted payload per row, mappers in the app]]
**Status:** active

## 2026-06-24 — Full-page Livewire uses the Blade layout component, not `layouts::app`
**Decision:** Full-page Livewire components render into the app's Blade layout component
(`components.layouts.app`) via `#[Layout(...)]`, not Livewire 4's default `layouts::app`. The base
`TestCase` calls `withoutVite()` so view tests do not depend on the gitignored `public/build`. Region
selection is guarded by actually asking `TaxYearRegistry::for()` to build the config, so Scotland is
refused with a clear error until its band pack lands (and auto-enables when it does), mirroring the
engine's own refusal rather than duplicating the rule.
**Why:** Livewire 4 registers `layouts` only as a component namespace, not a view namespace, so its
default page layout has no hint path here; reusing the one Blade layout the auth/marketing pages use
keeps a single source of truth. Tying the region guard to the engine avoids a second place to update.
**Status:** active

## 2026-06-24 — Forecast services: run = 3-variant comparison, deterministic on demand
**Decision:** A `SimulationRun` executes the engine's `HousingComparison` for the scenario's
household + housing action, producing **three `Result` rows** (stay_put, buy_outright, rent) on
one seed — the buy-vs-rent headline. The central deterministic "best estimate" forecast is
computed on demand by `ScenarioForecaster::deterministic()` and not (yet) persisted. The app
assembles all engine inputs in `ScenarioForecaster`; the base year is derived from the
scenario's tax year so runs stay clock-free.
**Why:** Buy-vs-rent is the point of the tool, and the engine already runs the three variants on
identical seeds, so one run → three comparable results is the natural unit. Keeping deterministic
output unpersisted avoids storing a figure the UI can recompute instantly. [[2026-06-24 — Persistence: one encrypted payload per row, mappers in the app]]
**Status:** active

## 2026-06-24 — Engine gains an optional progress hook (non-breaking)
**Decision:** Add an optional `?callable $onProgress` to `Simulator::run` and
`HousingComparison::compare` (default null = unchanged behaviour). The hook carries no I/O, so
the engine stays clock- and I/O-free; the app updates `progress_pct` from it and **cancels a run
by throwing from the hook** (`RunCancelled`), which the engine lets propagate. Progress is
per-path within a variant, with each variant weighted into a third of the overall bar. Chosen
over the plan's "chunk 10×1000 with incremental aggregation", which would need the engine to
expose mergeable partial percentiles (a bigger change for little gain at these run times).
**Why:** "Nothing long-running may run silently" needs a live progress signal, but the engine
must stay framework-free. An optional callback is the minimal faithful touch; throwing for
cancellation keeps cancellation entirely an app concern the engine need not know about.
**Status:** active

## 2026-06-24 — Runs: preview synchronous, full queued; queue driver deferred
**Decision:** Preview runs (~1,000 paths) execute synchronously for responsiveness; the full run
(10,000 paths) is queued via the standard Laravel queue abstraction (`RunScenarioSimulation`
job, holding only the run id). The concrete queue driver is left to infra — database/sync
locally, Redis + Horizon if/when needed — since the mechanism (job + status + progress + cancel)
is driver-agnostic. The seed is generated and recorded when not supplied; the assumption set is
snapshotted (frozen) on the run so results survive later edits to the live set.
**Why:** Matches the plan's preview-vs-full split without committing the local-first app to a
Redis dependency it does not need yet. Recorded seed + frozen snapshot make every stored run
reproducible and auditable. [[2026-06-24 — Engine gains an optional progress hook (non-breaking)]]
**Status:** active

## 2026-06-24 — Persistence: one encrypted payload per row, mappers in the app
**Decision:** Store each Household and Scenario as clear structural columns (name, region,
variant, base tax year, status, owner, timestamps) plus **one `encrypted:array` payload**
holding all the sensitive detail, rather than ~30 encrypted columns. The DTO↔array mapping
lives in the **app** (`app/Finance/Mapping/`), not the engine, so the engine stays
framework- and serialization-agnostic; the readonly DTOs under `packages/finance-engine/src/Dto`
remain the single source of truth and Eloquent maps to/from them.
**Why:** Encrypted columns are unindexable anyway, so a single payload is simpler and keeps
listing/filtering on the clear columns. Keeping the mapper app-side preserves the engine's
isolation. A round-trip test asserts a saved row decrypts to an identical DTO. Follows the
plan's persistence section. [[2026-06-24 — Engine is framework-free, in a path package]]
**Status:** active

## 2026-06-24 — Withdrawals on the pension; SimulationRun/Result deferred
**Decision:** Planned pension withdrawals live on the DC pension inside the household payload
(faithful to `DcPension::$withdrawalPlan`), **not** duplicated as a scenario-level field, so
there is one source of truth for them. The `SimulationRun` and `Result` tables are deferred
to the forecast-services step (there are no results to persist until the engine is wired into
the app).
**Why:** The data-model sketch listed withdrawal_decisions on Scenario, but the canonical DTO
already carries them on the pension; honouring the DTO avoids a second, divergent home for the
same data. Deferring run/result storage keeps phase-2 step-1 focused on input persistence.
**Status:** active

## 2026-06-24 — Auth: Fortify headless, web-route guests redirect to login
**Decision:** Install Laravel Fortify but run it **headless** (`config/fortify.php` views
disabled) until the Livewire UI phase builds the login/register screens. GDPR/account routes
sit behind the `auth` middleware; an unauthenticated visitor is **redirected to login** (302),
which is the correct behaviour for a web app (not a 401 API response). A placeholder named
`login` route exists so the redirect target resolves until the real screen ships.
**Why:** The auth backend is needed now (ownership scoping, GDPR), but the screens belong with
the rest of the UI. Anonymous use writes nothing because every write path is auth-gated.
**Status:** active

## 2026-06-24 — Admin: Filament 5 (Livewire 4); assumption-set figures stay sourced
**Decision:** Use Filament 5 for the admin panel at `/admin` (it pulls **Livewire 4**, a bump
from the plan's stated Livewire 3). The AssumptionSet resource curates metadata (name, source
note, default) and a model hook keeps at most one default; the **sourced numeric figures are
seeded from the engine's signed-off `AssumptionSetLibrary` and are not casually editable in
the admin** (numeric editing is a deliberate, flagged follow-up). The tax-year audit page is
read-only over the registry. `User::canAccessPanel()` returns true for this local single-user
app (tighten before any public release).
**Why:** The figures are sourced and signed off; editing one means re-sourcing it with a new
verified-on date, which should be a deliberate act, not a stray form save. Keeps the
"no magic numbers, every figure sourced" posture intact. [[2026-06-24 — Tax figures versioned per tax year, sourced and dated]]
**Status:** active

## 2026-06-24 — Mortality model: embed ONS cohort life tables
**Decision:** Drive stochastic joint-life mortality from embedded ONS cohort life tables
(by single year of age and sex), sampling each partner's age of death per path and running
the household to the last survivor.
**Why:** Cohort tables account for future mortality improvements, so lifespans (and the
"will the money last" answer) are realistic. Rob chose this over a parametric fit (compact
but less precise at extreme ages) and over period tables (simpler but understate longevity).
Larger data ingest, but a one-off, and it carries a clear ONS source.
**Status:** active

## 2026-06-24 — Forecast mechanics: dual drawdown strategy + cautious default allocation
**Decision:** (1) Ship TWO drawdown strategies and compare them side by side rather than
picking one: "tax-efficient" (cash → GIA → ISA → DC pension last) and "pension-aware" (draw
DC pension income earlier, up to a sensible band, to reduce the post-April-2027 IHT estate).
Default display = tax-efficient. (2) Default invested-pot allocation (DC, ISA, GIA) =
cautious **40% equities / 60% bonds**, no cash within pots; cash accounts use the cash
assumption. Both are runtime-configurable per scenario.
**Why:** The drawdown order trades off income tax now vs sheltered growth and IHT later;
showing both keeps the tool neutral (illustrate consequences, not recommend) and surfaces the
April-2027 tension. A cautious 40/60 suits a retired household relying on the pot for income
(lower sequence-of-returns risk). [[2026-06-24 — Forecast mechanics: ... ]]
**Status:** active

## 2026-06-24 — Assumption figures signed off (adopted as proposed)
**Decision:** Rob signed off the researched figures in [docs/ASSUMPTIONS.md](docs/ASSUMPTIONS.md)
as proposed. Set A (FCA real returns + DMS vols) is the engine default; Sets B (DMS
historical) and C (OBR/BoE) ship as compare overlays. Includes the flagged judgement calls:
cash real vol overridden to 2% (inflation modelled separately), Eq–Cash/Bond–Cash
correlations as reasoned estimates, house growth +1% real. Figures stay runtime-overridable
and are re-verified at build time.
**Why:** Unblocks the forecast year-stepper and Monte Carlo with defensible, cited defaults.
**Status:** active

## 2026-06-24 — Default assumptions: FCA expected returns + DMS volatilities
**Decision:** The default AssumptionSet uses FCA-derived expected returns combined with
Barclays Equity Gilt Study / Dimson-Marsh-Staunton (DMS) historical volatilities and
correlations. DMS and OBR/BoE-inflation sets ship as compare overlays. Claude researches and
proposes the actual cited figures; **Rob signs off before any forecast is shown as real.**
**Why:** FCA projection rates are the familiar, defensible default but publish only nominal
growth brackets, not the volatility/correlation a Monte Carlo needs; DMS supplies those from
a coherent historical source. Pairing them gives a defensible default that the stochastic
engine can actually run. [[2026-06-24 — Modelling depth and scope (from approved plan)]]
**Status:** active

## 2026-06-24 — Savings + dividends computed in one combined income-tax pass
**Decision:** The plan listed separate `SavingsTax` and `DividendTax` calculators. Instead,
savings and dividend tax are computed inside `IncomeTaxCalculator::compute(TaxableIncome)`,
a single combined pass over the rate bands, rather than as three independent calculators.
`onNonSavingsIncome` is retained for the simple case (and the existing tests).
**Why:** UK income tax stacks the three categories in a fixed order (non-savings, savings,
dividends), and the savings starting-rate band, Personal Savings Allowance and dividend
allowance all consume rate-band space even though charged at 0%. Computing them separately
cannot get the band interactions right; a shared band cursor is the only faithful model.
**Status:** active

## 2026-06-24 — Scaffold the standard doc set
**Decision:** Added PRD.md, DATA-MODEL.md, DECISIONS.md and the root CLAUDE.md orient
tripwire, porting goal / data model / decisions out of docs/PLAN.md so they have a standard
home. docs/PLAN.md remains the exhaustive scope source of truth.
**Why:** The project adopted the documentation standard; the orient hook flagged these as
missing. Keeps a fresh session from re-reading the whole plan to find the shape.
**Status:** active

## 2026-06-24 — Local-first, personal use, no hardcoded client data
**Decision:** Build a local single-user site. Rob enters the couple via the UI himself; any
first-run sample must be obviously fictional. Possible free public release later, so do not
design accounts out — just defer them.
**Why:** The immediate need is Rob's own decision support for a known real couple. Hardcoding
their data would leak PII into the repo and bake in one scenario.
**Status:** active

## 2026-06-24 — Money is hand-rolled integer pence (brick/money dropped)
**Decision:** Use a hand-rolled `Money` value object over integer pence. Do not re-add
brick/money without re-checking the clash.
**Why:** `brick/money` could not resolve against `brick/math` 0.18 in the Laravel 13 lock.
The plan already listed integer pence as the primary option; zero dependencies strengthens
the engine's framework isolation.
**Status:** active

## 2026-06-24 — Engine is framework-free, in a path package
**Decision:** The calculation engine lives in `packages/finance-engine`
(`retireforecast/finance-engine`, path repo, required `"*"`), with zero Laravel
dependencies, no I/O and no clock. Tests run as pure PHPUnit, no Laravel bootstrap.
**Why:** Isolation is what makes the HMRC worked-example tests and the Monte Carlo
golden-master trustworthy. The Laravel app is a shell around the product.
**Status:** active

## 2026-06-24 — Tax figures versioned per tax year, sourced and dated
**Decision:** Every tax figure lives in a per-tax-year config carrying a `source` URL and a
`verified_on` date. Two stale-brief corrections baked in: income-tax threshold freeze runs
to **April 2031** (not 2028); **dividend rates rise in 2026/27** (ordinary 8.75→10.75,
upper 33.75→35.75).
**Why:** No magic numbers; figures must be defensible against gov.uk. 2025/26 and 2026/27
genuinely differ, so they are distinct config years.
**Status:** active

## 2026-06-24 — Modelling depth and scope (from approved plan)
**Decision:** HMRC-accurate deterministic engine PLUS Monte Carlo with stochastic joint-life
mortality. Pensions: DC, DB, State Pension. Housing: buy-cheaper-outright vs rent on
identical seeds. IHT/legacy in as a toggle (incl. pensions entering the estate from Apr
2027). Assumptions are a runtime/display choice across several sourced sets (FCA default),
not baked in. England/Wales/NI first; Scotland income tax + LBTT/LTT out of v1 (region
resolver throws rather than guessing).
**Why:** Matches the decision-support goal: the consequences only become visible if tax,
longevity and sequence risk are all modelled properly. Captured here from docs/PLAN.md.
**Status:** active

## 2026-06-24 — Regulatory posture: guidance only
**Decision:** Education/guidance only, never a personal recommendation. A build-time test
fails if any result template contains banned recommendation phrasing. Signpost Pension Wise
/ MoneyHelper / FCA-regulated advisers.
**Why:** Personal recommendations on pensions/drawdown are FCA-regulated activity. Staying on
the guidance side of the line is a hard design constraint, not a wording afterthought.
**Status:** active
