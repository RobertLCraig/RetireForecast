# PLAN: decision-support — lever thresholds + combination comparison

> A staged spec for the "what combination gives us the best chance?" feature. A fresh agent should be
> able to build it phase by phase. It carries real modelling + regulatory decisions (see "Open questions
> — get Rob's answers first") AND a correctness spine (Phase 0) that must hold before any UI, because
> the obvious cheap implementation is *quietly wrong on the exact household this feature was built from*.
> Read `HANDOVER.md` (orient), then the `docs/PLAN.md` section **"Decision-support: lever thresholds +
> combination comparison (2026-07-04)"** (the reviewed summary this expands), then this. Follows the doc
> standard + `CLAUDE.md` engine rules (framework-free engine, integer pence, reconciliation/completeness
> invariants, no silent long-runs, no silent path cuts, every figure sourced + table + CSV, tests green
> on every commit). The real couple this serves is in `docs/SCENARIO-V2.local.md` (gitignored, private).

**Stage:** SPEC — not built. Hardened by a 4-agent review (2026-07-04: gaps / pitfalls / presentation /
communication); the corrections are folded in below.
**Last updated:** 2026-07-04.

## What this is, and the gap it closes
The product's core job is to answer "which combination of levers gives the best chance the money lasts?"
Today the engine can answer it but the app cannot *show* it: a user sees one lever-point at a time (the
deterministic "Explore the levers" sliders — `ScenarioResults::applySliders` / `sliderForecast`) or
hand-builds what-ifs and eyeballs Compare. The **thresholds** ("safe up to ~£X") and the **trade-offs
between combinations** are invisible; producing them needed a by-hand Monte-Carlo sweep (the V2 session:
buy-price ceiling ~£260k@retire-67 / ~£300k@retire-70; a child ~£150–330/mo closes the gap; and,
counterintuitively, living longer *raises* success because the binding risk is survivor-poverty after
the first death). This feature makes that analysis a first-class, interrogable part of the app.

**Audience split (load-bearing).** The *decision-makers* are not numbers people — a "line goes up / dives
below the floor" picture lands; probabilities, decimals and dense tables do not. The *analyst* (Rob) must
interrogate every figure. So every output is a plain-language/visual headline over a collapsed drill-down
of the exact numbers + accessible table + CSV.

## Open questions — get Rob's answers first
1. **Ordering in the *public* build.** A best-first list is implicit advice (the banned-phrasing lint is
   blind to sort order). Fine now (private, `compliance.personal_use = true`). Decision: when the flag flips
   for public release, should the comparison ever show a **gated** best-first ordering, or **never rank**?
   (This plan assumes: unordered in guidance mode, ordering behind the `interpret` gate — Phase 3.)
2. **Family contribution × Pension Credit — RESOLVED 2026-07-04 (researched, Rob asked "how does the DWP
   actually handle it").** Model regular family/third-party money as **fully disregarded income** for
   Pension Credit — this is the *correct* DWP treatment, not merely the optimistic one. gov.uk's Pension
   Credit adviser technical guidance lists **"regular payments from a charity or relative"** under *"What
   doesn't count as income"*; a regular gift is not income and not notional income. Caveats to model when
   the lever ships: (a) **maintenance** (from a former partner / the other parent of a child) is *not*
   voluntary and is not disregarded; (b) a **one-off lump sum** banked as **savings** becomes *capital*,
   which PC *does* assess (tariff income above £10k, and the £16k HB/CTS cliff) — so a regular income
   stream is disregarded but a large banked gift counts. Sources: gov.uk *"A detailed guide to Pension
   Credit for advisers and others"*; entitledto *"Income from voluntary or charity sources"*. Verified
   2026-07-04. (Deferred lever, not in the core phases — but the modelling call is now made.)
3. **Target(s) — RESOLVED 2026-07-04 (Rob).** Sweep **both** an essentials-last curve **and** a full-spend
   -last curve (essentials + discretionary). Design the `SweepEngine` **parameterised on the success
   predicate** (which spend bar) **and the target probability**, so 95%, a 90/95 pair, and both curves are
   all caller choices — the engine doesn't hard-code the bar. (Superseded the earlier "essentials-only
   v1" assumption.)
4. **Which levers ship in v1** (Phase 4 menu) and whether the **2-D frontier** (Phase 5) is v1 or a
   fast-follow. (Assumes: buy-price + retirement-age frontier is the flagship and should be v1.)

## Correctness spine — Phase 0 (must hold before any UI is built) — **BUILT 2026-07-04**
**Status: the spine is built and green** — `packages/finance-engine/src/Sweep/` (`SweepEngine` +
`SweepLever`/`SweepMetric`/`SweepPoint`/`SweepCurve`/`Crossing`/`Frontier` + verdict enums), tested in
`packages/finance-engine/tests/Sweep/SweepEngineTest.php`. S1 (the deterministic-median pass over-promises
on a survivor-cliff household), S2 (the 2-D frontier primitive), S3 (the banded crossing verdicts) and
pinned-seed reproducibility + Wilson confidence intervals all hold. Tests use **synthetic** levers +
a synthetic survivor-cliff scenario (V2's real figures are private, never committed). **What remains from
Phase 0's original wish-list:** the real-world levers (buy price, retirement age) — deferred to Phase 4's
lever menu, since the spine is lever-agnostic — and per-component seeded RNG substreams (the CRN discipline
note below) for levers that change RNG consumption; today levers that do so must declare
`LeverDirection::Unknown` and are not monotone-fit. **Next: Phase 1 (the app-layer queued job).**

These three are the reasons this is a staged plan rather than a slider tweak. Proven headlessly, with tests,
before building anything a user sees.

- **S1 — never bracket a tail crossing with a deterministic pass.** `DeterministicForecaster` runs each
  person at their *independent median* death age (`RepresentativeDeathAge::forPerson` →
  `CohortLifeTable::medianDeathAge`), so the survivor-poverty tail (first death *early*, second *late*) —
  the binding risk — **never appears** in the deterministic path. A median pass locates success ≈ 50%, not
  the 95% *tail* crossing, and is biased *optimistic* (~£120k high on buy price for V2). The crossing is a
  Monte-Carlo quantity and must be found by MC. Allowed: a low-path **MC** grid with common random numbers,
  interpolate the crossing; or a **tail-calibrated** death stress (first-dier low percentile, survivor high
  percentile) used *only* as a wide outer bracket that a full MC then confirms.
- **S2 — the flagship answer is a 2-D frontier, not a 1-D sweep.** "£260k at 67 / £300k at 70" varies two
  levers; a single-lever curve prints £260k as if unconditional. The primitive is a **parametric threshold**
  (threshold of lever A vs lever B). Every 1-D readout pins its held-fixed context.
- **S3 — define crossing semantics.** Handle **already-safe** (no crossing → "already on track"),
  **unreachable** (never crosses in range), **non-monotone / multi-crossing** (drawdown, SP-deferral, and
  the longevity lever are not monotone). Report the first crossing as a **band with its MC confidence
  interval**, never a point; monotone-fit only where the lever is provably monotone (buy price is; longevity
  is not).

**Phase 0 deliverable:** a framework-free `SweepEngine` in `packages/finance-engine/` (no Eloquent, no I/O,
no clock) that takes a `Household` + `ForecastSettings` + `AssumptionSet` + a lever spec + a grid, and returns
a curve of `{leverValue, successProbability, ci}` plus a detected crossing (or a no-crossing verdict). It runs
MC per grid point on a **pinned seed** with per-component seeded substreams (see discipline below).
**Tests (engine suite):** reproduces the V2 buy-price curve (£200k→~100%, £260k→~95%, £300k→~77% at
retire-67) within CI; the buy-price×retire-age frontier yields ~£260k@67 / ~£300k@70; a golden-seed curve is
byte-stable; a deliberately deterministic-bracketed crossing is shown (in a test) to differ materially from
the MC crossing (guards S1 from regressing); already-safe / unreachable / non-monotone inputs return the right
verdicts (S3).

## Statistical & operational discipline (applies to every phase)
- **Pin one explicit seed** through every grid point and combination and record it (`SimulationRunner::createRun`
  defaults to a *random* seed — override it). CRN gives a smooth curve **only for monotone levers**. CRN
  **breaks** when a combination changes RNG consumption (toggling care, adding a household member desyncs the
  return stream in `Simulator::run`) — give mortality / care / returns **their own seeded substreams**, or hold
  structure fixed within a comparison set and flag when identical-paths comparability can't hold.
- **No silent long-runs, no silent path cuts.** There is **no transient-forecast entry point** today (the live
  preview was retired; `ScenarioResults::makeWhatIf` persists a child before forecasting) — Phase 0's engine
  entry is builder-state/`Household`-in, curve-out, **no persistence**. The sweep runs as a **queued job reusing
  the Compare live-progress + cancel UX**, never synchronously on the web request. **Path-count-per-point is
  explicit** on every readout; the first-N paths of a seed are an honest sub-sample, so show "preview (500
  paths)" tightening to "confirmed (10k)". The **combination generator is bounded** (single-lever + a curated
  set of pairs, or a greedy/beam search toward the target — never an unbounded cross-product).
- **Provenance + staleness.** Every new figure ships with its table + CSV + seed + paths + assumption snapshot.
  A computed threshold is **engine-derived but can go stale** — the assistant's G1 grounding checks provenance,
  not freshness — so persist it keyed by an inputs hash and ride the **same input-edit invalidation as
  `SimulationRun`**; surface it only when the hash matches the current inputs.
- **Every threshold carries its optimism stack.** The buy-cheaper ceiling stacks assumptions that all push it
  *up* (`withoutPropertyCosts()` drops the flat's service charge and adds no new one; new-home running costs are
  only price-scaled by `scaledRunningCosts`; house/salary growth is deterministic inside the MC; care off by
  default; CGT on the hand-entered base cost; no SDLT surcharge). The readout carries the caveat set the results
  page already computes (`assumptionsPanel` / `saleExplainer`) and names the buy-variant simplifications.

## Build phases (each ships green; correctness spine first)

### Phase 1 — Threshold-finder backend (headless, on top of Phase 0)
Wrap the `SweepEngine` in an app-layer service + a **queued job** (mirror `RunScenarioSimulation`: progress,
cancel, terminal status). Persist a `ThresholdResult` keyed by inputs hash with seed/paths/grid/engine-version;
invalidate on scenario edit exactly as runs are invalidated. Expose the curve + crossing band + the pinned
context + the optimism caveats.

**Progress (2026-07-04): the COMPUTE CORE is BUILT** — `App\DecisionSupport\LeverThresholdService` (+ `LeverKey`,
`ThresholdOutcome`) resolves a scenario's household/settings/assumptions through the single `ScenarioForecaster`
(so a threshold rests on the same inputs the results page forecasts), builds the lever (`LeverKey` registry →
the engine levers), sweeps it and finds the crossing, on a **fixed seed** with **default per-lever grids** that
bracket the scenario's figures; `SweepEngine::sweep` gained an `onProgress(done, total)` hook. Feature-tested
(a real scenario computes a retirement-age threshold with per-point progress; reproducible; grids bracket).
**Phase 1 is now COMPLETE (2026-07-05).** The queued backend was built mirroring the `SimulationRun` triad
(DECISIONS 2026-07-05): a single `ThresholdResult` model + migration that is both the run (lifecycle + live
progress + cancel, reusing `SimulationStatus`) and the store (the mapped `ThresholdOutcome` = curve + crossing in
the encrypted `payload`); `ThresholdOutcomeMapper` (float lever-space, not pence); `ThresholdRunner` (mirrors
`SimulationRunner` — createRun / request-or-**cache-hit** / execute-with-progress-and-cancel) + `RunLeverThreshold`
job (mirrors `RunScenarioSimulation`). **Two-layer staleness:** primary delete-on-edit (`ScenarioBuilder` deletes
`thresholdResults()` like runs, cascading to children) + belt-and-braces **inputs hash** (sha256 of the effective
builder-state + engine version + lever/metric/target/grid/paths/seed) so a re-request is a cache hit and a stale
figure never surfaces. **Provenance** frozen per record (seed/paths/grid/engine+tax-year versions/assumption
snapshot). **CSV export**: an owner-scoped route + `ThresholdCsvExporter` carrying the shared
`App\Export\ExportDisclaimer` (extracted from `ScenarioResults` — one home for the guidance wording), the crossing
verdict (a band, S3) and the full grid. Default 2,000 paths/point (`ThresholdRunner::DEFAULT_PATHS`); the
preview→confirm path ladder is a Phase-2 concern. Tested: provenance + queue dispatch; sweep-to-done with a curve;
job handle; cache-hit / cache-miss; cancel-before-start; dead-worker → Failed; edit invalidates (base + children);
mapper round-trip; CSV disclaimer + owner-scoping. **Next: Phase 2 UI.**
- **Done when (met):** a scenario computes a threshold via the queue with live progress; re-running with identical
  inputs is a cache hit; editing an input invalidates it; the CSV carries the disclaimer.

### Phase 2 — The simple view (non-numbers decision-maker)
On the results page, a **"How far can we go?"** panel: the lever slider drives a **live net-position line redraw**
(reuse `SimulationResult::netPositionFanChart` / `ResultPresenter::fan` — median line + red "money runs out"
floor, bands hidden in a **new simple-mode `fan()` flag**), with a **green→red meter track** under the slider
(safe zone up to the crossing) and a plain caption. Live redraw uses the cheap **deterministic** line (instant);
the green edge + caption come from the Phase-1 job on release (debounced, `aria-live`). Headline above it: the
**word verdict** (`runOutVerdict` — Very likely / Borderline / Unlikely) + a **10-dot natural-frequency
pictograph** ("~9 of 10 futures your money lasts", year-first — "runs short around 2045", never "88%"). The
success-probability **S-curve** (break-even marker + 90/95% target line) lives in a "Show the full sweep"
disclosure with its grid table + CSV.
- **Guardrails:** never the word "safe" in neutral copy; never a bare percentage headline; never colour-alone
  (meter + chips carry icon + text); recolour the death vertical so shortfall-red vs death-red don't collide.
- **Done when:** a non-numbers user can drag buy-price and watch the line dive under the floor + the meter turn
  red past the ceiling; the analyst can open the curve + numbers. **Tests:** simple-mode fan hides bands; meter
  boundary matches the Phase-1 crossing; `aria-live` + table update on redraw.

**Phase 2 is now COMPLETE (2026-07-05).** Built as a nested Livewire component `App\Livewire\ThresholdExplorer` on
the results page (`sec-how-far`), with `App\DecisionSupport\ThresholdPresenter` for the view models (DECISIONS
2026-07-05). The live redraw is a new transient deterministic entry point `LeverThresholdService::deterministicForecastAt`
(lever+value → one `DeterministicForecaster` run, no MC/persistence), explicitly labelled "a central estimate, not the
range" — because the plan's live-preview was retired, this is the transient entry point Phase 0's discipline note said
did not exist. The net-position line reuses `ResultPresenter::burndown` (one usable-wealth definition, shared with the
ladder/Compare), the meter reads the Phase-1 crossing, the analyst S-curve + grid + the Phase-1 CSV route sit in a
disclosure, and a 10-dot pictograph headlines the current plan's MC odds. Guardrails enforced + tested (no "safe"
wording; dots + year, never a bare % headline; icon+text chips; death vertical recoloured off shortfall-red). Levers
offered are gated to the scenario (buy price only when a buy is configured, retirement age only when someone works).
**Deferred to a fast-follow / later phase:** starting the slider at the scenario's *actual* value rather than the grid
mid-point; the simple-mode MC fan (median + floor) as an alternative to the deterministic line. **Next: Phase 3.**

