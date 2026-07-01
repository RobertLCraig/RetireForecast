# PLAN (DRAFT) — tax-efficient withdrawal sequencing across wrappers ("fill the band")

> **Status: DRAFT proposal, not a decision.** Dated 2026-06-30. To be reviewed by Rob, then
> folded into docs/PLAN.md (backlog) + a DECISIONS.md entry once the open questions are settled.
> Nothing here is built **beyond what the "Already built" section explicitly notes as existing.**
> Motivated by the competitive scan ([docs/RESEARCH-competitive-gap-analysis.md](RESEARCH-competitive-gap-analysis.md),
> Cluster A) — the highest-value, most on-brand net-new item, because RF already owns the HMRC engine.
>
> _Refreshed 2026-07-01: **Pension Credit (a means-tested benefit) is now live in the engine** (`PensionCreditCalculator`
> wired into `PathProjector`), which adds a first-order means-tested-benefit interaction to sequencing — see "How it
> relates" + "Tax nuances". The foundation this spec builds on (`Forecast/DrawdownStrategy`) is unchanged._

## Why / motivating case

The **order** you draw ISA vs DC pension vs GIA vs cash — and how much you take from each
*before crossing a tax threshold* — materially changes lifetime tax and how long the money lasts.
This is the UK analogue of US Roth/taxable/tax-deferred ordering. The competitive finding is stark:
**RightCapital advertises ~$868k lifetime tax saved** purely from sequencing + band-filling, and
**Timeline shows the success rate rising to 94%** from re-ordering withdrawals. Yet even *professional*
UK tools mostly let the adviser **specify** the order (Voyant's savings order; Conquest's
funding-preference menu) rather than optimise it, and **none quantify the £ saved** to a consumer.
A UK tool that surfaces "this order pays £X less lifetime tax" is white space — and it's the most
on-brand addition RF can make, because the penny-accurate tax engine that makes it trustworthy
already exists.

## Already built (the head start — do not rebuild)

RF is **further along here than the gap analysis assumed**. Existing today:

- **`Forecast/DrawdownStrategy`** — a two-case strategy enum, both shipped and compared side by side
  (tax-efficient is the default display):
  - **`TaxEfficient`** — spend non-pension assets first (`cash → gia → isa`), leave the pension to
    grow, draw it only as a last resort.
  - **`PensionAware`** — draw taxable pension income **up to the basic-rate band first** (capped to
    avoid a tax spike), then non-pension, then the rest of the pension — to run the pot down ahead of
    **unused pots entering the IHT estate from April 2027**.
- **`PathProjector::fundShortfall()`** draws assets in the strategy's order to meet a spending
  shortfall, grossing pension withdrawals up for tax (`grossUpPension`/`marginalTax`), and reports
  `fromPension` vs `fromAssets` so the cashflow ladder shows where the money came from.
- **GIA is CGT-aware on disposal** — `disposeGiaSlice()` realises the pro-rata gain and consumes the
  matching cost basis (no double-tax later); `capitalGainsTax()` charges the realised gain per owner.
- **A "cap pension to the basic-rate band" mechanism already exists** (the `$capToBasicRate` path) —
  this is the **seed of "fill the band"**, currently used only by `PensionAware`.
- **`Dto/WithdrawalInstruction`** (PCLS / UFPLS / drawdown at a given age) for explicit user-planned
  withdrawals, executed by `plannedWithdrawals()`.
- All tax thresholds are **sourced + `verified_on`** in `TaxYear/TaxYearConfig` (income-tax bands,
  CGT AEA + rates, allowances), so a planner reads them — **no new magic numbers.**

So the foundations — named strategies, band-capped pension draw, CGT-aware GIA disposal, IHT-2027
awareness, identical-seed Compare — are in place. This feature **generalises** them.

## The gap (what sequencing optimisation adds)

1. **The non-pension order is hardcoded `cash → gia → isa`, and GIA is drawn in full before ISA.**
   There is **no "draw GIA gains up to the CGT annual exempt amount, then switch to ISA"** — so the
   free £3,000 CGT allowance is either left unused (ISA drawn that could have waited) or overshot
   (more gain realised than the allowance shelters). The ISA-last default also forgoes ISA's role as
   the tax-free *band-filler* between pension tranches.
2. **"Fill the band" exists only as the `PensionAware` basic-rate cap.** There is no general,
   multi-threshold fill — e.g. pension up to the **personal allowance** (free) → GIA gains up to the
   **CGT AEA** (free) → **ISA** (always free) → pension up to the **basic-rate ceiling** → only then
   higher-rate pension / further GIA.
