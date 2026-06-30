# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue building the calculation engine and then the app around it. Read `docs/PLAN.md` first: it is the full approved plan and the source of truth for scope.

**Stage:** active
**Status:** Phase D go-live. The **adviser-legibility workstream** is the active priority (from Rob's 2026-06-29 browser walkthrough). Built so far: the legibility **presentation layer** (house-sale waterfall, assumptions panel, itemised per-year spend, life-event milestones, input-sanity notes), the **contingent-cost correctness fix (#1, option b)**, the **per-variant deterministic cashflow ladder (#6)**, the **editable-assumptions layer's *core*** (the six economic assumptions editable on builder step 1, deriving a user-tweakable **custom set** stored as a sparse `assumptionOverrides` delta, applied once in `ScenarioForecaster::assumptions()`), and a **what-if legibility cluster** (a what-if highlights what it changed vs its base on the results page + dashboard tags + Compare chips; one-click **quick what-ifs**; the builder **rings + shows the base value** of each changed input). The **editable-assumptions layer is now built**: the **live in-builder preview** (verdict + end wealth), the **longevity-lever UX** (the modelled age at death shown beside each lever), selling costs **decomposed into per-component %/£ lines**, and the **per-line cost-condition override UI** (#1's remainder). **Remaining in the workstream:** (e) **real-time cost toggles (#7)** — scope is an open decision (partly subsumed by the live preview, see What's next), then **buy-vs-rent as a deliberate what-if/Compare**. See What's next + docs/PLAN.md + DECISIONS 2026-06-29/30 + docs/RESEARCH-editable-assumptions-ux.md.
_Last updated: 2026-06-30 (built the editable-assumptions UI slices: **live preview** verdict+end-wealth, **modelled age at death** by the lifespan lever, **selling-cost %/£ component breakdown**, and the **per-line cost-condition override**. All pending Rob's browser sign-off. Next decision: what (e) real-time cost toggles should be.)_

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
- **Storage inversion (Phase B):** a scenario stores the raw builder **form-state** (`builder_state`, one `encrypted:array`) as the single source of truth; the engine `Household` + `HousingAction` DTOs are **derived** from it (`Scenario::toHousehold()`/`toHousingAction()` via `HouseholdAssembler`, no reverse-mapper). A what-if **child** (Phase C2) holds no `builder_state` — only `parent_scenario_id` + a sparse encrypted `overrides` delta; `effectiveBuilderState()` = base ⊕ overrides via `App\Forecast\BuilderStateDelta`. Clear columns are a projection. (The pre-rebuild `households`/`scenario_drafts` tables were dropped.)

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
- **Regulatory posture: education/guidance only, never a personal recommendation** — enforced by the `BannedPhrasingTest` partition lint; signpost Pension Wise / MoneyHelper. The advice-style **interpretation** toggle is admin-granted, off by default, walled-off behind an `interpret` Gate.
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
- **Done — editable-assumptions layer UI (2026-06-30, pending Rob's browser sign-off):** (a) a sticky **live in-builder preview** running one cheap deterministic forecast on a transient scenario from the current form-state (never saved), recomputed each round-trip, headlining the does-the-money-last **verdict** + **spendable/total wealth at end**, inviting completion while the inputs are too incomplete to forecast (single-sourced from `ScenarioForecaster::deterministic()`, server-rendered, CSP-safe); (b) the **modelled age at death** shown beside each person's lifespan lever (from `ForecastResult::deathCalendarYears`, same forecast), so "peer/+10 years" resolves to a visible age; (c) **selling costs decomposed into per-component %/£ lines** — engine `SellingCostComponent` (`Percent|Money`) summed in `saleProceeds` with a reconciled `HousingProceeds` breakdown; default 2% preserved when none; legacy `sellingCostRate` back-compat (total preserved); builder edits three default components (agent 1.25%, legal £1,500, EPC & removals £800) each with a %/£ toggle; (d) the **per-line cost-condition override** ("Applies": Auto / Always / while-owning / while-working) completing option (b), with an Auto hint from `HouseholdAssembler::autoCondition()`.
- **In progress:** nothing mid-edit; tree clean.
- **Known bugs / broken:** none open (the five 2026-06-28 re-review findings are all resolved — see Session log + docs/PLAN.md "Review findings"). Documented v1 scope limits, all flagged in code: income tax England/Wales/NI only (Scotland throws); emergency tax models the over-deduction magnitude, not PAYE-table pennies; mortality grid ages 50–100 / years 2025–2074 with clamping + a non-ONS tail above 100 (cap 110); forecast taxes GIA dividends + cash interest annually AND realises CGT on GIA disposal (ISA tax-free; GIA/cash grow at capital only; v1 omits capital-loss relief + judges the CGT band on non-savings income); income-tax thresholds frozen until 2031, then indexed with inflation; DB escalation + triple lock as smooth growth factors; buy-vs-rent takes main-home CGT as £0 (PRR) and no SDLT surcharge; house/salary growth deterministic inside the Monte Carlo.

## What's next (in order)
The go-live critical path. Longer-tail and parked work is under Open items, not repeated here.
1. **Adviser-legibility workstream** — the priority from Rob's 2026-06-29 browser walkthrough (full detail: docs/PLAN.md "Adviser-legibility workstream (2026-06-29)"; decisions: DECISIONS 2026-06-29). None of it is an engine bug (determinism + mortality re-verified); it is cost placement + missing explanation. **Guiding principle (Rob): trust comes from explanation — every headline figure must be traceable on screen to its inputs/assumptions, so this sits above remaining go-live polish.** The **explainer / show-your-working layer is built (2026-06-29, pending Rob's browser sign-off):** the house-sale waterfall (proceeds decomposition + per-option destination, selling-cost rate shown beside the £), the assumptions panel (real-vs-nominal labelled, single-source blended return), itemised per-year spend (essential/discretionary), the **life-event milestones** timeline (when each person retires / their State Pension starts / takes a pension / dies — death from a new single-source engine field `ForecastResult::deathCalendarYears`; the house-sale marker landed with the per-variant ladder, #6), and **input-sanity notes** (a heads-up when an input did something drastic — no salary from a retirement age at/below current age; a death floored to the base year) on the results page — see DECISIONS. The **#1 contingent-cost correctness fix is also built (2026-06-29, option b):** an expense line carries an auto-classified condition (mortgage / service charge → *while owning the home*; commute → *while working*; explicit override honoured first), so the **sell variants no longer pay a phantom mortgage/service charge** and the **commute stops at retirement** (engine + `HouseholdAssembler`; `HousingComparison::variantInputs()` is the new single source of the variant households; PLSA now excludes property costs too) — reconciliation-tested. The **per-line override UI is now built** (the "Applies" control, 2026-06-30 — see sub-item 1). The **per-variant deterministic cashflow ladder (#6) is now built** too — a strategy selector runs `HousingComparison::variantInputs()` through `DeterministicForecaster`, so the ladder + milestones show the corrected per-strategy numbers and the house-sale milestone landed (see Current state + DECISIONS). The whole results page is browser-reviewed on desktop (the "on this page" nav signed off); **mobile is deferred**. See Current state + DECISIONS. Remaining, in order:
   1. **Editable-assumptions layer ("everything editable" — Rob's direction).** The **core + the UI slices (a)–(d) are built** (2026-06-29/30, all pending Rob's browser sign-off): the six **economic assumptions** editable on step 1 deriving a custom set (`assumptionOverrides` delta applied once in `ScenarioForecaster::assumptions()`); (a) the **live in-builder preview** (verdict + end wealth); (b) the **longevity-lever UX** (modelled age at death beside each lever); (c) **selling costs decomposed into per-component %/£ lines** (Rob: some quotes are %, some flat); (d) the **per-line cost-condition override UI** ("Applies": Auto/Always/while-owning/while-working). See Current state + DECISIONS 2026-06-30. **Remaining — (e) real-time cost toggles (#7): an open decision.** The live preview already updates on every edit (so "real-time" is largely delivered); what (e) adds is an *include/exclude toggle* per cost line (explore "what if I drop this cost?" without deleting it) — but its scope and persistence semantics (preview-only vs saved) need Rob's steer before building. Patterns + free-tool references: docs/RESEARCH-editable-assumptions-ux.md.
   2. **Buy-vs-rent as a deliberate what-if / Compare** (reuse the delta-child + Compare infrastructure), not baked into every report; + the per-option plain-English **"why"** narrative, milestone-anchored, lint-safe.
2. **Finish the real-browser verification pass.** The a11y axe/Lighthouse sweep is underway — one finding fixed (the scrollable data-table wrappers are now keyboard-focusable via `tabindex="0"`; see docs/A11Y.md sweep log). PDF layout looked right to Rob; **2FA QR scan deferred by Rob**. The results-page **"on this page" nav is verified on desktop; the mobile view (nav hidden below `lg`) is deferred** to later in the dev timeline. NB the local DB has **0 completed runs**, so re-run a forecast before checking the Monte Carlo charts / PDF.
3. **Optional:** tighten the CSP `script-src` to nonces (Alpine CSP build) — needs the browser.

## Open items
Open decisions and parked work, off the immediate go-live path (which is under What's next).
- [ ] **Spreadsheet import** — the line-item expense-categories data-model decision; re-verify IWT CSP vs the real 2023 export (the fixture was built from a masked dump); Nischa deprioritised (`isAvailable()=false`); imported income → Person 1, no start age (by design, flagged). Real sample `.xlsx` live in gitignored `docs/*.xlsx` (never commit).
- [ ] **Assumption-set figures** are not numerically editable in Filament (curate-metadata-only; figures seeded from the engine library — editing one means re-sourcing with a new verified-on date). **Now subsumed by the "everything user-editable" direction** (DECISIONS 2026-06-29 + What's next #3): assumptions become editable in the **main UI** as a user-derived *custom set* from the sourced presets, not in Filament.
- [ ] **ONS mortality + FCA/DMS assumption *sources*** sit at their 2026-06-24 sign-off (docs/ASSUMPTIONS.md, docs/MORTALITY.md) — a separate review from the gov.uk statutory-figure pass; the `investmentIncomeYield` 2% is a reviewed-and-kept modelling assumption.
- [ ] **Demo couple's anonymised figures** — Rob supplies later, entered via the UI, not hardcoded (field list in docs/PLAN.md "Data Rob supplies").
- [ ] **External-review enhancement backlog** (post-v1, not blocking) — docs/PLAN.md "External review triage" (cashflow timeline, longevity-distribution visual, stress-test panel, what-if sliders, v2 annuitisation + care-cost stochasticity). Declined items recorded in DECISIONS 2026-06-25.

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
If `vendor/` is missing: `composer install`. If engine classes are not found, re-register the path package: `composer update retireforecast/finance-engine`. To use the app locally: **Herd already serves it at `https://retireforecast.test`** — no `php artisan serve` needed locally (run `npm run build` after asset changes, as `public/build` is gitignored). `php artisan serve` is only needed where Herd is not running (e.g. CI). This flow works fine under the new CSP. **CSP note:** the `web` group ships a Content-Security-Policy (`config/security.php`); it does **not** include the Vite dev-server/websocket origins, so if you use HMR (`npm run dev`) set `SECURITY_HEADERS_ENABLED=false` in `.env` while developing, or `SECURITY_CSP_REPORT_ONLY=true` to stage it. **DB setup — IMPORTANT:** a local `database.sqlite` that predates the Phase B rebuild has a stale schema (the `create_scenarios` migration was **rewritten in place**, so `php artisan migrate` alone will NOT add `builder_state`). On any pre-rebuild DB, run **`php artisan migrate:fresh --seed`** (rebuilds from current migrations; `--seed` runs `DatabaseSeeder` → assumption sets + a `test@example.com` / `password` user). For a ready-made fictional sample, seed the **demo preset**: `php artisan db:seed --class=Database\Seeders\DemoScenarioSeeder` (idempotent; outside production it creates **`demo@example.com` / `password`** with a base plan + one what-if child; in production set `DEMO_USER_EMAIL` to an existing user). Register your own at `/register` and **accept the one-time guidance-only disclaimer at `/welcome`**. **Two-factor auth** enrols at **`/account/security`** ("Security" in the nav; behind a fresh password confirmation). Build a forecast at `/scenarios/create`, run it on its results page; download a **PDF summary** from there. The full queued run needs a worker (`php artisan queue:listen`); the preview does not. **Admin panel at `/admin` is gated on `is_admin`** (default false) — grant yourself once with `php artisan user:make-admin {email}` (e.g. `demo@example.com`), after which the Users resource toggles **Admin access** + `can_interpret`. Tests neutralise Vite, so they pass without a build.

## Sibling docs
| Doc | Purpose |
|-----|---------|
| docs/PLAN.md | The full approved implementation plan. Source of truth for scope, data model, tax rules, Monte Carlo design, phasing. Holds the "Sector-informed build plan (2026-06-25)". |
| docs/RESEARCH-cashflow-modelling.md | How the sector (Voyant/Timeline/CashCalc, PLSA/SMPI) solves edit/clone/compare, line-item expenditure, drill-down — what we adopt + the gaps it surfaced. |
| docs/RESEARCH-editable-assumptions-ux.md | How free consumer tools (Boldin, ProjectionLab, NYT rent-vs-buy, Guiide, Actuaries Longevity Illustrator) handle editable assumptions, buy-vs-rent and cost breakdowns — what we adopt (2026-06-29). |
| docs/RESEARCH-document-import.md | PARKED post-v1 feature: statement-driven onboarding + document import (sector evidence, document→builder-field map, gotchas). |
| PRD.md | Goal, success criteria, scope, non-goals, open questions. |
| DATA-MODEL.md | Canonical data shape; what is materialised in code today vs planned. |
| DECISIONS.md | Append-only decision log with rationale. |
| CLAUDE.md | Root orient tripwire + build/test conventions + "Doc hygiene" rules. |

## Branch status
On `master`. A GitHub remote exists (`origin` → github.com/RobertLCraig/RetireForecast). As of this save local `master` is **ahead of `origin/master` by this session's commits (the editable-assumptions core + the what-if legibility cluster), unpushed** — confirm the count with `git rev-list --count origin/master..master`. **Pushing to `master` is gated and needs Rob's explicit go-ahead — do not push unprompted.** Otherwise commit directly to `master` (personal local-first project; no PR flow). **Re-check `git status` / `git log` before any commit or push** (a second Claude session has shared this tree before; the tree can move under you). The pre-rebuild prototype is tagged **`prototype-v1` (a8f1f68)**, the only recovery snapshot. For the commit history use **`git log`** (the source of truth — not restated here, where it would drift); the recent trajectory is in the Session log + DECISIONS.md.

## Session log
_Newest first. Keep only the recent live window here; older sessions are in `git log` + DECISIONS.md. Per-session figures are dated history and may stay._

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
`HouseholdAssembler::autoCondition()`. Suite green throughout; assets rebuilt. **Stopped at (e) real-time cost toggles
— an open decision** (largely subsumed by the live preview; the remaining include/exclude-toggle scope needs Rob's
steer). All pending Rob's browser sign-off.

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
