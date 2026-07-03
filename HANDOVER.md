# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue building the calculation engine and then the app around it. Read `docs/PLAN.md` first: it is the full approved plan and the source of truth for scope.

**Stage:** active
**Status:** Phase D go-live, **feature-complete for personal use**; the adviser-legibility workstream and the whole **post-v1 enhancement backlog are built** (annuitisation, historical stress-test, ONS mortality-refresh guardrail, care-cost risk — plus **Lane B forced-housing now complete** (in-place forced sale built 2026-07-03) + Lane C withdrawal-sequencing *core*, #5/#6 handed off). The tool runs in **personal-use advice mode** (`config('compliance.personal_use')` = the flagged regulatory line — set false before any public release). What remains is Rob's **browser verification / sign-off**, the **public-release blockers**, and **optional refinements** — see What's next + Current state.
_Last updated: 2026-07-03 (session arc: in-place forced sale → mortgage-maturity made a **user input** → **buy-with-a-
mortgage** (`HousingAction::buyMortgageRate` funds a buy-cheaper shortfall with an interest-only RIO) → a contextual
**"Check these figures & get help"** sources/contacts panel (results / Compare / PDF). Plus the real **V2 couple's DB
scenarios rebuilt** (Stay-put base + 5 named what-ifs, CGT entered, buy-cheaper on a £165k RIO). A later doc-only
session then specced an in-app local-model assistant ("chatbot" — explains + captures, never builds) and
**built + refined its Phase 1** (a grounded, local scenario-explainer that interrogates the year-by-year
figures, Monte Carlo probabilities, the lump-sum tax shock and the sale waterfall, as a side panel;
docs/RESEARCH-local-assistant.md), then its **Phase 2** (methodology doc-RAG on local embeddings over a
**curated** methodology corpus, hand-rolled cosine, `assistant:index-docs`; live verification forced the
curation — see DECISIONS/LA-9).
See Session log + DECISIONS 2026-07-03.)_

## Multi-agent coordination (lanes CLOSED 2026-07-02 — single-session tree)
The concurrent A/B/C/D lanes are **closed**; this is a single-session tree again. The full per-lane build record
lives in `git log` + DECISIONS.md (2026-06-30 / 07-01) — not restated here. High level: **Lane A** post-v1 backlog
(annuitisation, historical stress-test, ONS mortality-refresh, care-cost stochasticity) — complete; **Lane B**
forced-housing workstream (means-tested benefits, mortgage-redemption + payment-stop, feasibility flags, input
clarity, **in-place forced sale**) — **complete** (the forced sale built 2026-07-03,
[docs/PLAN-in-place-forced-sale.md](docs/PLAN-in-place-forced-sale.md)); **Lane C** withdrawal sequencing — core
shipped, #5/#6 handed off ([docs/PLAN-withdrawal-sequencing.md](docs/PLAN-withdrawal-sequencing.md)); **Lane D**
multi-property — docs-only DRAFT awaiting Rob ([docs/PLAN-multi-property.md](docs/PLAN-multi-property.md); note it was
mis-homed inside commit `5a6688f`, content intact). If lanes ever reopen, honour [[concurrent-session-split]]
(re-check `git` first, commit only your files, never push without Rob's go-ahead). `PathProjector` was the
cross-lane contention point.

## Goal & success criteria
Full plan: [docs/PLAN.md](docs/PLAN.md); PRD: [PRD.md](PRD.md). Summary:

- **Goal:** let an older couple (one working, one retired) model whether to sell their home and either buy somewhere cheaper outright (invest the surplus) or sell and rent (invest all proceeds), and see the consequences of pension lump-sum withdrawals and whether their money lasts for life.
- **Headline outputs:** (1) the pension lump-sum tax shock (25% tax-free, marginal tax on the rest, plus the Month-1 emergency-tax overpayment and reclaim), and (2) running-out-of-money / longevity risk via Monte Carlo.
- **Success for Rob's own use:** a working **local** site where he can enter a real (known) couple himself, run buy-vs-rent, and read a trustworthy forecast. **No hardcoded client data in the repo.** If it proves useful he may later release it publicly for free.
- **Correctness bar:** the engine reproduces known HMRC worked examples to the penny (examples A, B, C in docs/PLAN.md). **Met** for the deterministic engine.

## Canonical data shape
The single source of truth for the domain shape is the engine's readonly DTOs under `packages/finance-engine/src/Dto/` (Eloquent models and Livewire forms map to/from these). See [DATA-MODEL.md](DATA-MODEL.md) and docs/PLAN.md for the full field lists. Conventions, honoured by all existing code:

- **Money = integer pence**, never a float. Held by `Money` value object (GBP only). Rates = `Percent` (integer basis points). Dates = ISO `Y-m-d`. **Ages are derived from DOB + a reference date, never stored.**
- Entities — all coded as readonly DTOs under `src/Dto/` and persisted as encrypted payloads: Household, Person, Pension (subtype dc|db|state), Property, Account (isa|gia|cash|premium_bonds), IncomeStream, ExpenseProfile, Scenario (variant buy_outright|rent|stay_put), AssumptionSet, SimulationRun, Result. Sensitive money/DOB/salary/pot/balance fields are encrypted at rest.
- **Storage inversion (Phase B):** a scenario stores the raw builder **form-state** (`builder_state`, one `encrypted:array`) as the single source of truth; the engine `Household` + `HousingAction` DTOs are **derived** from it (`Scenario::toHousehold()`/`toHousingAction()` via `HouseholdAssembler`, no reverse-mapper). A what-if **child** (Phase C2) holds no `builder_state` — only `parent_scenario_id` + a sparse encrypted `overrides` delta; `effectiveBuilderState()` = base ⊕ overrides via `App\Forecast\BuilderStateDelta`. The delta carries **value overrides, added rows (stored whole at their id path) and removed rows (a `REMOVED` sentinel)**, so a what-if can add/remove items, not only change values (DECISIONS 2026-06-30). Clear columns are a projection. (The pre-rebuild `households`/`scenario_drafts` tables were dropped.)

## Architecture / stack
- **Laravel 13.17** app at the repo root (SQLite locally). **Fortify** (auth) and **Filament 5** admin (which pulled **Livewire 4** — a bump from the plan's stated Livewire 3). Fortify views are on; `FortifyServiceProvider` points each view route at a Blade screen (incl. the 2FA challenge + password-confirmation).
- **Front end:** hand-rolled **Livewire 4** full-page components (`app/Livewire/`) rendering into one Blade layout component via `#[Layout('components.layouts.app')]` — **not** Livewire 4's default `layouts::app` (only a component namespace here, so no view hint path). **ApexCharts** bundled via npm (`resources/js/app.js` + `charts.js`, an Alpine `chart` wrapper, reduced-motion aware, try/catch fallback to the table). `public/build` is gitignored, so the base `TestCase` calls `withoutVite()`.
- **`packages/finance-engine`**: a framework-free Composer **path package** (`retireforecast/finance-engine`, symlinked, required `"*"`). Zero Laravel dependencies, no I/O, no clock. This is the product; the Laravel app is a shell. The engine must never `use App\...` or `Illuminate\...` (guarded by `EngineIsolationTest`).
- Money is **hand-rolled integer pence** (brick/money dropped over a dependency clash). PHPUnit 12. **`phpoffice/phpspreadsheet`** is an **app-layer** dependency (for `.xlsx` import only); the engine stays dependency-free. A CSP + hardening headers ship on the `web` group (`config/security.php`); Filament `/admin` is out of scope by design.

## Key files / structure
The engine is the product; the Laravel app is a shell around it. The full tree is not mirrored here (it would drift) — browse it.

- `packages/finance-engine/src/` — framework-free engine: `Money/`, `TaxYear/` (the per-year config spine), `Tax/`, `Pension/`, `StatePension/`, `Property/`, `Benefits/`, `Iht/`, `Care/`, `Dto/` (canonical shapes), `Assumptions/`, `Benchmark/` (PLSA RLS), `Mortality/` (ONS cohort), `Forecast/` (`PathProjector` + `DeterministicForecaster` + `YearResult`), `MonteCarlo/`, `Housing/`. Namespace `RetireForecast\FinanceEngine\…`; tests are pure PHPUnit (the `Engine` testsuite).
- `app/` — `Forecast/` (`HouseholdAssembler` form-state→DTOs, `BuilderStateDelta`, `ScenarioForecaster`, `SimulationRunner`, `ResultPresenter`, `LumpSumTaxShock`, `AssumptionComparison`), `Import/` (CSV/xlsx `Spreadsheet` + profiles), `Livewire/` (`Dashboard`, `ScenarioBuilder`, `ScenarioResults`, `ScenarioCompare`, `AccountSecurity`), `Compliance/` (`OutputPhrasing` banned-phrase lint + walled-off `Interpretation`), `Finance/Mapping/`, `Models/`, `Http/` (controllers incl. `ScenarioPdfController`; `SecurityHeaders` + `EnsureDisclaimerAcknowledged` middleware), `Filament/`, `Demo/`, `Gdpr/`, `Jobs/`.
- Root: `composer.json` (path repo for the engine), `phpunit.xml` (defines the `Engine` testsuite), `config/security.php` (CSP + toggles), `database/migrations/` (scenarios holds `builder_state` + `parent_scenario_id`/`overrides`; no households/scenario_drafts), `database/seeders/DemoScenarioSeeder.php` (opt-in fictional sample).
- House style is Pint: `vendor/bin/pint --dirty` (app) / `vendor/bin/pint packages/finance-engine` (engine).

## Decisions locked
See [DECISIONS.md](DECISIONS.md) for the full append-only log + rationale. The load-bearing "don't relitigate" anchors:
- **Local-first, personal use, no hardcoded client data.** Rob enters the couple via the UI; any first-run sample must be obviously fictional. Possible free public release later, so do not design accounts out.
- **Modelling depth:** HMRC-accurate deterministic engine PLUS Monte Carlo with **stochastic joint-life mortality**. Pensions DC/DB/State; housing buy-vs-rent on identical seeds; IHT a toggle (incl. pensions entering the estate from April 2027). Assumptions are a sourced, runtime/display choice (FCA default), not baked in.
- **Regulatory posture: education/guidance only** is the **public** stance (never a personal recommendation; `BannedPhrasingTest` partition lint; signpost Pension Wise / MoneyHelper). **Currently relaxed for personal use:** this is a private tool, so **`config('compliance.personal_use')` (default true) is the flagged "regulatory line"** — it turns the walled-off advice-style `interpret` capability ON for everyone (no admin grant), so the app gives direct advice (e.g. the buy-vs-rent "why" narrative). **Set it false before any public release** and the guidance-only partition (lint + per-user `can_interpret` grant) re-applies; the suite runs with it false so the guard stays tested. See DECISIONS 2026-06-30.
- **Engine is framework-free** in a path package; **money = integer pence**; **savings + dividends in one combined income-tax pass**; **tax figures versioned per tax year with source + verified-on** (freeze to April 2031; dividend rates rise in 2026/27).
- **UI = hand-rolled Livewire 4** (Filament admin-only); form input → engine DTOs via a standalone, unit-tested `HouseholdAssembler`; charts are a progressive enhancement (every figure also text + accessible `<table>` + CSV); the region guard asks the engine's `TaxYearRegistry` (Scotland refused until its bands land).

## Current state
- **Done — deterministic engine:** money layer; per-year `TaxYearConfig`/`TaxYearRegistry` (2025-26 + 2026-27, England/Wales/NI; Scotland throws); income tax (combined savings/dividend stacking) + NI; pension lump-sum suite (PCLS/UFPLS/drawdown, Month-1 emergency tax + P55/P50Z/P53Z, MPAA, AA + taper) — **worked examples A & B**; State Pension (SPA-from-DOB, deferral, triple lock); SDLT (+surcharge) + CGT (PRR); benefits capital tariff + £16k cliff — **worked example C**; IHT (pensions-in-estate toggle) + care means-test. Plus DTOs, `AssumptionSetLibrary` (3 sourced sets), ONS cohort mortality, `Forecast/` (`PathProjector` + deterministic + Monte Carlo), `Housing` buy-vs-rent on identical seeds. **A5** (GIA/cash income tax + CGT-on-disposal) complete.
- **Done — app layer:** encrypted DTO persistence + Fortify auth + GDPR export/erase + Filament admin; forecast/scenario services (`ScenarioForecaster`, `SimulationRunner` + queued `RunScenarioSimulation` with progress + cancel); Livewire UI + ApexCharts (auth screens, builder wizard, results page, Compare); compliance layer (partition lint, first-run disclaimer gate, walled-off interpretation toggle); spreadsheet import (CSV/`.xlsx`, calibrated profiles); lump-sum tax-shock panel; compare-assumptions overlay.
- **Done — rebuild:** Phase A (engine enrichments: ongoing contributions, longevity, usable-vs-total wealth, income-by-source), C3 (results usable-vs-total + cashflow ladder), B (`builder_state` storage inversion + edit-in-place + stale-run invalidation), C2 (delta-child what-ifs + Compare), C1 (3-tier line-item budget, core + fast-follow), C4 (PLSA Retirement Living Standards benchmark).
- **Done — Phase D Tier-1 (trust), COMPLETE:** A5; the gov.uk ⚠️ figure-verification pass (every statutory figure re-confirmed + stamped `verified_on: 2026-06-27`, no value changed, pensions-in-IHT now enacted); admin-panel lockdown (`is_admin`); forecast-boundary reconciliation invariants; displayed-figure provenance; the user-facing import reconciliation panel.
- **Done — Phase D Tier-2 (go-live polish), BUILD COMPLETE:** demo preset/seeder; 10k-path Monte Carlo perf (lean `IncomeTaxCalculator::totalPence()` + worker JIT); CSP + security headers; 2FA enrolment UI; PDF results export (dompdf, reuses `ResultPresenter`); a11y CI scaffold + a first local sweep (3 contrast fixes); queued-run "waiting for a worker" hint (`SimulationRun::isAwaitingWorker()` → neutral on-screen note when a run sits queued at 0% past a 15s grace window).
- **Done — adviser-legibility *presentation* layer (2026-06-29, pending Rob's browser sign-off):** on the results page — the **house-sale waterfall** (`ResultPresenter::saleExplainer` + a new reconciled engine value object `HousingPurchase`), the **assumptions panel** (`assumptionsPanel`, real-vs-nominal labelled, engine's own blended return), **itemised per-year spend** (essential/discretionary in the cashflow ladder), **life-event milestones** (`milestones` + a new single-source engine field `ForecastResult::deathCalendarYears`), and **input-sanity notes** (`inputNotes`). Each carries a reconciliation/labelling guard; all education/guidance-only (the banned-phrasing lint).
- **Done — #1 contingent-cost correctness fix (2026-06-29, option b):** an expense line carries an auto-classified **condition** (mortgage / service charge → *while owning the home*; commute → *while working*; explicit override honoured first). `ExpenseProfile` gains `propertyCosts` / `employmentCosts` markers (a subset of essential, no drift); the **sell variants build with `withoutPropertyCosts()`** (no phantom mortgage on a sold home) and **`PathProjector` drops the commute when no one earns** (it stops at retirement, every variant); `HousingComparison::variantInputs()` is the new single source of the three variant households (the Monte Carlo runs it; the #6 ladder will too); PLSA now excludes property costs on its outright-ownership basis. Reconciliation-tested (property costs only in stay-put; commute falls by its full amount at retirement; auto-classify + override + PLSA-exclusion). **The per-line override UI is still to build** (auto-classification gives the defaults today). The **everything-editable / buy-vs-rent direction** is still to build — see What's next.
- **Done — #6 per-variant deterministic cashflow ladder (2026-06-29):** `ScenarioForecaster::deterministicVariants()` runs each housing strategy through `DeterministicForecaster` on the variant household + settings from `HousingComparison::variantInputs()` (the single source the Monte Carlo comparison also runs, so they can't drift; `stay_put` == the old `deterministic()`). The results page gained a **strategy selector** (`ScenarioResults::$ladderVariant`, default = the scenario's own variant) driving the cashflow ladder + its milestones; the **house-sale milestone** now lands (year 0, household-level, no per-person age) for a sell strategy; the **PDF** ladder follows the scenario variant too. Provenance (panel == CSV == PDF) holds on the *selected* variant; income-floor / input-sanity notes deliberately stay on the raw (stay-put) projection. Reconciliation-tested (sell variant owns no home → usable == total, equity freed into liquid wealth; buy keeps a smaller home).
- **Done — the builder highlights a what-if's changed inputs + shows the base value (2026-06-30):** when editing a what-if, each input whose value differs from the base is **ringed in amber and shows the base value it diverged from** ("was £18,000"), plus a one-line "fields you change from the base are highlighted" banner. `ScenarioBuilder::changedFromBase()` computes a `path => formatted base value` map (a **positional** diff of the live `builderState()` vs the base's `effectiveBuilderState()`, **index-based** to match each input's `wire:model`; values formatted by the shared `WhatIfChanges::formatValue()`), renders it on the form (`data-builder-diff` + a `data-changes` object), and a bundled, CSP-safe, morph-aware `resources/js/builder-diff.js` rings each matching input (`.builder-diff-changed`) and shows its base value via the field wrapper's `::after` (`.builder-diff-field[data-original]` — **not an injected node**, so morph-safe). One server map + one script covers all ~70 inputs uniformly. Pure progressive enhancement. Livewire-tested (childMode maps the changed path to its base value + renders the hook; a base does neither). **Pending Rob's browser sign-off.**
- **Done — one-click "quick what-ifs" (2026-06-29):** preset buttons **"Retire 2 years later"** and **"Live 10 years longer"** on the base's results page and each dashboard base row. Each POSTs to `QuickWhatIfController`, which uses `App\Forecast\QuickWhatIf` to edit the base's people (retire-later bumps each *working* person's `plannedRetirementAge` +2, clamped 50–80; live-longer moves each person onto a +10-year `offset_years` longevity lever relative to the base) and stores the result as an **ordinary delta-child via `BuilderStateDelta::diff`** (minimal, structurally identical — so it highlights/compares/edits like any hand-built what-if), then opens it. A preset that would change nothing builds nothing (no empty what-if); repeats get distinct names; owner-scoped. First UI use of the per-person longevity lever. Service + endpoint tested. **Pending Rob's browser sign-off.**
- **Done — what-ifs highlight what they changed from the base (2026-06-29):** a delta-child what-if now surfaces its `overrides` as readable changes in three places — a **"What this what-if changes" panel** at the top of its results page (each change as **base → new**, plus a "what-if of <base>" header line + orphaned-override notice), compact **change tags** on each what-if row in the **dashboard**, and per-plan **change chips** in **Compare**. One presenter `App\Forecast\WhatIfChanges` turns the sparse override map into `{label, from, to}`: the base value an override replaced is read back via the new **`BuilderStateDelta::valueAt()`** (read mirror of `setPath`, row-id aware), each dot-path humanised (list rows named by their own label/identity, e.g. "Essentials · amount", "DC pension · current value"), money/rate/enum formatted, meta fields (name/step) excluded. A pure projection of the existing delta (no new store). Unit + Livewire tested. **Pending Rob's browser sign-off.**
- **Done — editable-assumptions layer, *core* (2026-06-29):** the six economic assumptions surfaced by the read-only panel (investment growth blended-real, CPI, house/rent/salary growth, income yield) are now **editable on builder step 1**, defaulting to the chosen preset (placeholder + named in the hint) and deriving a **custom set**. Stored as a **sparse `assumptionOverrides` delta** in `builder_state` (only changed figures; empty keeps the preset, so a re-source flows through; composes with delta-child what-ifs via `BuilderStateDelta`). Engine `AssumptionSet::with*` (immutable; `withRealReturnShift` lands the blended-real return on the target without diverging the Monte Carlo) + `App\Forecast\AssumptionOverrides::apply()`, applied in the **single** resolution point `ScenarioForecaster::assumptions()` (so deterministic + variants ladder + Monte Carlo + the frozen run snapshot all share it). Results panel labels a tuned set **(customised)** and marks each **user-set** figure. Reconciliation-tested (no overrides == the preset; an edit demonstrably reaches the forecast; the blend lands on target under any allocation). **Pending Rob's browser sign-off.**
- **Done — results-page "on this page" side nav (2026-06-29, browser-verified desktop):** a sticky 2-col grid nav on `lg+` (hidden on mobile), listing only the sections present this render (built from the same flags they render under) as real anchor links that work without JS, with a CSP-safe `IntersectionObserver` scroll-spy (`resources/js/toc.js`). **Mobile check deferred** by Rob to later in the dev timeline.
- **Done — editable-assumptions layer UI (2026-06-30, pending Rob's browser sign-off):** (a) a sticky **live in-builder preview** running one cheap deterministic forecast on a transient scenario from the current form-state (never saved), recomputed each round-trip, headlining the does-the-money-last **verdict** + **spendable/total wealth at end**, inviting completion while the inputs are too incomplete to forecast (single-sourced from `ScenarioForecaster::deterministic()`, server-rendered, CSP-safe); (b) the **modelled age at death** shown beside each person's lifespan lever (from `ForecastResult::deathCalendarYears`, same forecast), so "peer/+10 years" resolves to a visible age; (c) **selling costs decomposed into per-component %/£ lines** — engine `SellingCostComponent` (`Percent|Money`) summed in `saleProceeds` with a reconciled `HousingProceeds` breakdown; default 2% preserved when none; legacy `sellingCostRate` back-compat (total preserved); builder edits three default components (agent 1.25%, legal £1,500, EPC & removals £800) each with a %/£ toggle; (d) the **per-line cost-condition override** ("Applies": Auto / Always / while-owning / while-working) completing option (b), with an Auto hint from `HouseholdAssembler::autoCondition()`; (e) the **per-line include/exclude toggle** — an "Include this cost" checkbox switches a line off (kept but counts £0, row dimmed, live preview moves); the assembler drops excluded lines once in `household()` so every total excludes them; the flag is stored sparsely (only when off) so it records no spurious what-if delta.
- **Done — buy-vs-rent compare + personal-use advice mode (2026-06-30):** a one-click **"Compare buy vs rent"** button (`BuyVsRentCompare` + `BuyVsRentController`) generates the meaningful alternative strategies as variant-only delta-child what-ifs and opens **Compare**, which now projects **each plan on its own variant** (`deterministicVariants[plan.variant]`). The regulatory line is flagged at `config('compliance.personal_use')` (default true): in this mode the `interpret` Gate is on for everyone and the walled-off `Interpretation` layer gives direct advice — the buy-vs-rent **"why" narrative** (`Interpretation::compareNarrative`, ranks the plans). The suite runs with the flag **false** (public posture) so the guidance-only partition stays tested. See DECISIONS 2026-06-30.
- **Done — what-ifs can add/remove items (2026-06-30):** the delta represents structural changes — an **added** row stored whole at its id path, a **removed** row as a `BuilderStateDelta::REMOVED` sentinel; `merge`/`setPath` append adds and splice removals, while a *leaf* override to a base-deleted row stays a flagged orphan. The "a what-if only changes values" refusal is gone; `WhatIfChanges` shows an add/remove as one line; the builder highlight pairs base rows by id. See DECISIONS 2026-06-30.
- **Done — partial-PRR CGT on selling a let former home (2026-06-30):** occupation-driven (not mortgage type, gov.uk HS283) — a `CgtHistory` on the engine `Property` (null = full PRR / £0, the common case), `CgtPrivateResidenceCalculator` extended for **joint owners** (two allowances), wired into `HousingComparison::saleProceeds` (gain = sale − purchase − improvements − selling costs). A **"Capital gains on sale" wizard** under the home (revealed when ever-let) captures purchase price / year / costs / joint + higher-rate toggles / a lived-in-vs-let **period timeline**, with a live readout; the sale waterfall shows the working. See DECISIONS 2026-06-30.
- **Done — longevity distribution (2026-06-30, first post-v1 backlog item):** the Monte Carlo now surfaces a `LongevityDistribution` on `SimulationResult` (read off the same joint-life sampler the wealth paths run) — last-survivor age p10/p50/p90, the planning horizon in years (p50 + p90), and P(at least one of you reaches 95 / 100). Shown as a neutral **"How long the money may need to last"** results-page panel (+ on-this-page nav). Nullable + mapper back-compat (old runs rehydrate as null). Engine + mapper tested. See DECISIONS 2026-06-30. **Pending Rob's browser sign-off (needs a completed run).**
- **Done — source-freshness guardrail (2026-06-30):** a `figures:freshness` command (pure, unit-tested `App\Finance\FigureFreshness`) reports each supported tax year's gov.uk `verifiedOn` and flags any older than `--months` (default 12), exiting non-zero so CI/a periodic run catches aging statutory figures. `TaxYearRegistry::SUPPORTED_TAX_YEARS` is the single source of the year set. A command (not a date-dependent phpunit test). See DECISIONS 2026-06-30.
- **Done — per-year surplus/shortfall + configurable safety floor (2026-06-30):** the cashflow ladder classifies each year **surplus / drawing / shortfall** on usable money and flags years usable funds fall below a **safety buffer** (default 2 months of essentials, set in the Spending step, read via `Scenario::safetyBufferMonths()` → `ResultPresenter::ladder`), with a headline (stays above / dips below in YYYY / runs out in YYYY) and status-tinted rows. Replaces the academic "neutral diagnostics" idea (Rob's reframe). See DECISIONS 2026-06-30.
- **Done — what-if sliders + retirement-year proration (2026-06-30):** an **"Explore the levers"** results-page panel (live sliders: retire ± yrs / spend ± % / return ± pts / live ± yrs) re-runs a throwaway deterministic forecast and shows the outcome — exploratory, never saved (`ScenarioResults::sliderForecast`/`applySliders`, transient scenario). And the engine now **prorates salary in the retirement year** (birth-month ÷ 12) instead of dropping the whole year (`PathProjector::workFraction`). See DECISIONS 2026-06-30.
- **Done — annuitisation (2026-07-01, Lane A):** a DC pot can buy a **lifetime annuity** with part of its value at a chosen age — the pot falls by the amount and pays **amount × rate** for life. `AnnuityPurchase` DTO on `DcPension` (level or RPI/CPI-escalating, single- or joint-life with a survivor %); `PathProjector` buys it once and pays escalating, survivor-aware income mapped to the existing `other_taxable` source (no `INCOME_SOURCES` change). The **rate is a user input** (builder default a sourced ~7.2%), so no fabricated age/rate table is baked in. Builder toggle "Buy an annuity with part of this pot", stored sparsely (no spurious what-if delta). Engine + assembler + builder round-trip tested. See DECISIONS 2026-07-01. **Pending Rob's browser sign-off.**
- **Done — historical stress test (2026-07-01, Lane A):** a **sequence-of-returns backtest** — replay each past year's real UK returns + inflation over the current plan (`HistoricalSequenceDraws`, a third `PathDraws`; `HistoricalBacktester` runs every eligible start year). A results-page panel (deterministic, pre-run) shows the % of ~140 historical starts survived, the worst start, and named crises (1929 / 1973–74 / 2000 / 2007–08). Data = the sourced `HistoricalReturns` class (UK real equity/bond/cash total returns + inflation 1871–2020) from the **Jordà–Schularick–Taylor Macrohistory** database — **CC BY-NC-SA, flagged in-code as a public-release blocker** (swap the source before any public launch). `RepresentativeDeathAge` extracted so forecast + backtest share the median-lifespan rule. See DECISIONS 2026-07-01 + docs/RESEARCH-stress-test-and-official-sources.md. **Pending Rob's browser sign-off.**
- **Done — ONS mortality refresh + integrity guardrail (2026-07-01, Lane A):** a **`mortality:refresh`** command (fully ONS) that (1) verifies the generated `OnsPeriodMortalityData` still matches its JSON source home cell-for-cell (5100 cells — closes the gap that nothing checked the hand-generated class against its source), (2) flags the ONS data stale past `--months` (default 24), and (3) with `--against <newRelease.json>` diffs a fresh ONS release and shows the cohort-life-expectancy impact. Non-zero exit on drift/staleness (CI signal). Pure grid work in `App\Finance\MortalityDataset` (unit-tested incl. the in-sync guard); reuses `FigureFreshness`. Ingests the ONS data as the JSON resource shape (xlsx auto-parse deferred). See DECISIONS 2026-07-01.
- **Done — care-cost stochasticity (2026-07-01, Lane A — last backlog item):** the Monte Carlo can model the fat-tail risk of late-life residential/nursing care. `CareCostSampler` draws a per-person end-of-life spell (Bernoulli ~1-in-4; exponential ~2.5yr duration; residential/nursing self-funder fee) from sourced `CareAssumptions` (Dilnot/PSSRU/LaingBuisson); wired via `PathDraws::careAnnualCost` (deterministic + historical return 0) → charged as essential spend in `PathProjector` → accumulated on `ForecastResult::careCostReal`; `Simulator` aggregates a `CareImpact` (share of paths + median/p90 bill) onto `SimulationResult`. **Opt-in** (`ForecastSettings::modelCareCost`, a sparse builder toggle — existing runs unchanged); the rent variant propagates it. Surfaced as a results **care-risk panel** (visible, not buried). v1 charges the gross self-funder cost (means-test refinement flagged). See DECISIONS 2026-07-01. **Pending Rob's browser sign-off.**
- **Done — in-place forced sale (2026-07-03, last Lane-B item):** a `MortgageMaturityAction::ForcedSale` home is now sold **in place at the redemption year** (an additive `PathProjector::projectYear` event), instead of the old no-op that kept the home for ever. It sells at the grown whole-property value via the shared **`HousingProceeds::compute`** (extracted so the year-0 variants and the in-projection sale are ONE reconciled definition), frees the net proceeds into the first living person's GIA (basis = proceeds), clears the home + debt (new state `homeSold` + `propertyWhole`), and from that year stops the mortgage payment + property/running costs and charges the entered rent. The freed equity is liquid, so Pension Credit erosion falls out for free. Rent + selling-cost basis ride on `ForecastSettings` (`annualRent`/`rentInflationReal`/new `sellingCosts`), populated by `ScenarioForecaster::settings()` **for a ForcedSale scenario only**. `ForcedSaleTest` pins wealth conserved across the sale to the penny + costs-stop-rent-starts + Pension-Credit erosion + let-vs-lived-in CGT; a `ScenarioForecasterTest` completeness test pins the rent/costs reaching the settings. v1 limits (flagged): partial-`ownershipShare` sale reconciles penny-exact only for whole ownership; Pension Credit sees the freed capital from the year after the sale. See DECISIONS 2026-07-03. **Browser-verified (works); the follow-up below made the mortgage-maturity treatment a user input.**
- **Done — mortgage-maturity is a user-modelable input (2026-07-03):** `mortgageMaturityAction` + `mortgageRedemptionYear` are now **editable in the builder** (property step) so refinance / repay-from-capital / forced-sale are each user-buildable scenarios (they were engine-consumed `Property` fields with no UI). The one-click **"Stay put"** plan (`BuyVsRentCompare`) now resets a `forced_sale` to `refinance` so it genuinely keeps the home instead of silently selling. **Migration:** a `forced_sale` base's *existing* stay-put child still inherits the forced sale — regenerate it. See DECISIONS 2026-07-03.
- **Done — local-model assistant, Phase 1 (grounded scenario-explainer) (2026-07-03):** an in-page "chatbot" (`App\Livewire\ScenarioAssistant` on the results page) that explains **this** forecast in plain English on a **local** model (Ollama). App-layer only, engine untouched. `App\Assistant\`: `OllamaChatClient` behind a `ChatClient` interface (local HTTP, fails loudly via `AssistantUnavailable`, strips `<think>`); `ScenarioContext` — the engine figure snapshot that is BOTH the model's context and the grounding allow-list, carrying the headline facts **plus the full year-by-year cashflow ladder** (per-year essentials / discretionary / income-by-source / tax / wealth, from the reconciled `ResultPresenter::ladder()` rows, inline-labelled), the **Monte Carlo probabilities/ranges** (chance the money lasts / runs out, wealth spread, longevity, care — when a completed run exists; else it says so), and the **lump-sum tax shock** (the flagship: 25% tax-free, marginal tax, Month-1 emergency over-deduction + reclaim — when planned), and the **home-sale waterfall** (what you pocket after mortgage / costs / CGT, then invest-and-rent or buy-cheaper — for a sell strategy), so the reader can **interrogate data the UI doesn't spell out**; `FigureGrounding` (**G1** — refuses any £/year/% figure not engine-derived), reuses `App\Compliance\OutputPhrasing` (**G2** — recommendation phrasing blocked in guidance-only mode, allowed in advice mode via the `interpret` gate); `AssistantService` orchestrates with one corrective retry then refuses rather than show a bad answer. **Inert by default** (`config('assistant.enabled')`, env `ASSISTANT_ENABLED`). 19 unit tests incl. the "never surfaces an invented figure" invariant; **end-to-end verified against the real `qwen3:14b`** (grounded answers pass; it declines to invent). v1 = synchronous call with a "Thinking…" state; it's a **docked full-height side panel** on the right edge (an edge-tab when closed, not a floating chat bubble), with a **Clear** (wipes the local transcript) and **grouped, clickable starter questions** — "Your plan" / "Risks worth checking (Pension Wise / adviser)" = the COBS 9.4.10G drawdown risk warnings in plain English / "Tax and the home" (sale starter shown only for a sell strategy) / "How the forecast is worked out" (Phase-2 methodology). streaming / pre-computed aggregates (the model won't sum across years — G1 refuses an ungrounded aggregate) / a side-nav entry are fast-follows. See DECISIONS 2026-07-03 + docs/RESEARCH-local-assistant.md. **Pending Rob's browser sign-off** (enable the flag, open a results page).
- **Done — local-model assistant, Phase 2 (methodology doc-RAG) (2026-07-03):** the "how does it model X?" questions, on local embeddings (`nomic-embed-text`). App-layer only. New `App\Assistant\`: `EmbeddingClient` + `OllamaEmbeddingClient` (local-only, fails loudly); `DocChunk`/`DocChunker` (heading-anchored, hard-word-capped so no chunk exceeds the embed model's context); `DocIndex` (**hand-rolled cosine search, no vector DB**); `MethodologyRetriever`. An **`assistant:index-docs`** command builds a **gitignored** JSON index under `storage/app/private/assistant/` (a derived artifact; a **missing index degrades gracefully** — methodology just isn't attached, scenario answers unaffected). **Not intent-routed:** each turn embeds the question, cosine-searches, and prompt-stuffs the top chunks above a threshold under a labelled METHODOLOGY section (the Livewire component does the I/O and threads it into `AssistantService::answer()`; the service stays pure/unit-testable). G1 grounding widens to context+methodology (a real methodology figure is groundable, an invented one still refused); the prompt forbids quoting a methodology figure as the reader's own (LA-6/LA-8). **The corpus is CURATED — the load-bearing call (LA-9), forced by live verification:** indexing all of `docs/` surfaced internal planning/build-record prose as "methodology" and nomic's compressed cosines gave no discrimination, so the index is limited to the sourced-methodology docs (`config('assistant.methodology_docs')` = ASSUMPTIONS.md, MORTALITY.md, RESEARCH-stress-test-and-official-sources.md); methodology questions now score 0.70–0.76 (right source doc), scenario questions 0.58–0.63 (nothing attaches at 0.66). Broader engine-computation methodology (emergency tax, Monte Carlo) is now covered by **docs/METHODOLOGY.md** (see next bullet). 15 new unit tests; **verified end-to-end vs real `qwen3:14b` + `nomic-embed-text`** (a mortality-methodology question answers grounded + sourced; a scenario question attaches nothing). **Phase 3 (backlog capture — the model's only write) remains specced.** See DECISIONS 2026-07-03. **Pending Rob's browser sign-off** (build the index, enable the flag, open a results page).
- **Done — /methodology page + doc (2026-07-03):** a purpose-written **`docs/METHODOLOGY.md`** covering the engine's computations (income tax stacking, the lump-sum tax shock + emergency tax + reclaim forms, MPAA/AA, State Pension, SDLT/CGT/PRR, means-tested benefits, IHT, care, mortality, the Monte Carlo, the year-by-year projection) plus a thorough **"what we don't model"** list. **One source, two homes:** it is BOTH the public **`/methodology`** page (`MethodologyController` + `Str::markdown`, mtime-cached, scoped `.methodology-prose` CSS, footer-linked) AND part of the assistant's methodology corpus. **Accuracy-first:** written from a **code-grounded survey of the actual engine** (not planning prose), with the honest caveats (IHT + care means-test are standalone, not in the year loop; SDLT surcharge + Pension-Credit carer addition exist but aren't wired live; the lump-sum panel omits SP/DB other income). Corpus re-indexed (43 chunks); **verified E2E vs real `qwen3:14b`** — emergency-tax / Monte-Carlo / let-home-CGT questions now answer accurately + grounded (before: planning noise or nothing). See DECISIONS 2026-07-03. **Pending Rob's browser sign-off** (open `/methodology`).
- **In progress:** nothing mid-edit. Two live carry-overs a fresh agent must know: (1) the **estate-inheritance fix** changed every couple forecast, so **stored runs from before it are stale — re-run couple scenarios** (Compare's "Re-run all N" button, or the queued worker); (2) the real **V2 couple's data** is captured privately in the gitignored `docs/SCENARIO-V2.local.md` (never commit) — the durable source to rebuild the scenario after a DB wipe.
- **Known bugs / broken:** none open (the five 2026-06-28 re-review findings are all resolved — see Session log + docs/PLAN.md "Review findings"). Documented v1 scope limits, all flagged in code: income tax England/Wales/NI only (Scotland throws); emergency tax models the over-deduction magnitude, not PAYE-table pennies; mortality grid ages 50–100 / years 2025–2074 with clamping + a non-ONS tail above 100 (cap 110); forecast taxes GIA dividends + cash interest annually AND realises CGT on GIA disposal (ISA tax-free; GIA/cash grow at capital only; v1 omits capital-loss relief + judges the CGT band on non-savings income); income-tax thresholds frozen until 2031, then indexed with inflation; DB escalation + triple lock as smooth growth factors; selling a **let** former home is charged **partial-PRR CGT** (occupation-driven, joint-owner split, gov.uk HS283 — see DECISIONS 2026-06-30), with deemed-occupation absences entered by hand and one rate per owner; a home lived in throughout stays full-PRR / £0; no SDLT surcharge on the replacement; house/salary growth deterministic inside the Monte Carlo; a **one-off cost fires on the first-declared person's age only** (`oneOffCosts` has no `personId` — a second-person-age cost can't trigger). **New v1 limits from the 2026-07-02/07-03 silent-drop fixes** (all flagged in code + DECISIONS): DB **commutation** treats the lump sum as fully tax-free (the LSA cap is not enforced) and a member dying before NRA never takes it (the survivor then uses the reduced base); **ownership share** apportions the mortgage + running costs pro-rata (a non-pro-rata upkeep split isn't modelled); **NI category** covers the employee-rate profiles (standard / reduced / deferred / nil) but not employer NI. The five collected-but-unconsumed inputs the 2026-07-02 audit found are now all consumed (DATA-MODEL "Known divergences" — no open silent-drops).

## What's next (in order)
The adviser-legibility workstream and the whole post-v1 backlog are built (see Current state). What remains:
1. **Rob's browser verification + sign-off** (testing deferred by Rob). The whole post-2026-06-29 cluster is built but unreviewed in the browser. **Re-run the browser a11y pass over the post-06-29 panels** (the 2026-06-29 axe pass predates annuitisation / stress-test / care-risk / sequencing; `npm run a11y` for the CI sweep; see docs/A11Y.md); check the **mobile** view of the results "on this page" nav (desktop signed off); the **2FA QR scan**; eyeball the new panels (annuitisation / stress-test / care-risk / withdrawal-sequencing). **NB the local DB has 0 (or stale) completed runs — re-run a forecast before checking the Monte Carlo charts / PDF.**
2. **Public-release blockers** (harmless while private; mandatory before any public launch — each flagged in code). Set **`config('compliance.personal_use')` false** + confirm the guidance-only partition re-applies (the suite already runs with it false); **swap the stress-test dataset** off the CC BY-NC-SA JST source for an OGL/licensed one (`HistoricalReturns`, DECISIONS 2026-07-01); tighten the **CSP `script-src` to nonces** (Alpine CSP build, needs the browser); complete the a11y pass to a public bar.
3. **Optional refinements to built features** (all flagged v1 limits; pick by value). **Care:** means-test the tail once assets fall below the threshold (`Care\CareMeansTest` is the hook), sex/age-split probability + HSLE timing, ONS-xlsx auto-parse for `mortality:refresh`. **CGT:** deemed-occupation absences, per-owner band-straddle from exact income, shared-occupancy lettings relief (DECISIONS 2026-06-30). **Monte Carlo:** stochastic house/salary growth (currently deterministic), post-2031 reindexing, per-scheme DB escalation. **Annuitisation:** explicit retirement-*month* override.
4. **CI / data hygiene.** Wire the freshness guardrails (`figures:freshness`, `mortality:refresh`) into a scheduled/CI run so aging or drifted figures fail loudly. Low-value hardening (confirm worth it): tamper-evident run hash, forecast caching.

**Specced-but-unbuilt work (lanes closed — no coordination needed, just pick it up).** Lane C: withdrawal-sequencing next steps #5/#6 (docs/PLAN-withdrawal-sequencing.md — gated on two modelling calls from Rob). Lane D: multi-property (docs/PLAN-multi-property.md, DRAFT, [needs Rob]). (Lane B is complete — the in-place forced sale built 2026-07-03.)

## Open items
Open decisions and parked work, off the immediate go-live path (which is under What's next).
- [ ] **Spreadsheet import** — the line-item expense-categories data-model decision; re-verify IWT CSP vs the real 2023 export (the fixture was built from a masked dump); Nischa deprioritised (`isAvailable()=false`); imported income → Person 1, no start age (by design, flagged). Real sample `.xlsx` live in gitignored `docs/*.xlsx` (never commit).
- [ ] **Assumption-set figures** are not numerically editable in Filament (curate-metadata-only; figures seeded from the engine library — editing one means re-sourcing with a new verified-on date). **Now subsumed by the "everything user-editable" direction** (DECISIONS 2026-06-29 + What's next #3): assumptions become editable in the **main UI** as a user-derived *custom set* from the sourced presets, not in Filament.
- [ ] **ONS mortality + FCA/DMS assumption *sources*** sit at their 2026-06-24 sign-off (docs/ASSUMPTIONS.md, docs/MORTALITY.md) — a separate review from the gov.uk statutory-figure pass; the `investmentIncomeYield` 2% is a reviewed-and-kept modelling assumption.
- [ ] **Demo couple's anonymised figures** — Rob supplies later, entered via the UI, not hardcoded (field list in docs/PLAN.md "Data Rob supplies").
- [ ] **External-review enhancement backlog** (post-v1, not blocking) — docs/PLAN.md "External review triage" (cashflow timeline, longevity-distribution visual, stress-test panel, what-if sliders, v2 annuitisation + care-cost stochasticity). Declined items recorded in DECISIONS 2026-06-25.
- [ ] **Delta-research backlog (2026-07-02)** — docs/PLAN.md "Delta-research backlog" + docs/RESEARCH-delta-2026-07-02.md. The **data-integrity fixes are all done** (the five collected-but-unconsumed inputs; DATA-MODEL "Known divergences" — survivor DB pension, per-person salary growth, DB commutation, NI category, ownership share; 2026-07-02/07-03). Remaining: the user-facing-copy fixes (the "Survivor fraction" field is now fixed; the **care-off silence** still misleads — a default-off ~1-in-4 six-figure care tail isn't surfaced), then the uncertainty-communication + household-composition + methodology + a11y items in the Delta-research backlog. Then, by value: uncertainty-communication upgrades; the **third-adult-contributing-to-upkeep** scope item (Rob's back-burner ask — BoardContribution stream + HouseholdMember, no third planning subject); a user-facing **/methodology** page; an adviser/Pension-Wise output pack; **WCAG 2.2 AA** + mobile to a public bar; and the JST-stops-at-2020 stress-test data gap. **Household scope decided: no third full planning subject** (DECISIONS 2026-07-02).
- [ ] **In-app local-model assistant (2026-07-03)** — **Phases 1–2 BUILT** (grounded scenario-explainer + methodology doc-RAG, see Current state, pending Rob's browser sign-off); **Phase 3 specced.** docs/RESEARCH-local-assistant.md + docs/PLAN.md "In-app local-model assistant" + DECISIONS 2026-07-03. A local (Ollama) results-page "chatbot" that **explains + captures, never predicts/calculates/builds**: phased (1) ✅ grounded scenario-explainer (now also on the **Compare page**, comparison-aware via a shared `AssistantContext` + `ComparisonContext`), (2) ✅ methodology doc-RAG (curated corpus — see LA-9), (3) research/feature capture. **The model's only write (Phase 3) is `queueBacklogItem` (append-only, attributed); it never builds code or offers to.** Two guardrails — G1 figure-grounding, G2 runtime phrasing partition — the latter a flagged public-release blocker. The **/methodology page** that widens the doc-RAG corpus for engine-computation methodology is now **built** (docs/METHODOLOGY.md; see Current state).

## How to pick up
Run from the **project root** (the test runner shells out to a relative phpunit path, so it fails from `C:\Users\r`):
```powershell
Set-Location "C:\Dev\RetireForecast"
# NB: php / artisan / composer / npm are NOT on the Git Bash PATH on this machine — run them via the
# PowerShell tool (PHP 8.4 is provided by Laravel Herd). Bash is fine for git / grep / file ops. See CLAUDE.md.
php artisan test                            # full suite — must be all green (the baseline; red = stop and fix)
php artisan test --testsuite=Engine        # engine only
npm run a11y                                 # Pa11y CI a11y sweep (needs the served app — see docs/A11Y.md)
vendor/bin/pint --dirty                      # house style on changed files
npm run build                                # build assets (public/build is gitignored); `npm run dev` to watch
```
**Queue worker — run it with JIT for the 10k Monte Carlo (≈2× faster, byte-identical).** OPcache JIT is off by
default on this machine (`opcache.enable_cli=0`, `opcache.jit=disable`), so the full simulation job runs fully
interpreted unless you start the worker with JIT:
```powershell
php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 artisan queue:work
```
The synchronous preview (deterministic, 1 path) does not need it. Re-measure perf with a 10k `Simulator::run` over
the `comfortable` fixture (see DECISIONS 2026-06-28 "10k-path perf"); there is no timed test (wall-clock is
environment-dependent), the `IncomeTaxTotalPenceTest` grid is what guards the refactor's correctness.
If `vendor/` is missing: `composer install`. If engine classes are not found, re-register the path package: `composer update retireforecast/finance-engine`. To use the app locally: **Herd already serves it at `https://retireforecast.test`** — no `php artisan serve` needed locally (run `npm run build` after asset changes, as `public/build` is gitignored). `php artisan serve` is only needed where Herd is not running (e.g. CI). This flow works fine under the new CSP. **CSP note:** the `web` group ships a Content-Security-Policy (`config/security.php`); it does **not** include the Vite dev-server/websocket origins, so if you use HMR (`npm run dev`) set `SECURITY_HEADERS_ENABLED=false` in `.env` while developing, or `SECURITY_CSP_REPORT_ONLY=true` to stage it. **DB setup — IMPORTANT:** a local `database.sqlite` that predates the Phase B rebuild has a stale schema (the `create_scenarios` migration was **rewritten in place**, so `php artisan migrate` alone will NOT add `builder_state`). On any pre-rebuild DB, run **`php artisan migrate:fresh --seed`** (rebuilds from current migrations; `--seed` runs `DatabaseSeeder` → assumption sets + a `test@example.com` / `password` user). For a ready-made fictional sample, seed the **demo preset**: `php artisan db:seed --class=Database\Seeders\DemoScenarioSeeder` (idempotent; outside production it creates **`demo@example.com` / `password`** with a base plan + one what-if child; in production set `DEMO_USER_EMAIL` to an existing user). Register your own at `/register` and **accept the one-time guidance-only disclaimer at `/welcome`**. **Two-factor auth** enrols at **`/account/security`** ("Security" in the nav; behind a fresh password confirmation). Build a forecast at `/scenarios/create`, run it on its results page; download a **PDF summary** from there. The full queued run needs a worker (`php artisan queue:listen`); the preview does not. **Admin panel at `/admin` is gated on `is_admin`** (default false) — grant yourself once with `php artisan user:make-admin {email}` (e.g. `demo@example.com`), after which the Users resource toggles **Admin access** + `can_interpret`. Tests neutralise Vite, so they pass without a build. **Local-model assistant (inert by default):** set `ASSISTANT_ENABLED=true` in `.env` to show the results-page side panel (needs Ollama up with `qwen3:14b` — already on this machine). For Phase 2 methodology answers, build the doc index once with **`php artisan assistant:index-docs`** (curated corpus → gitignored `storage/app/private/assistant/doc-index.json`; without it, methodology just isn't attached and scenario answers still work). Re-run the command after editing a curated methodology doc.

## Sibling docs
| Doc | Purpose |
|-----|---------|
| docs/PLAN.md | The full approved implementation plan. Source of truth for scope, data model, tax rules, Monte Carlo design, phasing. Holds the "Sector-informed build plan (2026-06-25)". |
| docs/RESEARCH-cashflow-modelling.md | How the sector (Voyant/Timeline/CashCalc, PLSA/SMPI) solves edit/clone/compare, line-item expenditure, drill-down — what we adopt + the gaps it surfaced. |
| docs/RESEARCH-editable-assumptions-ux.md | How free consumer tools (Boldin, ProjectionLab, NYT rent-vs-buy, Guiide, Actuaries Longevity Illustrator) handle editable assumptions, buy-vs-rent and cost breakdowns — what we adopt (2026-06-29). |
| docs/RESEARCH-document-import.md | PARKED post-v1 feature: statement-driven onboarding + document import (sector evidence, document→builder-field map, gotchas). |
| docs/RESEARCH-competitive-gap-analysis.md | Full-market competitive scan (2026-06-30): where the engine already leads vs where the gaps are (decumulation policy + framing). Net-new backlog items folded into docs/PLAN.md "Competitive gap analysis". |
| docs/RESEARCH-delta-2026-07-02.md | Delta research (2026-07-02) over the five topics the competitive scan left as residuals/gaps: uncertainty communication, household composition (+ third-adult-contributing-to-upkeep UK mechanics), adviser/Pension-Wise outputs, accessibility + mobile (WCAG 2.2 AA), methodology disclosure. Adversarially source-checked; recommendations folded into docs/PLAN.md "Delta-research backlog". |
| docs/RESEARCH-local-assistant.md | Spec + feasibility (2026-07-03) for the in-app **local-model assistant** ("chatbot"): a results-page explainer + backlog-capture on a local model (Ollama). The model **explains + captures, never predicts/calculates/builds**; phased explainer → doc-RAG → capture; two guardrails (figure-grounding + runtime phrasing partition). **Phase 1 BUILT** (`App\Assistant\` + `ScenarioAssistant`); Phases 2–3 specced. See DECISIONS 2026-07-03. |
| docs/RESEARCH-stress-test-and-official-sources.md | Stress-test industry standards + official UK data sources (2026-07-01): historical sequence backtesting; BoE has no equity total return, so the source is the JST Macrohistory dataset (CC BY-NC-SA); care-cost = LaingBuisson/PSSRU + ONS timing; ONS-refresh = fully ONS. Source decisions resolved + built. |
| docs/PLAN-withdrawal-sequencing.md | Tax-efficient withdrawal sequencing across wrappers (ISA/SIPP/GIA) + "fill the band" with the lifetime-tax £-delta. **CORE SHIPPED 2026-07-01**; #5 PCLS-timing + #6 optimiser handed off (ready-to-execute plan in the spec, gated on two modelling calls from Rob). |
| docs/PLAN-mortgage-payment-stop.md | Spec + **BUILT** (2026-07-01): stops the bundled mortgage *payment* after a repay-from-capital redemption via a `while_mortgaged` expense condition + `ExpenseProfile::mortgageCosts`. Kept as the build record. |
| docs/PLAN-in-place-forced-sale.md | Spec + **BUILT** (2026-07-03): `ForcedSale` sold in place at the redemption year — `PathProjector` event sells at the grown value via the shared `HousingProceeds::compute`, frees equity into GIA, stops housing costs, charges the entered rent; rent + selling costs ride on `ForecastSettings`. The last Lane-B item, now closed. |
| docs/SCENARIO-V2.local.md | **GITIGNORED / PRIVATE** (real couple's data, never commit): the durable capture of the V2 couple + core scenario (incomes, the flat, CGT history, base + what-ifs) to re-model from after a DB wipe. |
| docs/METHODOLOGY.md | User-facing engine-computation methodology (income tax, lump-sum shock, SP, SDLT/CGT/PRR, benefits, IHT, care, Monte Carlo, the year loop) + "what we don't model". One source, two homes: the public `/methodology` page AND the assistant's methodology corpus. Written from a code-grounded engine survey (2026-07-03). |
| PRD.md | Goal, success criteria, scope, non-goals, open questions. |
| DATA-MODEL.md | Canonical data shape; what is materialised in code today vs planned. |
| DECISIONS.md | Append-only decision log with rationale. |
| CLAUDE.md | Root orient tripwire + build/test conventions + "Doc hygiene" rules. |

## Branch status
On `master`. A GitHub remote exists (`origin` → github.com/RobertLCraig/RetireForecast). Check sync with `git rev-list --left-right --count origin/master...master` — the count is not transcribed here (it drifts; the 2026-07-02 history rewrite + force-push is recorded in DECISIONS 2026-07-02). **Pushing to `master` is gated and needs Rob's explicit go-ahead — do not push unprompted.** Otherwise commit directly to `master` (personal local-first project; no PR flow). **Re-check `git status` / `git log` before any commit or push.** The pre-rebuild prototype is tagged **`prototype-v1` (a8f1f68)**, the only recovery snapshot. For the commit history use **`git log`** (the source of truth — not restated here, where it would drift); the recent trajectory is in the Session log + DECISIONS.md.

## Session log
_Newest first. Keep only the recent live window here; older sessions are in `git log` + DECISIONS.md. Per-session figures are dated history and may stay._

_2026-07-03 (/methodology page + doc — engine-computation methodology, one source for page + assistant)_ — Built the
**/methodology page**: a purpose-written **`docs/METHODOLOGY.md`** that is BOTH the public page (rendered by
`MethodologyController` + `Str::markdown`, mtime-cached, scoped `.methodology-prose` CSS, footer-linked) AND part of
the assistant's methodology corpus — one source, two homes. It covers the engine's computations (income-tax stacking,
the lump-sum tax shock / emergency tax / reclaim forms, MPAA, State Pension, SDLT/CGT/PRR, means-tested benefits, IHT,
care, mortality, the Monte Carlo, the year-by-year projection) + a thorough "what we don't model". **Accuracy-first:**
written from a **code-grounded engine survey** (a subagent read the actual engine; not the planning prose), keeping the
honest caveats (IHT + care means-test standalone, not in the year loop; SDLT surcharge + carer addition exist but
aren't wired live; the lump-sum panel omits SP/DB other income). Added to `config('assistant.methodology_docs')`,
re-indexed (43 chunks); **verified E2E vs real `qwen3:14b`** (emergency-tax / Monte-Carlo / let-home-CGT now answer
accurately + grounded — before, planning noise or nothing). Page + corpus tested; suite green. DECISIONS 2026-07-03.
Nothing pushed.

_2026-07-03 (assistant on Compare — a multi-plan context for comparison questions)_ — Showed the assistant on the
**Compare page** too, where it must answer **comparison** questions the single-scenario context can't. Introduced a
shared **`AssistantContext`** contract (`promptBlock`/`includesMonteCarlo`/`systemIntro`) that both `ScenarioContext`
and a new **`ComparisonContext`** implement — `SystemPrompt` + `AssistantService` now work against the interface, so
the guardrails and orchestration are untouched. `ComparisonContext` lays out each compared plan (base + ready what-ifs)
with its deterministic headline figures (money lasts / runs short, essentials, spendable + total wealth, how it differs
from base), from the **same per-variant `deterministicVariants` forecasts the Compare table renders** (provenance); its
system intro tells the model to name the plan(s) and **not do arithmetic across plans** (so a compared figure grounds,
never an invented delta). `ScenarioAssistant` gained a **`compare` flag** (mounted `:compare="true"` on Compare) with
comparison starter questions. Deterministic only (per-plan Monte Carlo a later add). Verified end-to-end vs real
`qwen3:14b` (named the plan leaving the most + which last vs run short, all grounded). Unit + Livewire tested. Suite
green. DECISIONS 2026-07-03. Nothing pushed.

_2026-07-03 (assistant UI — docked sidebar, Clear, adviser-style starter questions)_ — On Rob's asks: turned the
assistant from a floating "live chat" bubble into a **docked full-height side panel** (edge-tab when closed;
`fixed inset-y-0 right-0`, `border-l`), added a **Clear** (wipes the local transcript back to the starter state), and
replaced the three plain-text examples with **grouped, clickable starter questions** (`ScenarioAssistant::suggestions()`;
a click asks directly via `ask($preset)`). The "Risks worth checking (Pension Wise / adviser)" group is the
plain-English form of the five **COBS 9.4.10G** drawdown risk warnings — the "what to ask a financial adviser"
material from the adviser-pack backlog (docs/RESEARCH-delta-2026-07-02 §3); the home-sale starter shows only for a
sell strategy. Also made the open panel **shrink the page** beside it (CSS-only `body:has([data-assistant-open])`
padding on lg+, so the fixed sidebar sits beside the content, not over it) and **trimmed the results left nav**
(`13rem`→`11rem`). View + component only (service/engine untouched); neutral (passes `BannedPhrasingTest`);
Livewire-tested. Suite green. DECISIONS 2026-07-03. Nothing pushed.

_2026-07-03 (local-model assistant — Phase 2 built: methodology doc-RAG)_ — Built **Phase 2**: "how does it model
X?" answers on local embeddings (`nomic-embed-text`). App-layer only. New `App\Assistant\`:
`EmbeddingClient`/`OllamaEmbeddingClient`, `DocChunk`/`DocChunker`, `DocIndex` (**hand-rolled cosine — no vector
DB**), `MethodologyRetriever`; an **`assistant:index-docs`** command writing a gitignored index under
`storage/app/private/assistant/`. **Prompt-stuff, not intent-route** (a 14B router is unreliable): each turn embeds
the question, cosine-searches, attaches the top chunks above a threshold under a labelled METHODOLOGY section (the
Livewire component threads it into `AssistantService::answer()`; the service stays pure). G1 grounding widens to
context+methodology. **Live verification changed the design (the important bit):** indexing all of `docs/` surfaced
internal planning/build-record prose as "methodology" and nomic's compressed cosines (~0.64–0.71 for everything)
gave a global threshold no discrimination — so the corpus is **curated** to the sourced-methodology docs
(`config('assistant.methodology_docs')` = ASSUMPTIONS/MORTALITY/stress-test). Methodology questions then score
0.70–0.76 (right source doc), scenario questions 0.58–0.63 (nothing attaches at the 0.66 threshold). Broader
engine-computation methodology (emergency tax, Monte Carlo) deferred to a purpose-written /methodology page. 15 new
unit tests; verified end-to-end vs real `qwen3:14b` + `nomic-embed-text` (mortality-methodology answered grounded +
sourced; scenario question attaches nothing). Suite green. **Phase 3 (backlog capture) remains specced.** DECISIONS
2026-07-03. Nothing pushed.

_2026-07-03 (assistant context — home-sale waterfall; Phase-1 sources now comprehensive)_ — Added the **home-sale
waterfall** as the next context source (same pattern: a fourth optional formatted array on `ScenarioContext`, reused
from `ResultPresenter::saleExplainer()`, resolved by the Livewire component as the results page does). For a sell
strategy it exposes what the household pockets (sale − mortgage − costs − CGT = net proceeds) and the
invest-and-rent / buy-cheaper split (surplus or shortfall); null for stay-put. Verified vs real `qwen3:14b`
(£274k pocketed, £106.7k to invest; £118k mortgage, £8k costs). **Phase-1 context sources are now comprehensive** —
central projection, year-by-year ladder, Monte Carlo, tax shock, sale waterfall. Suite green. DECISIONS 2026-07-03.
Next is phase-level (Phase 2 doc-RAG, Phase 3 backlog capture). Nothing pushed.

_2026-07-03 (assistant context — Monte Carlo probabilities + lump-sum tax shock)_ — Continued widening what the
assistant can interrogate. Added the two figures the tool most exists to surface: **Monte Carlo probabilities/ranges**
(chance the money lasts / runs out, terminal-wealth spread, longevity, care tail — from a completed run's
`SimulationResult`, prefixed "Monte Carlo —"; the context says so when no run exists) and the **lump-sum tax shock**
(the flagship — 25% tax-free, marginal tax, Month-1 emergency over-deduction + P55/P50Z/P53Z reclaim, MPAA — reused
from `LumpSumTaxShock::assess()`). Both are optional inputs to `ScenarioContext` (so it stays pure/unit-testable); the
Livewire component resolves them. Every figure reuses the panels' own formatting (provenance) and grounds; the system
prompt keeps "how likely" apart from "what happens". Verified vs real `qwen3:14b` (18% run-out / 71% funded / wealth
range; £17,432 tax + £11,568 reclaim via P55). Suite green. DECISIONS 2026-07-03. Nothing pushed.

_2026-07-03 (assistant refinements — side panel + year-by-year interrogation)_ — Testing Phase 1, Rob's "essentials
in 5 years?" refused (context held only headline facts) — his framing: **a big part of the point is to interrogate
data not visible in the UI.** So `ScenarioContext` now carries the **full year-by-year cashflow ladder** (per-year
essentials / discretionary / income-by-source / tax / wealth, from the reconciled `ResultPresenter::ladder()` rows,
**one line/year, every figure inline-labelled** — keeps the right number on the right thing, LA-8). All figures land
in the grounding allow-list, so per-year questions are answerable; verified vs real `qwen3:14b` ("essentials in 5
years" → the right year's £; maps "5 years" to a calendar year). Limit (by design): the model won't sum across years
(an ungrounded aggregate → G1 refuses). Also **moved the panel to a fixed, collapsible SIDE panel** (bottom-right,
launcher when closed) — pure Livewire `$open`/`toggle()`, CSP-safe. Suite green. DECISIONS 2026-07-03. Nothing pushed.

_2026-07-03 (local-model assistant — Phase 1 built)_ — On Rob's "go" (build), built **Phase 1** of the specced
assistant: a grounded, local **scenario-explainer** on the results page. All app-layer (engine untouched). New
`App\Assistant\`: a `ChatClient` interface + `OllamaChatClient` (local HTTP, fails loudly, strips `<think>`);
`ScenarioContext` (the engine figure snapshot = the model's context AND the grounding allow-list, one home);
`FigureGrounding` (**G1** — rejects any £/year/% not engine-derived); `SystemPrompt`; `AssistantService` reusing
`OutputPhrasing` (**G2**, advice/guidance on the `interpret` gate) with one corrective retry then a refusal rather
than a bad answer. Thin `App\Livewire\ScenarioAssistant` + view, embedded behind `config('assistant.enabled')`
(**inert by default**; env `ASSISTANT_ENABLED`). Design call: **prompt-stuff a bounded figure snapshot, not
tool-calling** (more reliable on a smaller local model; the snapshot appends more facts as context grows). 19 unit
tests incl. the headline "**never surfaces an invented figure**" invariant; **verified end-to-end against the real
`qwen3:14b`** — a grounded answer passes, and it declined to invent a 2070 house value. Suite green. **Phases 2–3
(doc-RAG, backlog capture) remain specced.** Design calls in DECISIONS 2026-07-03; **pending Rob's browser sign-off.**
Nothing pushed.

_2026-07-03 (local-model assistant spec — doc-only)_ — Rob asked to investigate a page "chatbot" on a **local AI
model** to answer questions about the scenario, queue research, and drive the feature/task list. Investigated: the
project had **already settled the local-AI stance** (2026-06-28 document-import — *explain/capture, never predict*;
local-only; never the source of a number) and the **runtime is already up** (Ollama on `localhost:11434` with
tool-calling models + `nomic-embed-text`). Honest reframe: only the **scenario-explainer** has unique in-app value;
research/tasklist is thin NL→backlog capture a 14B local model shouldn't do "for real" (that's Claude Code's job).
Rob's hard constraint: **the model may only append to the work queue — it never builds, edits code, or offers to.**
Specced across **docs/RESEARCH-local-assistant.md** (framing, architecture, two guardrails — G1 figure-grounding /
G2 runtime phrasing partition, phasing explainer→doc-RAG→capture, gotchas LA-1…LA-8) + PLAN backlog + DECISIONS +
this Open-items/sibling-docs. **Nothing built.** A concurrent session committed the sources/contacts panel
(54af7bd, b9824f2) meanwhile — coordinated; staged only these four doc files. DECISIONS 2026-07-03.

_2026-07-03 (V2 scenario rebuilt + CGT entered + buy-with-mortgage feature)_ — Long session driving the real V2
couple's model. **(1) Rebuilt the DB scenarios** (backed up to gitignored `database/backups/` first): cleared the
clutter (12 old scenarios), made **"Stay put" the base** (id 9 — the ~£90k pays the mortgage down to £118k +
refinances as a residential RIO, payment reset to £7,080/yr = £118k interest-only @6%, *researched*), and rebuilt 5
clean named delta-children (forced-sale→rent, sell-&-buy, let-out-&-rent, retire-later, live-longer) via a tinker
transform of scenario 9's real data. **(2) Entered the CGT history** (never persisted in any scenario; sourced from
the gitignored `docs/SCENARIO-V2.local.md`): base cost £192.5k, £35k improvement **not** claimed (unevidenced +
mostly repairs, *researched* — no time bar but must substantiate, and replacement kitchen/bath/windows are repairs
not improvements), higher-rate → **CGT ≈ £24,237**; the sell what-ifs now reflect it (forced-sale proceeds fell by
exactly the CGT). **(3) Built buy-with-mortgage** (`HousingAction::buyMortgageRate` → a RIO on the shortfall; see the
top DECISIONS entry) after researching that a £200k buy is unaffordable but a ~£165k one funded by a ~£61.7k RIO is
feasible (survivor-affordability the constraint). The V2 "Sell & buy cheaper" now uses £165k + 6% RIO and *lasts*.
**(4) Surfaced sources + contacts in the UI** — a reusable `<x-sources-and-contacts>` "Check these figures & get
help" panel (Pensions & money always; Later-life mortgages + Capital gains tax **contextual** — shown only when a
plan involves a mortgage / sells an ever-let home) on the results + Compare pages + a compact PDF version, with
verified live contacts (Pension Wise, MoneyHelper, Equity Release Council, HMRC CGT 0300 200 3300, TaxAid, FCA);
neutral signposting, passes `BannedPhrasingTest`. All mortgage/CGT reasoning + sources + contacts also captured in
`docs/SCENARIO-V2.local.md`. Suite green (595). `database/backups/` holds the pre-rebuild snapshot. **Unpushed since
the last push:** buy-with-mortgage + sources-panel commits are local-only on `master` (forced-sale + mortgage-maturity
are already on origin).

_2026-07-03 (browser verification of the forced sale → mortgage-maturity made user-modelable)_ — Rob browser-checked
the forced-sale build: it works (input note, the "The home is sold" chart annotation, honest wealth outcomes), but
the one-click "Compare buy vs rent" produced a **"Stay put" plan that silently sold** at the redemption year (it
inherited the base's `forced_sale`; `BuyVsRentCompare` overrode only `variant`). Root cause: `mortgageMaturityAction`
+ `mortgageRedemptionYear` were engine-consumed `Property` fields with **no builder UI**, so the treatment couldn't
be modelled. Rob's call: the treatments (refinance / repay / forced sale) should be **user-buildable scenarios**. So:
(1) added **builder inputs** for both fields (property step: year + a Refinance/Repay/Sell select; validation +
`blankProperty` defaults + `loadState` backfill for old scenarios); (2) `BuyVsRentCompare` now **resets a
`forced_sale` to `refinance` on the generated stay-put child** (it keeps the home; user can model repay explicitly),
mirroring `QuickWhatIf::letOutAndRent`. `ScenarioBuilderTest` pins the round-trip; `BuyVsRentTest` pins the reset.
Adding a non-empty-default field re-surfaced the new-field-spurious-delta gotcha (fixed via `BuilderStateFixture::full()`,
the `ownershipShare` precedent). **Migration:** a `forced_sale` base's existing stay-put child still inherits the
forced sale — regenerate it. Suite green (592). DECISIONS 2026-07-03. Nothing pushed.

_2026-07-03 (in-place forced sale built — last Lane-B item)_ — Resumed from the handover; suite green (reconciled),
then built the one fully-ready no-Rob-input build item: the **in-place forced sale** (Lane C #5/#6 is gated on Rob,
Lane D is a draft, so forced-sale was the unambiguous resume step). `MortgageMaturityAction::ForcedSale` was a
`PathProjector` no-op → a stay-put projection kept the home **for ever**. Now an additive `projectYear` event (after
the RepayFromCapital block) sells the home at the redemption year: at the **grown whole-property value**, frees net
proceeds into the first living person's GIA (basis = proceeds), clears home + debt (new state `homeSold` +
`propertyWhole`), stops the mortgage payment + property/running costs and charges the entered rent from then on.
Extracted the sale maths into **`HousingProceeds::compute`** (ONE reconciled definition — `saleProceeds` now delegates;
`DEFAULT_SELLING_COST_RATE_BP` moved there) so the year-0 variants and the mid-projection sale can't drift. The
projector has no `HousingAction`, so rent + selling-cost basis ride on `ForecastSettings` (new `sellingCosts`), set by
`ScenarioForecaster::settings()` for a ForcedSale scenario only. Pension Credit erosion falls out for free (freed
equity is liquid). `ForcedSaleTest` (wealth conserved to the penny under zero growth; costs-stop-rent-starts; PC
erosion; let-vs-lived-in CGT; no-redemption no-op) + a `ScenarioForecasterTest` completeness test. Enum doc + results
note updated. v1 limits flagged (partial-share penny-exactness; one-year PC lag). Suite green (590). DECISIONS
2026-07-03. Nothing pushed.

_2026-07-03 (fifth/last silent-drop backlog fix — property ownership share; researched)_ — On Rob's "research the
correct answer", wired **`Property::ownershipShare`**. Researched the HMRC tenants-in-common convention: a
beneficial share apportions the **value, sale proceeds and taxable gain**, each owner taxed on their share with
their own allowance (composes with the existing joint-owner CGT split). Convention: the user enters **whole-property**
figures; the share (null = 100%) scales the household's position everywhere the property matters. Applied by scaling
`$state['property']` + `$state['mortgageOutstanding']` at projector init (→ wealth, means-test on a let home, IHT,
growth), the stay-put running costs, and the sale (`HousingComparison::saleProceeds`: household's price/mortgage/costs
+ CGT on its share of the gain). **Scaling is a no-op when null**, so the reconciliation-tested whole-ownership sale
math is byte-identical (every existing housing test unaffected). `OwnershipShareTest` pins the share reaching the
sale proceeds (halved + reconciling), the CGT (smaller share → smaller gain, still non-zero), and the forecast
property wealth (half share = half). Builder gained an ownership-share input. Suite green (584). **All five
silent-drops from the 2026-07-02 audit are now closed.** Nothing pushed.

_2026-07-03 (fourth silent-drop backlog fix — NI category, sourced)_ — On Rob's "always research the correct
answer; accuracy beats less work", wired **`Person::niCategory`** properly. Researched the gov.uk category-letter
tables (`rates-and-thresholds-for-employers`, both supported tax years identical, verified 2026-07-03): employee
main-band rate is **8%** standard (A/F/H/M/N/V), **1.85%** reduced (B/E/I), **2%** deferred (D/J/L/Z), **nil**
(C/K/S/X); upper band 2% throughout. `NationalInsuranceParameters` gained `reducedMainRate`/`deferredMainRate`;
`NationalInsuranceCalculator::onEmploymentEarnings` takes the category (case/space-insensitive, null→standard) and
picks the main rate / returns zero for nil categories; the projector passes `Person::niCategory` through; builder
gained a category select. `NationalInsuranceCalculatorTest` pins each rate to the penny (£62,570 → clean £37,700
main band), `NiCategoryForecastTest` proves it reaches the forecast (year-0 tax falls by the exact band-rate
delta). Suite green (581). **Last silent-drop: `Property::ownershipShare`** — researching the tenants-in-common
convention (HMRC apportions value/proceeds/gain by beneficial share) before wiring. Nothing pushed.

_2026-07-02 (third silent-drop backlog fix — DB commutation; Rob chose wire-all-three)_ — Asked Rob wire-or-remove
for the three remaining lower-impact unconsumed fields; he chose **wire all three**. Did the biggest first: **DB
commutation** (`commutationLumpSum`/`commutationFactor`). Retyped `commutationFactor` `Percent`→**`float`** (a
commutation factor is a ratio, e.g. 12:1, not a percentage — the fixtures had worked around the mistype). Model:
a shared `commutedAnnualPence` reduces the pension by `lumpSum ÷ factor` (null/≤0 → 12), used by both the member's
`dbIncome` and the survivor pension; the tax-free lump sum is paid once at the member's NRA (`age === NRA`, so a
pre-forecast commuter isn't re-paid), escalated by `dbFactor`, routed as PCLS-style tax-free cash into wealth.
Ripple: assembler (`floatOrNull`), both fixtures (`12`), `WhatIfChanges` (dropped from RATE), builder gained a
factor input + hints. v1 limits flagged (LSA cap not enforced; death-before-NRA uses the reduced base).
`DbCommutationTest` pins reduction + one-off lump sum + null-factor default + no-commutation baseline. No fixture
drift (feature tests don't assert the affected figures). Suite green (577). **Next: `Person::niCategory` +
`Property::ownershipShare` (wire).** Nothing pushed.

_2026-07-02 (second silent-drop backlog fix — per-person salary growth)_ — Continued straight on from the
survivor DB fix to the next backlog item. **`Person::salaryGrowth`** was a live, validated, DTO-mapped builder
input the engine ignored: the projector escalated **one household-wide `salaryFactor`** off the assumption set,
so a person's own figure vanished and two earners couldn't grow pay at different rates. Made `salaryFactor` a
**per-person map**; `growState` now escalates each person at their own **real** override, falling back to the
assumption-set rate when null (override = trend not risk, no volatility — same convention as the per-asset
overrides); the two salary read points (earnings + NI) index by person. Chose **real** semantics to match the
existing "Salary growth (real)" framing (one definition); relabelled the builder field + added a hint.
**`PerPersonSalaryGrowthTest`** pins the exact compounding rate, per-person independence, and the null fall-back.
Docs reconciled (DATA-MODEL, PLAN, DECISIONS). Suite green (574). **Next silent-drop fixes: `DbPension`
commutation, then `Property::ownershipShare` / `Person::niCategory` (wire or remove).** Nothing pushed.

_2026-07-02 (first silent-drop backlog fix — survivor DB pension)_ — Resumed from the handover; suite green
(reconciled), then picked the plan's first agent-executable item: the top data-integrity silent-drop the
2026-07-02 audit re-found. **`DbPension::spousePensionFraction`** was collected/validated/mapped but consumed by
no engine code, so a DB member's death dropped the survivor's DB income to **£0** regardless of the entered
fraction. Fixed with a new **`PathProjector::survivorDbIncomeNominal()`** (joint-life analogue of the annuity
survivor income): on the member's death the survivor keeps `accruedAnnualPension × dbFactor × fraction` for life,
sourced as `defined_benefit` (taxed + Pension-Credit-assessable). Member-alive path unchanged (no double-count);
a null fraction still stops on death. **`SurvivorDbPensionTest`** is the completeness guard (the fraction
demonstrably reaches the forecast; the null boundary holds). Confirmed the app path is live end to end (builder
validate → `HouseholdAssembler` → DTO → engine; the demo `db1` carries a 50% fraction) and added a builder hint
under the field (it previously did nothing, unexplained). Docs reconciled (DATA-MODEL, PLAN backlog, DECISIONS).
Suite green (571). **Next silent-drop fix: `Person::salaryGrowth`.** Nothing pushed.

_2026-07-02 (docs-only — delta research folded in, doc set reconciled to code, household scope decided)_ — On Rob's
"review the project + the improved brief, then update the plan and documentation so we can build any features really
well". Ran two workflows: (1) a **delta research wave** over the five topics the 2026-06-30 competitive scan left as
residuals/gaps (uncertainty communication, household composition, adviser/Pension-Wise outputs, accessibility +
mobile, methodology disclosure), each claim adversarially source-checked → **docs/RESEARCH-delta-2026-07-02.md** +
the PLAN "Delta-research backlog"; (2) a **doc-set audit vs code**, whose fixes landed across PRD (open questions
that shipped marked resolved), DATA-MODEL (five collected-but-unconsumed fields caveated; three never-materialised
fields moved to Known divergences), PLAN + the feature specs (DONE/superseded markers corrected), ASSUMPTIONS /
MORTALITY (runtime editability, grid-edge behaviour + `mortality:refresh`, 2024-based ONS), and the stress-test doc
(JST embeds 1871–2020 only, ONS extension not built). **Household scope decided: no third full planning subject**
(couple ceiling stands — market-unanimous); Rob's "3 adults contributing to upkeep" is a backlog item served by a
household `BoardContribution` stream + a lightweight `HouseholdMember` (no third-person planning). The audit re-found
the **silent-drop bug class** in five live inputs — worst **`DbPension::spousePensionFraction`** (survivor DB pension
silently £0) + **`Person::salaryGrowth`** — logged as the **first** backlog fixes, **not fixed this pass** (docs-only
per Rob). DECISIONS 2026-07-02 (docs-only pass). Suite green throughout (unchanged — no code touched). Nothing pushed.

_2026-07-02 (review pass — 2 HIGH engine fixes, per-asset overrides wired, personal-data scrub + history purge)_ — On
Rob's "review the project, check docs vs reality, check for bugs", ran a multi-agent audit (PathProjector, tax,
reconciliation/completeness, docs) then fixed everything found, each with a test, suite green throughout.
**Bugs:** (1) **DC pot drawn before its earliest access age** — `earliestAccessAge` was mapped + validated but never
consulted in `PathProjector`; `fundShortfall` now gates the pot per owner age (inherited pot = age 0). (2) **CGT
band-straddle** under-taxed below-PA GIA disposals (unused PA leaked into the 18% band; £50k gain at £0 income was
£8,460 vs £9,018) — fixed + extracted the testable `cgtOnGain`. **Silent drop:** (3) the three per-asset overrides
(`DcPension`/`Property` `growthAssumptionOverride`, `Account::yield`) were collected but ignored — **wired into the
engine** (per-pot / per-property growth, per-person balance-weighted GIA yield), completeness-tested. (4) **CGT
`incomeBySource` reconciliation** guard + comment (current numbers were right but unpinned). **Data:** real V2-couple
figures/names had leaked into tracked docs + import tests (violating no-client-data-in-repo) — captured privately
(`docs/morning-worklist.local.md`), scrubbed to placeholders/synthetic values, and **git history rewritten +
force-pushed** (Rob authorised) to purge it from all commits. **Docs:** de-staled the Branch status (was "~12
unpushed", actually in sync), DATA-MODEL (mortgage-stop built; source count; overrides now consumed), the
withdrawal-sequencing header (DRAFT → core shipped), and compressed the closed-lanes coordination block. DECISIONS
2026-07-02.

_2026-07-02 (Lane B — mortgage-payment-stop built; forced-sale specced; V2 couple captured; lanes closed)_ — Finishing
Lane B off the V2 browser review. **(1)** Flagged an **unaffordable "buy cheaper" plan on Compare** (the sale can't
fund the buy — `ScenarioCompare` reads `buyOutcome`). **(2)** Built the **mortgage-payment-stop** — a `while_mortgaged`
expense condition + `ExpenseProfile::mortgageCosts`; the projector drops the payment once `RepayFromCapital` clears the
balance (was double-counted with the one-off repayment); `withoutPropertyCosts()` + PLSA widened to exclude it;
engine-tested (DECISIONS 2026-07-01, docs/PLAN-mortgage-payment-stop.md marked BUILT). **(3)** Specced the last Lane-B
item, the **in-place forced-sale** (docs/PLAN-in-place-forced-sale.md): first as a plan for a fresh agent, then folded
in Rob's 4 decisions — it is a **what-if** on the stay-put base (base = "find £100k and stay" = RepayFromCapital), rent
is a user input (its variations are what-ifs), CGT on the grown value, freed proceeds → investable wealth. Ready to
build. **(4)** Captured the **real V2 couple** (incomes, the flat, the full CGT history Rob gave, the core scenario +
what-ifs) in **`docs/SCENARIO-V2.local.md`** — **gitignored** (`/docs/*.local.md`, never commit) so it survives a DB
wipe; unknowns (SP attribution, DLA annual, DOBs, CGT base cost) flagged, not guessed. Lanes then closed. Suite green
throughout; committed to Lane-B files only, nothing pushed.

_2026-07-01 (Lane C — withdrawal sequencing: results panel shipped; #5/#6 handed off)_ — Built the **results-page
"How you draw your money down" panel** (`ScenarioResults` computes `WithdrawalStrategyComparison`; a neutral partial
shows current-order vs fill-the-bands lifetime tax + the difference) with the **advice-gated steer**
(`Interpretation::withdrawalSequencingNarrative` behind `personal_use`, via the exempt interpretation partial;
banned-phrasing partition intact) + a nav entry + a Livewire test (`39db68c`). Then wrote a **ready-to-execute plan
for #5 (PCLS-timing) + #6 (the optimiser)** into the spec ("Implementation plan for a fresh agent"; `da0d14c`). The
withdrawal-sequencing **core is shipped**; #5/#6 await a fresh agent. Suite green.

_2026-07-01 (Lane C — withdrawal sequencing: engine core + £-delta computation built)_ — On Rob's go-aheads, built the
approved feature in green committed slices. **Engine:** a `FillBands` `DrawdownStrategy` + its fill-order in
`PathProjector::fundShortfall` (pension within the personal allowance → GIA gains within the CGT allowance → cash + ISA
→ basic-rate pension → the rest), made **Pension-Credit-aware** (a Guarantee-Credit household draws capital first, since
any pension income claws the credit back £-for-£; the year loop passes the award through). `TaxEfficient`/`PensionAware`
stay byte-identical (the pension-draw primitive generalised from a basic-rate-cap boolean to a taxable-income limit).
**PA-taper:** resolved by the ordering (all capital is drawn before any higher-rate/taper pension, so the 60% band is
only entered when pension is the sole source, unavoidable, no code). **£-delta (4a):**
`ScenarioForecaster::deterministicUnderStrategy` + `App\Forecast\WithdrawalStrategyComparison` sum each strategy's
lifetime tax (`YearResult::totalTax`) and the difference, reconciliation-tested, neutral by construction. **Next (4b):**
the results-page panel + the advice-gated steer (`Interpretation` behind `personal_use`), then PCLS timing, then the
optimiser. Suite green throughout; commits lane-clean (my files only).

_2026-07-01 (Lane C — withdrawal sequencing: reviewed vs the parallel work, folded Rob's decisions, claimed the lane)_ —
On Rob's steer, reviewed the project + docs against the fast-moving parallel lanes, then advanced the Lane C
decumulation workstream. Refreshed **docs/PLAN-withdrawal-sequencing.md** for the now-live **Pension Credit** means-test
(Guarantee Credit is income-tested £-for-£, so drawing taxable pension above the guarantee claws it back at a 100%
effective rate — this **inverts** the "fill the free band with pension first" heuristic for a low-income household).
Asked Rob the spec's open questions; he approved the **full-capability build** (see DECISIONS 2026-07-01 (Lane C) +
the spec's "Decisions" / "Build order"). Claimed the `PathProjector` lane in the coordination section. Also de-staled
the coordination section (Lane B was built+committed but still described as "uncommitted/in-flight"). **Stopped before
the engine core** (the `FillBands` fill-order in `fundShortfall`): it needs finer draw primitives (pension-to-PA,
GIA-gains-to-CGT-AEA) and reconciliation tests, and the Forecast layer was actively churning under other lanes — that
core is the next action. Docs-only this session; my commits are in `git log` (competitive-scan + spec + coordination).

_2026-07-01 (Lane B — Compare "re-run all" button; Pension Credit how-to-claim; investment-growth in the ladder)_ —
Continuing Rob's V2 browser review. **(1)** A **"Re-run all N (full 10k)" button on Compare** (`ScenarioCompare::runFullFamily`)
queues a fresh 10,000-path run for the base + its ready what-if children in one click — for refreshing stored Monte
Carlo runs after a model change (the comparison table itself is live deterministic, already current). First built on the
individual results page, then moved to Compare on Rob's steer. **(2)** **How-to-claim Pension Credit** guidance
(`ResultPresenter::pensionCreditGuidance`) in the income-floor section, shown only when the forecast credits it: the
gov.uk claim line, backdating, and what it passports to — Pension Credit is under-claimed and means-tested, so modelling
it as income without saying how to get it left money on the table. **(3)** The cashflow ladder now surfaces
**investment growth** (capital appreciation) beside the taxed investment income, answering "where do the gains come
from": `growState` returns the year's capital growth, the loop attaches it deflated by next year's price level (real
purchasing-power gain), and `YearResult::investmentGrowth` carries it; the ladder shows a column (when it occurs) + a
note, and the CSV a column. Engine-tested (an ISA shows real capital growth; the same cash shows ~none — its return is
income, not capital). Suite green throughout; each committed to its own Lane-B files.

_2026-07-01 (Lane B — engine correctness: the survivor inherits a deceased partner's assets)_ — Rob's browser
review of the V2 report surfaced a flagship-number bug via a puzzle: the Monte Carlo said the couple "run out by
2032" while the Compare deterministic said 2044 — same variant, same `PathProjector`. Traced it (ruled out a stale
run — re-running didn't move it) to **stranded wealth**: `fundShortfall`'s drawdown skips a dead owner's accounts
while `liquidWealth` still sums them, so on the first death the deceased's savings/investments and (for sell variants)
the whole invested proceeds — dumped into `persons[0]` — became counted-but-undrawable; the survivor couldn't reach
the money, so the run read as "ran out" at the first death with a full pot idle. Hit **every couple forecast**.
Confirmed with a standalone repro (same household; "depleting" year was just whoever held the money dying). Fixed:
`PathProjector::settleEstates()` — the survivor inherits the deceased's cash/ISA/GIA (CGT base-cost uplift on death)
and remaining pension pot value, once, to the first living person; scheduled withdrawals/contributions don't carry
and only the deceased's own assets move (ownership respected, no double-dip — Rob's constraint). Deterministic and MC
now reconcile (both deplete 2039 on the repro). `EstateInheritanceTest` pins it; suite green. DECISIONS 2026-07-01.
**Stored pre-fix runs are stale — re-run couple scenarios.**

_2026-07-01 (Lane B — single-strategy report + what-if architecture; let-home capital; V2 deferred refinements)_ — On
Rob's read that the confusing results page came from **conflating partial what-ifs into an individual forecast**,
**split variations out into proper what-if scenarios**: the individual report is now **single-strategy** (one card, no
in-report 3-way comparison, no ladder switcher), the strategy comparison lives on **Compare** (whose burndown gained the
same **milestone annotations**), the "explore the levers" sliders became a **"Build a what-if"** save control, and both
charts moved to the **top** of the report + were annotated with the big life events (home sold / State Pension / retires
/ deaths). Added a **"Let out & rent elsewhere"** generated what-if (keep the flat, let it, rent cheaper). Then, on
"pick up the deferred item" + "continue", built: an engine fix so a **let home counts as assessable capital** (letting
erodes Pension Credit like a sale — on V2, £0 vs ~£41k kept when occupied; `Property::isLet`), the **buy>proceeds
feasibility flag** (a buy-cheaper that costs more than the sale frees is flagged, not silently floored to £0), and a
first-class **tax-free disability-benefit income type** (`IncomeStreamType::DisabilityBenefit`, structurally tax-free at
the assembler so DLA can't be mis-taxed or wrongly means-tested). Triaged the rest: **income-ends-on-sale declined**
(no property↔income link in the single-property model — nothing to fix); **mortgage-payment-stop after a repay** is a
real gap left open (needs a `while_mortgaged` condition — mid-design when paused). DECISIONS 2026-07-01 (two entries).
Suite green throughout; each committed to its own Lane-B files. **V2 local scenario (real DWP figures) stays uncommitted
(Rob's data).** All pending Rob's browser sign-off.

_2026-06-30 (Lane B — forced-housing-event workstream A/B/C/D built + committed)_ — On Rob's "do all of it", built the
four-track workstream from the V2 pressure-test, each slice green + committed: **(A)** Pension Credit Guarantee Credit
credited live in `PathProjector` (sourced SMG + severe-disability/carer figures, capital tariff via `CapitalAssessment`,
a `Person::receivesDisabilityBenefit` flag; new `means_tested_benefit` income source) — on V2 it quantifies the
downsizing trap (stay keeps ~£41k of Pension Credit; selling-and-holding-the-cash loses most of it to the capital
tariff); **(B)** a `MortgageMaturityAction` redemption event on `Property` (refinance / repay-from-capital / forced-sale)
so a mortgage is no longer assumed to roll on for life; **(C)** a feasibility note flagging a mortgage due for
redemption; **(D)** a pay-frequency selector (the 4-weekly-DLA / monthly-rent fix) + a disability-benefit checkbox + a
no-retirement-age note. Also fully corrected the local V2 scenario against Rob's real DWP figures (not committed — his
data). Suite green; assets rebuilt. **Deferred** (flagged in the commits): stop the bundled mortgage payment after
a repay, the in-place forced-sale model, income-ends-on-sale, the buy-price-over-proceeds note, a dedicated
tax-free-benefit income type.

_2026-07-01 (Lane A — care-cost stochasticity; post-v1 backlog COMPLETE)_ — Built the last open Lane A item:
the fat-tail risk of late-life care fees in the Monte Carlo. Researched + locked the figures (Dilnot ~1-in-4;
PSSRU length-of-stay ~2.5yr mean; LaingBuisson self-funder ~£1,300/£1,600 a week), baked as sourced
`CareAssumptions`; `CareCostSampler` draws an end-of-life spell per person, wired through a new
`PathDraws::careAnnualCost` (deterministic/historical return 0) into `PathProjector`, aggregated as a `CareImpact`
on `SimulationResult`. Opt-in via a sparse builder toggle (existing runs unchanged); the rent variant propagates
it; surfaced as a results care-risk panel. Engine `96dd534`, app `41b455d`, suite green (560). Committed only my
files; Lane C's committed withdrawal-sequencing left alone. **With this, the whole post-v1 backlog is done** —
what remains is Rob's browser sign-off and the optional refinements.

_2026-07-01 (Lane A — ONS mortality refresh + integrity guard)_ — Built the ONS-refresh backlog item as a
`mortality:refresh` command. Found the engine's `OnsPeriodMortalityData` is generated from a sourced JSON but nothing
verified the two still agreed, so the command's core is a **cell-for-cell integrity check** (5100 cells; the committed
class is confirmed in sync), plus freshness flagging and an `--against` diff of a new ONS release with its life-expectancy
impact. Pure `MortalityDataset` helper, unit-tested; suite green (548). Also did a docs review pass (fixed What's next #0
— Lane B forced-housing is built, not "active" — and the research-doc sibling line). Committed only my files; Lane C's
in-flight withdrawal-sequencing edits (results page + a new partial) left untouched. **Care-cost is the last open Lane A item.**

_2026-07-01 (Lane A — historical stress-test built)_ — Built the stress-test panel end to end after the source
research. Chose historical sequence backtesting (sector standard). Data gate: BoE (Rob's first pick) turned out to
hold only equity *prices* + bond *yields* on inspection (no total returns), so — after surfacing the correction —
switched to the **Jordà–Schularick–Taylor Macrohistory** total-return dataset (downloaded, verified, baked into the
sourced `HistoricalReturns` class; CC BY-NC-SA flagged as a public-release blocker). Engine (`HistoricalSequenceDraws`
+ `HistoricalBacktester` + `RepresentativeDeathAge` extract; `5938612`) then the results panel (`2cad843`), suite
green (539). Care-cost + ONS-refresh source decisions locked (not built). Committed only my files; favicon/branding
edits left for their lane.

_2026-07-01 (Lane A — stress-test / care / ONS-refresh research)_ — Researched the three remaining Lane A backlog
items against Rob's "official source (ONS/FCA)" lean. Findings in
[docs/RESEARCH-stress-test-and-official-sources.md](docs/RESEARCH-stress-test-and-official-sources.md): the sector
standard for stress-testing is **historical sequence backtesting** (Timeline/DMS); the official, *shippable* UK
data source is the **Bank of England millennium dataset (OGL v3.0)** for returns + **ONS** for inflation (no ONS
return series exists; FCA gives methodology, not data). ONS-refresh is fully ONS; care-cost is only partly ONS
(fees = LaingBuisson, need-probability/duration = PSSRU). Two source decisions surfaced to Rob; nothing built yet.

_2026-07-01 (Lane A — v2 annuitisation)_ — After re-reviewing the tree (Lane B had shipped the whole
forced-housing workstream + a big estate-inheritance correctness fix, and reworked the sliders; suite green at 526),
built the next Lane A backlog item: **annuitisation**. Engine first (`AnnuityPurchase` DTO on `DcPension`;
`PathProjector` buys once, pays escalating survivor-aware income via the existing `other_taxable` source — commit
`7bcbede`), then the **builder** toggle + assembler mapping + sparse storage (`d85f5bf`), then docs. Rate is a
user input (default a sourced ~7.2%), so no fabricated rate table. Suite green throughout (532 tests). Respected the
lane split: committed only my files, left Lane B's committed docs untouched and the in-flight favicon/branding edits
alone. **Next Lane A:** stress-test (still gated on sourced historical data), ONS-refresh, care-cost assumptions.

_2026-06-30 (multi-agent coordination — two concurrent sessions)_ — Spotted that this tree has a **second active
session**: uncommitted edits to `DECISIONS.md` / `docs/PLAN.md` / `PRD.md` / `DATA-MODEL.md` recorded a new
**forced-housing-event workstream** (the "V2" real-couple pressure-test → means-tested benefits in the forecast, a
mortgage-redemption event, feasibility flags, input clarity) that this session did not author. Left those four files
untouched (Lane B's in-flight work), and updated HANDOVER to make both lanes visible and add a loud
**Multi-agent coordination** section + commit rule (claim your lane, commit only your lane's files, re-check git first).
Committed HANDOVER only. See [[concurrent-session-split]].

_2026-06-30 (post-v1 builds: what-if sliders + retirement-year salary proration)_ — On Rob's "those recommendations
look good, do them", started working through the approved backlog. Built **retirement-year salary proration** (engine:
`PathProjector::workFraction` — salary + NI = birth-month ÷ 12 in the year they turn the retirement age, instead of the
whole year being dropped) and the **what-if sliders** ("Explore the levers" panel: retire/spend/return/longevity, a live
throwaway deterministic re-forecast, never saved). Both committed, suite green. **Remaining approved builds (next):**
v2 annuitisation (rate as a user input defaulted to a sourced ~7.2%, reusing DB-income machinery + a pot-reduction
event), the stress-test panel (gated on authoritative sourced historical sequences — must be real, not fabricated), and
the ONS-refresh script (needs the ONS source) + care-cost assumptions. Paused here rather than rush two more big
trust-critical engine/data features at the tail of a very long session.

_2026-06-30 (per-year surplus/shortfall + safety floor; salary-at-retirement finding; stress-test & v2 research)_ —
On Rob's direction clarifying the "diagnostics" item, built the concrete thing he wanted: the cashflow ladder now
classifies each year **surplus / drawing / shortfall** on usable money and flags any year usable funds fall below a
**configurable safety buffer** (default 2 months' essentials, set in the Spending step). Reported the
**salary-at-retirement** finding (engine pays salary while `age < plannedRetirementAge`, dropping the whole retirement
year — conservative; true mid-year proration needs a retirement-*month* input). Then **researched** the remaining items
Rob asked about (sources below) and reached the decision wall — each now needs a data-source/UX/assumptions call from him
(see What's next #2): stress-test = historical-sequence backtesting (worst UK case **1973–74**: FT30 ≈−75%, gilts ≈−50%
real; UK SWR ≈3.7% Morningstar) needing a data source; annuitisation ≈**7.2%** level joint at 65 (RPI ≈35–40% lower);
care ≈**£1,300/wk** residential / **£1,600/wk** nursing self-funder (LaingBuisson 2025/26). Suite green.
**Sources:** [bellavia historical stress-test](https://bellavia.app/insights/what-if-you-retired-right-before-1929-1973-2000-2008-a-historical-analysis/) ·
[UK SWR / Morningstar](https://retirementexpert.co.uk/pension-drawdown/safe-withdrawal-rate) ·
[Which? annuity rates](https://www.which.co.uk/money/pensions-and-retirement/accessing-your-pensions/annuities/annuity-rates-aQGfH6W5n2rm) ·
[Retirement Line annuity tables](https://www.retirementline.co.uk/annuities/annuity-rates/) ·
[LaingBuisson care costs (payingforcare)](https://www.payingforcare.org/how-much-does-care-cost/).

_2026-06-30 (post-v1 backlog: source-freshness guardrail)_ — On "keep going until something needs direction", after the
longevity distribution built the one remaining clearly-no-direction backlog item: a **`figures:freshness`** command +
pure `App\Finance\FigureFreshness` that flags tax-year figures verified more than `--months` ago (default 12), exiting
non-zero for CI. Built as a command (not a date-dependent test); the arithmetic is unit-tested against a fixed date.
**Then stopped:** every remaining backlog item needs Rob's direction — neutral-diagnostics *definitions* (withdrawal /
replacement rate on murky data), the stress-test panel's *historical data* sourcing, what-if-slider *UX*, v2
annuitisation + care-cost *modelling assumptions*, the ONS-refresh *data source*, and whether the audit-only tamper-hash
/ forecast-caching are worth the complexity. Suite green.

_2026-06-30 (longevity distribution — first post-v1 backlog item)_ — After the CGT work + the handover save, on Rob's
"continue", started the post-v1 backlog with the cheapest high-value output: a **longevity distribution** from the Monte
Carlo. The joint-life sampler already drew per-path death ages (only used for cashflow); the `Simulator` now also
captures the **last-survivor** age + year per path and summarises them into a `LongevityDistribution` on
`SimulationResult` (age p10/p50/p90, planning-horizon years p50/p90, P(reach 95 / 100)). Mapper serialises it
(back-compat: old runs → null). A neutral **"How long the money may need to last"** panel on the results page (+ nav
entry), framed around the last survivor. Engine test pins it via fixed-age longevity (deterministic); mapper test covers
round-trip + the pre-longevity null. Suite green; assets rebuilt. **Pending Rob's browser sign-off** (needs a completed
run — local DB has 0).

_2026-06-30 (CGT on selling a let former home — partial PRR, with an input wizard)_ — On Rob's ask ("remodel the
capital gains based on owned-and-lived-in vs owned-and-let, and what years; research the rules + build a wizard"),
researched and confirmed the rules against **gov.uk HS283 / tax-sell-home** (links in DECISIONS): PRR is time-
apportioned, the **final 9 months** are always relieved, **lettings relief is shared-occupancy only since April 2020**,
and **CGT is per-individual** (each owner their own £3,000 allowance + rate). The engine already had a sourced
`CgtPrivateResidenceCalculator` doing the apportionment — it was just hard-coded to £0 in the sale and had no inputs.
Built: a `CgtHistory` DTO on `Property`; extended the calculator for **joint owners** (two allowances); wired it into
`HousingComparison::saleProceeds` (gain = sale − purchase − improvements − selling costs; null history = full PRR / £0,
so nothing else changes); a **"Capital gains on sale" wizard** under the home (revealed when ever-let) capturing
purchase price, year bought, costs, joint/higher-rate toggles and a **lived-in vs let period timeline**, with a live
readout (years owned/lived-in/let, relieved %, estimated CGT) reduced through the same `HouseholdAssembler::cgtHistoryFrom`
the forecast uses; the sale waterfall shows the CGT working. Confirmed for Rob that **mortgage type is irrelevant to
CGT — occupation is what counts** (living in a home on a BTL mortgage is still main-residence for those months).
Sparse storage (no `cgtHistory` delta when not ever-let) keeps what-ifs clean. Suite green; assets rebuilt. **Pending
Rob's browser sign-off.**

_2026-06-30 (what-ifs can add & remove items; personal-use advice mode + buy-vs-rent narrative; whole adviser-legibility build)_ —
A long build session resumed from the handover. Built the editable-assumptions UI (live preview, modelled death age,
selling-cost %/£ breakdown, per-line cost-condition override, per-line include/exclude toggle), the **one-click
buy-vs-rent compare** (delta-child strategy what-ifs + per-variant Compare projection + a walled-off advice "why"
narrative), and switched on **personal-use advice mode** behind the flagged `config('compliance.personal_use')` line.
Then, on Rob's report that the "a what-if only changes values" refusal "made no sense" (it blocked adding a one-off
**mortgage-deposit** cost to a stay what-if), **lifted the value-only restriction**: `BuilderStateDelta` now represents
**added rows** (stored whole at the id path) and **removed rows** (a `REMOVED` sentinel); `merge`/`setPath` append adds
and splice removals while a *leaf* override to a deleted row stays a flagged orphan (so orphan detection holds); dropped
`structurallyDiffers` + the save refusal; `WhatIfChanges` shows an add/remove as one line; the builder highlight pairs
base rows by id (not index) so add/remove/reorder no longer mis-highlights. Suite green throughout; assets rebuilt.
**All pending Rob's browser sign-off.**

_2026-06-30 (built the editable-assumptions UI: live preview, longevity readout, selling-cost %/£ breakdown, cost-condition override)_ —
Resumed from the handover and built the planned editable-assumptions slices in order, committing each green. **(a) Live
in-builder preview:** a sticky panel runs one cheap deterministic forecast on a transient scenario from the current
form-state (never saved), recomputed each round-trip, headlining the does-the-money-last **verdict** + **spendable/total
wealth at end** (Rob chose verdict + end wealth); it invites completion while the inputs aren't forecastable. Refactored
so one forecast per round-trip feeds both the panel and **(b)** the per-person **modelled age at death** beside each
lifespan lever (`ForecastResult::deathCalendarYears`). **(c) Selling costs → per-component %/£ breakdown:** Rob's steer
("some are %, some flat — that's how the world works") drove a `SellingCostComponent` (`Percent|Money`) summed in
`saleProceeds`, a reconciled `HousingProceeds` breakdown (sum == total, asserted), 2% default preserved, legacy
`sellingCostRate` back-compat (total preserved, never co-persisted); the builder edits three defaults each with a %/£
toggle, the sale waterfall + assumptions panel show each line + basis. **(d) Per-line cost-condition override:** an
"Applies" control (Auto/Always/while-owning/while-working) completing option (b), Auto hint single-sourced from
`HouseholdAssembler::autoCondition()`. **(e) Per-line include/exclude toggle (#7):** Rob chose a **persisted** toggle —
an "Include this cost" checkbox switches a line off (kept but counts £0, row dimmed, live preview moves); the assembler
drops excluded lines once in `household()` so every total excludes them, and the flag is stored **sparsely** (only when
off) so a what-if that changes nothing records no spurious delta (fixed a `ScenarioChildTest` regression that way).
Suite green throughout; assets rebuilt. The editable-assumptions workstream (a–e) is complete. Then, on Rob's choice of
the **one-click "compare buy vs rent"** framing, built that mechanism: a button (`BuyVsRentCompare` + `BuyVsRentController`)
generates the meaningful alternative strategies as **variant-only delta-child what-ifs** (idempotent on repeat clicks)
and opens **Compare** — and **fixed Compare to project each plan on its own variant** (`deterministicVariants[plan.variant]`,
the #6 source) instead of the raw stay-put `deterministic()` basis, without which the buy/rent columns showed identical
numbers under different labels. Then, on Rob's steer ("flag the education line so we can come back later; for now give
the best advice for personal use, not public"), **switched on personal-use advice mode**: a single documented switch
`config('compliance.personal_use')` (default true) makes the `interpret` Gate allow everyone, and the buy-vs-rent
**"why" narrative** (`Interpretation::compareNarrative`, in the walled-off advice layer) ranks the compared plans and
says which to lean towards. The regulatory line is flagged in one findable place (config + Gate + CLAUDE.md + DECISIONS)
to flip false before any public release; the suite runs with it false so the guidance-only guard stays tested.
**Adviser-legibility workstream complete.** All pending Rob's browser sign-off.

_2026-06-30 (the builder highlights a what-if's changed inputs + shows the base value)_ — On Rob's ask ("the input
that differs from the base should be highlighted", his example an edited annual rent; then "would be good to see the
original figure we diverged from"), the builder now, when editing a what-if, **rings every input whose value differs
from the base in amber and shows the base value it diverged from** ("was £18,000"), with a one-line banner.
`ScenarioBuilder::changedFromBase()` computes a `path => formatted base value` map — a **positional** diff of the live
`builderState()` against the base's `effectiveBuilderState()`, **index-based** so the keys match each input's
`wire:model`; values formatted by the shared `WhatIfChanges::formatValue()`. It renders on the `<form>`
(`data-builder-diff` + a `data-changes` object); a bundled `resources/js/builder-diff.js` matches inputs by their
`wire:model` path, rings each (`.builder-diff-changed`) and shows its base value via the field wrapper's `::after`
from a `data-original` attribute (**not an injected node**, so morph-safe). **Why one script, not ~70 annotated
inputs:** the wizard has ~70 `wire:model` inputs; one server-computed map + one script covers them all uniformly, is
CSP-safe (data attribute + bundled script) and morph-aware (re-applied on the Livewire `commit` hook, like `toc.js`).
Pure progressive enhancement (a highlight carries no data, so JS-only is the right call). The diff is positional
because a what-if child can't reorder/add/remove rows (the delta rule), so indices align with the base; name/step are
excluded. On opening an existing what-if the changed fields show on load; live-as-you-type follows the deferred
`wire:model` round-trips. Livewire-tested (childMode maps `housing.annualRent` to `£18,000` + renders the hook; a base
does neither). Suite green; assets rebuilt.

_2026-06-29 (one-click "quick what-ifs": retire later / live longer)_ — On Rob's ask (prompted by the new what-if
highlighting), added **preset what-if buttons** — **"Retire 2 years later"** and **"Live 10 years longer"** — on the
base's results page and each dashboard base row. Each POSTs to a new `QuickWhatIfController` → `App\Forecast\QuickWhatIf`,
which edits the base's people (retire-later bumps each *working* person's `plannedRetirementAge` +2, clamped to the
builder's 50–80; live-longer moves each person onto a +10-year `offset_years` longevity lever, relative to whatever the
base already models) and stores the result as an **ordinary delta-child computed through `BuilderStateDelta::diff`**.
That diff-through-the-base choice is load-bearing: the delta is automatically minimal and structurally identical to the
base, so a generated what-if is byte-for-byte the same shape as a hand-built one (it shows its changes via
`WhatIfChanges`, compares and edits identically). A preset that would change nothing (a lone retiree for "retire later")
builds and creates nothing and says so; repeats get distinct names ("… (2)"); the endpoint is owner-scoped. First UI use
of the per-person longevity lever (the editable-assumptions plan will surface it directly too). Service + endpoint + the
button-rendering covered by tests. Suite green; assets rebuilt. **Pending Rob's browser sign-off.**

_2026-06-29 (what-ifs highlight what they changed from the base + dashboard tags)_ — On Rob's ask, a delta-child
what-if now **shows its `overrides` as readable changes**: a "What this what-if changes" **panel** at the top of the
what-if's results page (each change **base → new**, a "what-if of <base>" header line, and the orphaned-override
notice), compact **change tags** on each what-if row in the **dashboard**, and per-plan **change chips** in the
**Compare** table. One presenter `App\Forecast\WhatIfChanges` maps the sparse override delta to `{label, from, to}` —
the base value an override replaced is read back via a new **`BuilderStateDelta::valueAt()`** (the read mirror of
`setPath`, descending maps by key and row-lists by stable id), each dot-path humanised (top-level fields,
assumption/housing/property figures, and **list rows named by their own label/identity** — "Essentials · amount",
"DC pension · current value", "P1 · gross salary"), money/rate/enum formatted, meta fields (name/step) excluded. It
is a **pure projection of the existing delta** (no new store — one home per fact, so it can't drift and a base edit
flows through). Tested: `WhatIfChanges` + `valueAt` units, plus Livewire assertions that the dashboard tag, the
Compare chip and the results panel render. Suite green; assets rebuilt (more `amber-*` utilities). **Pending Rob's
browser sign-off.**

_2026-06-29 (built the editable-assumptions core: a user-derived custom set from a sourced preset)_ — Resumed from
the handover and built the planned next step, the first slice of "everything user-editable". The six **economic
assumptions** the read-only panel already surfaces are now **editable on builder step 1**, each defaulting to the
chosen preset (placeholder + named hint) and deriving a **custom set**. Stored as a **sparse `assumptionOverrides`
delta** in `builder_state` (only changed figures; an empty box keeps following the preset, so a re-source flows
through; the key is omitted when empty so a what-if child records no spurious delta — and overriding an assumption
in a child composes for free via `BuilderStateDelta`). Engine: pure immutable `AssumptionSet::with*` derivations —
**`withRealReturnShift`** applies an "investment growth = X%" edit as a uniform shift across the asset classes so the
blended-real return lands on X under *any* allocation **without diverging** the per-class Monte Carlo (volatility /
correlations untouched: the user edits return, not risk). App `AssumptionOverrides::apply()` overlays the delta, in
the **single** place `ScenarioForecaster::assumptions()` — so the deterministic forecast, the per-variant ladder, the
Monte Carlo *and* the frozen run snapshot all share the customised set. Results panel labels a tuned set
**(customised)** and marks each **user-set** figure. Loose validation bounds keep an obvious typo out without
blocking a deliberate stress test. **v1 gotcha (recorded in DECISIONS):** a Blade **block `@php … @endphp`**
mis-compiles when the file already has an inline **`@php(...)`** — Blade's non-greedy raw-block regex pairs the
inline `@php` with the block's `@endphp`, leaving the opening `@php` literal + a stray `?>` (a parse error far away);
fix = keep view metadata in the component, not a `@php` block (sibling of the "`@if` glued to a word" trap). Suite
green; assets rebuilt (new `amber-*` utilities). **Pending Rob's browser sign-off.** Next: live in-builder preview,
then the longevity-lever UX → cost components → the per-line cost-condition override UI.

_2026-06-29 (built #6: per-variant cashflow ladder + a results-page "on this page" nav)_ — Resumed from the
handover and built the planned next step. **`ScenarioForecaster::deterministicVariants()`** runs each housing
strategy through `DeterministicForecaster` on the variant household + settings from
**`HousingComparison::variantInputs()`** — the same single source the Monte Carlo comparison runs, so the
deterministic ladder and the simulated comparison can't drift (`stay_put` is byte-identical to `deterministic()`).
The results page gained a **strategy selector** (`ScenarioResults::$ladderVariant`, default = the scenario's own
variant) driving the cashflow ladder + its milestones; chose a **switch over side-by-side** (the table is too wide)
and offer only meaningful strategies (stay-put always; buy with a buy price; rent when a sale is set). The
**house-sale milestone** landed (year 0, household-level, no per-person age, `milestones(..., homeSold:)`); the
**PDF** ladder follows the scenario variant too (same stay-put-only bug). Provenance (panel == CSV == PDF) holds on
the *selected* variant; income-floor / input-sanity notes stay on the raw (stay-put) projection. Then, on Rob's
browser feedback that the page is long, added a sticky **"on this page" side nav** (a 2-col grid on `lg+`, hidden on
mobile): real anchor links built from only the present sections (one source — the same flags they render under),
with a CSP-safe `IntersectionObserver` scroll-spy (`resources/js/toc.js`) + a defensive Livewire `commit`-hook
re-init. **Rob verified the nav on desktop ("looks good"); mobile deferred.** v1 note found + fixed in passing:
**Blade silently doesn't compile an `@if` glued to a word** (`cashflow@if …`) — it leaves the directive literal
while compiling its `@endif`, a parse error; don't glue `@if` to a word. Suite green; provenance tests updated for
the deliberate stay-put → selected-variant basis change. **Next: the editable-assumptions layer.**

_2026-06-29 (built #1: contingent-cost placement, option b — the correctness fix)_ — An expense line now carries a
**condition** (`always` / `while_owning_home` / `while_working`), **auto-classified by label** (mortgage / service
charge → while-owning; commute / season ticket → while-working) with an explicit per-line override honoured first.
**`ExpenseProfile`** gains `propertyCosts` / `employmentCosts` markers (a subset of essential — no drift) +
`withoutPropertyCosts()`; **`HousingComparison`** exposes **`variantInputs()`** (the single source of the three
variant households — `compare()` runs it now, the #6 ladder will too) and its **sell variants strip property costs**,
killing the phantom-mortgage bias; **`PathProjector`** drops the commute in years no one earns (`anyoneWorking()`),
so it stops at retirement in every variant; **`HouseholdAssembler`** does the label classification; **PLSA** excludes
property costs too (outright-ownership basis). Reconciliation-tested (property costs only in stay-put; the commute
falls by its full £ at retirement; auto-classify + override + saved-exclusion + PLSA-exclusion). v1 flags: the
commute stops when the *last* earner retires (not tied to a specific commuter); contingent costs treated as
essential; a lifelong-*single* household's spend is survivor-scaled every year (pre-existing oddity, noted, out of
scope). **Builder override UI still to build** (folds into the editable-assumptions layer; auto-classification gives
the defaults today). Suite green. **Next: the per-variant deterministic ladder (#6)** via `variantInputs()` → it
*shows* the corrected per-strategy numbers + lands the house-sale milestone.

_2026-06-29 (Rob's browser pass → the "everything user-editable" direction + research — folded; detail in DECISIONS.md + docs/RESEARCH-editable-assumptions-ux.md):_
fixed a leaked Blade `@if`-glued-to-a-word on the sale waterfall (`1f544bd`) and set the direction that reshaped the
rest of the workstream: #1 contingent costs via **option (b)** (auto-classify by label + per-line override); **all
assumptions user-editable** (sourced presets deriving a custom set); **buy-vs-rent as a deliberate what-if/Compare**;
costs as real figures with a breakdown. Backed by research into the free tools (Boldin, ProjectionLab, NYT rent-vs-buy,
Guiide, the Actuaries Longevity Illustrator) — the universal pattern being sensible sourced defaults + every assumption
overridable + live update.

_2026-06-29 (adviser-legibility presentation layer + the browser walkthrough that birthed it — folded; detail in `git log` + DECISIONS.md):_
Rob's browser walkthrough of the real couple found **no engine bug** (the swings were live-edit foot-guns: a
retirement age ≤ current age zeroing salary, a longevity offset clamped to the base year); the real find was
**contingent costs charged in every housing variant** (a phantom mortgage/commute biasing buy-vs-rent), plus a
20%-not-2% selling-cost entry and a monthly-as-annual rent — totals reading plausible while wrong. His framing
("I can't trust numbers the output hasn't explained") set explainability above go-live polish. Built the **explainer /
show-your-working layer**: the house-sale waterfall (`saleExplainer` + the reconciled engine `HousingPurchase`), the
assumptions panel (real-vs-nominal labelled, single-source blended return), itemised per-year spend, **life-event
milestones** (+ the single-source engine field `ForecastResult::deathCalendarYears`), and **input-sanity notes**.

_2026-06-29 (results charts reworked + queued-run hint — folded; detail in `git log` + DECISIONS.md):_ reworked the
fan + comparison charts to default to **spendable money (excl. home)** with an include-home toggle, replaced the
terminal-wealth bar with a per-strategy **over-time** line, anchored the fan at £0, added £-abbreviating + person-age
axis formatters (`charts.js`), and a thin-tail explainer for the end-of-life rise; engine gained a per-year
`usableFanChart` (with a `usable ≤ total` reconciliation test) + a stale-run re-run prompt. **Rob signed these off.**
Also the queued-run **"waiting for a worker" hint** (`SimulationRun::isAwaitingWorker()`) so a run with no worker
explains itself instead of sitting at 0%.

_2026-06-28 (go-live polish + parked work — detail in `git log` + DECISIONS.md):_ the **five re-review findings
resolved** (PDF/screen Monte-Carlo one-source via `Scenario::latestCompletedRun()`; `freezeEndYear` threshold
un-freezing; GIA-disposal basis conservation; `medianDepletionYear` to all surfaces; cash-interest conservation);
the **fan-chart blank-render fix** + the Compare **wealth-over-time burndown**; the per-option run-out **verdict**;
the **handover consolidation + doc-hygiene guard** (`HandoverHygieneTest`, CLAUDE.md "Doc hygiene" + "Green is the
invariant"); and the **parked** statement-driven **document-import** design (deterministic transfer-matching core,
an LLM only as a walled-off LOCAL assist — the £1,258 internal-transfer double-count is a rules job, not an LLM one).

_Earlier sessions (the engine build; app Phase 2 steps 1–4; rebuild Phases A, C3, B, C2, C1, C4; Phase D — A5, the
gov.uk figure-verification pass, the admin-panel lockdown, the Tier-1 data-integrity guardrails, and the Tier-2
items: demo seeder, 10k perf, CSP headers, 2FA UI, PDF export, a11y scaffold) — see `git log` and DECISIONS.md for
the dated detail._