### Phase 3 — Combination *comparison* (not ranking)
A panel comparing a chosen set of what-if children (or a bounded generated set) as **word-band chips** +
net-position **sparklines**, **no decimals** (bucket to words — 94.9 vs 95.0 is MC noise). **Unordered in
guidance mode**; best-first ordering and any "best/strongest" label behind the `interpret` gate (like
`Interpretation::compareNarrative`). Plain-English **surprising-lever callout** (for V2: living longer *raises*
success). Do not mix probabilistic rows into Compare's deterministic Yes/No grid — this is its own surface.
- **Done when:** the comparison shows chips + sparklines, unordered; ordering appears only in advice mode.
  **Tests:** a test asserts **neutral order when `compliance.personal_use = false`**; no banned phrasing; the
  analyst drill-down still lists success% / depletion / p10 / median wealth per option with CSV.

### Phase 4 — Survivor-first lever menu + the survivor-cliff story
Re-scope the lever menu around the binding risk. Add the survivor-targeted levers, several already built:
**joint-life annuity with a survivor %** (`AnnuityPurchase`), **defer the *survivor's* State Pension** (deferring
the first-dier's is wasted — whose-SP-to-defer is the insight), **DB survivor fraction** (`spousePensionFraction`),
**care on/off pinned** (an off-by-default six-figure tail otherwise inflates every ceiling), and **per-person
longevity** (the current slider bumps both, conflating "who dies first" with "how long the survivor lives").
Add the **survivor-cliff dumbbell** (income-vs-essentials before/after the first death) — and **fix
`ResultPresenter::incomeFloor()`**, which currently snapshots the last *all-alive* year (i.e. *before* the cliff)
and so understates this exact risk: add a **survivor-year twin** off `deathCalendarYears` + per-year
`incomeBySource`.
- **Done when:** each survivor lever sweeps and moves the curve; the cliff dumbbell reads the survivor-year floor.
  **Tests:** deferring the survivor's SP raises the floor while deferring the first-dier's does not (completeness);
  care-on lowers the ceiling; the incomeFloor survivor-year twin reconciles to the ladder's survivor rows.