3. **No modelling, in the sequencing, of:** the **£100k–£125,140 personal-allowance taper** (a 60%
   effective band worth stepping around), the savings/dividend allowances' interaction with the
   fill order, or the timing of the 25% PCLS relative to band-filling.
4. **The user picks one of two presets; there is no quantified lifetime-tax £ delta between them**,
   and no search across richer orderings. The whole point — "this order saves £X" — is not surfaced.

## Target shape (proposed)

A configurable, **threshold-aware** withdrawal policy, plus a comparison that **quantifies the £** —
all engine-side and framework-free.

- **Generalise `DrawdownStrategy`** from a 2-case enum into a small set of **named, sourced
  strategies** (keep both existing ones; add **"Fill the bands"**). Represent it as a value object
  the projector reads, so it stays a single source the deterministic forecaster, the Monte Carlo and
  the per-variant ladder all share (the same discipline as assumption resolution in
  `ScenarioForecaster`).
- **A per-year band-fill planner.** Given each person's already-taxable income for the year, draw to
  meet spend in a tax-minimising order: (a) pension up to the **personal allowance**; (b) GIA gains up
  to the **CGT AEA**; (c) **ISA** (tax-free); (d) pension up to the **basic-rate ceiling**; (e) only
  then higher-rate pension / further GIA — **per person** (each spouse fills their own allowances;
  couples already split GIA across two AEAs in the CGT calc). Respects home/BTL illiquidity (it draws
  liquid wrappers, not the house).
- **A comparison artefact that surfaces the £.** Run the chosen strategies on **identical seeds** (the
  existing Compare + `DrawdownStrategy` lever already do this) and show the **lifetime-tax £ delta** and
  the **terminal usable-wealth delta**. This is RightCapital's signature, and RF already runs variants
  on identical seeds, so it is mostly *presentation* over existing runs.

## How it relates to the rest of the work

- **The new safety-floor feature** (surplus/shortfall, 2026-06-30) answers *how much* to draw each
  year (meet spend, stay above the buffer); sequencing answers *from where*. They compose: the floor
  is the trigger, the sequence is the source.
- **Dynamic guardrails** (Cluster A) sit on top of *how much*; sequencing is orthogonal and can land
  first.
- **Multi-property** (the sibling draft) adds rental income that occupies tax bands — the band-fill
  planner must read it (and, with Section 24, flag the simplification).
- **Means-tested benefits are now live** (`PensionCreditCalculator` in `PathProjector`, 2026-07-01) — and they
  **invert the sequencing calculus for a low-income household**. Guarantee Credit = max(0, guarantee − (assessable
  income + capital tariff)), so every £1 of *assessable income* (incl. taxable pension) above the guarantee claws
  Pension Credit back **£-for-£ — a 100% effective rate**, on top of any income tax. So "fill the pension up to the
  personal allowance for free" is **wrong** for a household on Guarantee Credit. ISA / 25% PCLS are **capital,
  disregarded as income**, so they don't claw back the income side — and spending capital *down* can even *raise* the
  award (a lower capital tariff). This is the strongest argument that sequencing must be **household-specific, not one
  fixed order** — and it is now testable because the engine computes the award.

## UK thresholds the planner respects (all already in `TaxYearConfig`, sourced + dated)

- Personal allowance (£12,570; **tapered £1 per £2 over £100,000 → £0 at £125,140** — the 60% trap).
- Basic-rate ceiling (£50,270), higher-rate (£125,140), additional-rate thresholds.
- 25% PCLS / lump-sum allowance (£268,275).
- CGT annual exempt amount (£3,000) + rates (18% basic / 24% higher).
- Savings + dividend allowances and the starting-rate band (the engine already does one combined pass).
- **MPAA** (£10,000) once a pension is flexibly accessed — a cap on how much DC can be recycled.

## Tax nuances to get right (and flag where simplified)

- **The 60% PA-taper band** (£100k–£125,140): band-filling should treat this as a threshold to step
  *around*, not just the basic/higher boundary. (Matters only for higher earners — may be out of scope
  for the target couple; see Open questions.)
- **One rate per owner per year** vs straddling the band boundary from exact income — the same bounded
  simplification already flagged for partial-PRR CGT.
- **MPAA** after flexible access caps recycling; the planner must not "fill" beyond it.
- **Section 24** (if multi-property lands): BTL mortgage interest is a 20% credit, not a deduction —
  rental income occupies bands and must be in the already-taxable figure the planner reads.