### Phase 5 — The 2-D frontier (the flagship visual)
Render the parametric threshold (S2) as a small **family of curves** or a **success heatmap with the 95% iso-line**
for buy-price × retirement-age (the headline pair), each cell an MC success on the pinned seed. Simple-view
summary: "safe up to £260k if YCC retires at 67; up to £300k if she works to ~70." Analyst view: the full grid +
table + CSV.
- **Done when:** the frontier reproduces the V2 pair; the simple summary pins both levers. **Tests:** the
  iso-line matches the per-column 1-D crossings from Phase 1.

### Phase 6 — Assistant tie-in (grounded, staleness-aware)
Extend `ScenarioContext` with the computed threshold/curve as `AssistantFact`s **only when the inputs hash
matches** (so `FigureGrounding` G1 can't restate a stale figure). The assistant *states* the threshold in plain
English and volunteers the survivor-cliff fact; it never calculates. Neutral phrasing routes through the G2
partition (`OutputPhrasing`); "safe/optimal/should" stay banned in the auto-stated threshold.
- **Done when:** the assistant answers "how much can we spend on a house?" with the grounded band, and refuses
  after an input edit until recomputed. **Tests:** G1 refuses an ungrounded/stale threshold; G2 blocks directive
  phrasing in guidance mode.

### Deferred — family / board contribution as a lever (needs a modelling home)
`IncomeStream` is fixed-`startAge`; the child money is meant to start *when the first partner dies* (a *sampled*
year), which no stream can trigger, and a tax-free stream keeps **full** Pension Credit **plus** the money (a
double-count the reconciliation rule exists to catch). **Do not ship this lever until** it is a survivor-onset
(first-death-triggered) tax-free stream in `PathProjector` with a *tested* PC treatment (Open Question 2) and the
resident-contributor entitlement reductions (council-tax single-person discount, PC SDP) it should trigger.
**Tests:** completeness (the money reaches the survivor from the year after the first death) + reconciliation
(PC + contribution don't both count).

## Reuse-vs-build map
| Asset | Status |
|---|---|
| Net-position line + £0 floor + red shortfall shading | **Reuse** — `SimulationResult::netPositionFanChart`, `ResultPresenter::fan`/`burndown`/`belowZeroBand` |
| Sign-aware £ axis, age-per-year axis, table fallback | **Reuse** — `resources/js/charts.js` |
| Plain-English verdict + green/amber/red banding | **Reuse** — `ResultPresenter::runOutVerdict` |
| Death / retirement / SP milestone verticals | **Reuse** — `ResultPresenter::milestones` / `milestoneAnnotations` |
| Lever sliders + transient deterministic forecast | **Reuse** — `ScenarioResults::applySliders` / `sliderForecast` |
| Compare overlay + per-plan rows + gated "why" narrative | **Reuse** — `ScenarioCompare`, `Interpretation::compareNarrative` |
| Assistant fact pipeline + G1/G2 | **Reuse** — `ScenarioContext`, `FigureGrounding`, `OutputPhrasing` |
| Framework-free `SweepEngine` (MC bracketing, frontier, crossing semantics) | **Build** — Phase 0, engine package |
| Queued sweep job + `ThresholdResult` store + invalidation | **Build** — Phase 1 |
| Simple-mode `fan()` (median + floor only) | **Build** — Phase 2 |
| 10-dot natural-frequency pictograph | **Build** — Phase 2 (also in the delta-research backlog) |
| Green→red "how far can we go" meter | **Build** — Phase 2 |
| Comparison word-chip list + sparklines, gated ordering | **Build** — Phase 3 |
| Survivor-year `incomeFloor` twin + survivor-cliff dumbbell | **Build** — Phase 4 |
| 2-D frontier / heatmap w/ iso-line | **Build** — Phase 5 |
| Threshold facts into the assistant | **Build** — Phase 6 |

## Risks / watch-items
- **S1 regression** is the highest risk — any future "just use the deterministic forecaster, it's faster" change
  silently reintroduces the optimistic bias. The Phase-0 test that pins deterministic-vs-MC divergence is the guard;
  keep it.
- **MC cost.** Frontier × combinations × paths blows up; lean on CRN + bounded generators + the preview→confirm
  path-count ladder, and keep everything queued. Re-measure wall-clock against a real grid before widening it.
- **Compliance drift.** The ordering-is-advice gap only bites when `personal_use` flips false; the Phase-3
  neutral-order test is the guard, and any "best/safe/optimal" wording must stay behind the gate.
- **Provenance vs freshness.** The assistant will confidently state a stale threshold unless Phase-1 invalidation +
  the Phase-6 hash-match are both in place; don't ship Phase 6 without Phase 1.