- **PCLS interaction**: taking tax-free cash changes the taxable balance available to fill bands;
  decide whether the planner times PCLS or leaves it user-specified (Open questions).
- **Pension Credit (means-tested) interaction — now live** — for a Guarantee-Credit household, assessable income claws
  the credit back **£-for-£** (a 100% effective marginal rate), *inverting* the "draw pension in the free band first"
  heuristic (see "How it relates"). The engine now computes the award (`PensionCreditCalculator`), so a
  Pension-Credit-aware planner can read it and avoid destroying it; a naive band-filler would silently claw the benefit
  back — exactly the completeness class of bug the project guards against.

## Tests (the project's reconciliation / completeness bar)

- **A sequencing edit demonstrably reaches the result** — the chosen order changes lifetime tax
  (completeness, the DLA-drop lesson applied to strategy).
- **Reconciliation of the £-delta**: shown delta == (tax of run A − tax of run B), both the engine's
  own figures, never a re-derivation.
- **Band-fill correctness**: a year's pension draw stops *exactly* at the PA / basic-rate ceiling given
  other income; GIA disposals stop at the CGT AEA; ISA is drawn tax-free in between.
- **Per-source completeness**: ISA, GIA, cash and pension each still reach the forecast under every
  strategy (no wrapper silently skipped).
- **Golden case**: a household where "Fill the bands" beats both presets, with the **£ saved asserted**.
- **Engine isolation** stays intact (no `App\…` / `Illuminate\…` in the engine).

## Build order (all-in v1, per Rob's decisions — each slice green + committed)

**Shared-file note:** every slice centers on `PathProjector::fundShortfall` + `DrawdownStrategy` — the engine
hot file other lanes also touch (Lane A stress-test, Lane B forced-housing). Keep changes **additive** (a new
enum case + new branches, not a refactor of the existing `TaxEfficient` / `PensionAware` logic), re-check `git`
before each engine edit, and claim the lane in HANDOVER.

1. **Core — the `FillBands` strategy.** New `DrawdownStrategy::FillBands` + its draw order in `fundShortfall`
   (pension→PA, GIA gains→CGT AEA, ISA, pension→basic-rate ceiling, then the rest), **Pension-Credit-aware**
   (skip the free-band pension draw when it would claw back Guarantee Credit; prefer ISA/PCLS). + engine tests
   (band-fill stops at the exact thresholds; the PC-aware path; per-source completeness).
2. **PA-taper.** The fill logic steps around the 60% £100k–£125,140 band.
3. **£-delta in Compare.** "Strategy X pays £Y less lifetime tax" (neutral, always) + the advice-gated steer
   (`personal_use`). Reuses Compare's identical-seed runs; reconciliation test (delta == tax(A) − tax(B)).
4. **PCLS timing.** Let the planner choose when to take the 25% tax-free cash (vs user-specified).
5. **Search-optimiser (last).** A bounded search over orderings to minimise lifetime tax; flag cost/benefit.

Each slice ships alone; stopping after 1–3 already delivers the headline value.

## Decisions (Rob, 2026-07-01)

1. **Strategy set → "Fill the bands" as a third named `DrawdownStrategy`** (not a general planner yet):
   draw PA → CGT AEA → ISA → basic-rate pension → rest, compared on identical seeds via the existing Compare.
2. **£-delta framing → both:** always show the **neutral number** ("Fill-the-bands pays £X less lifetime tax"),
   and add the **directional steer** ("lean towards this") only when advice mode is on (behind `personal_use` /
   the `interpret` gate, like the buy-vs-rent narrative). The neutral figure passes the banned-phrasing lint.
3. **Pension-Credit-aware → yes** (the single biggest correctness point): for a household on Guarantee Credit
   the fill order must NOT draw pension income that claws the credit back £-for-£ — read the engine's award
   (`PensionCreditCalculator`) and prefer capital (ISA / PCLS, disregarded as income) in that case.
4. **PA-taper 60% band → in v1:** the fill logic steps around £100k–£125,140.
5. **PCLS timing → in v1:** the planner may decide *when* to take the 25% tax-free cash (vs user-specified).
6. **Search-optimiser → in scope, sequenced LAST** (heaviest, least-certain payoff): a bounded search for the
   cheapest ordering. The named strategy + refinements land first.

Rob chose the **full capability**, so v1 is the whole feature, delivered in the build order below, each slice green.
