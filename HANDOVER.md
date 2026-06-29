# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue building the calculation engine and then the app around it. Read `docs/PLAN.md` first: it is the full approved plan and the source of truth for scope.

**Stage:** active
**Status:** Phase D go-live. The **adviser-legibility workstream** is the active priority (from Rob's 2026-06-29 browser walkthrough). Its legibility **presentation layer is built** (house-sale waterfall, assumptions panel, itemised per-year spend, life-event milestones, input-sanity notes), the **contingent-cost correctness fix (#1, option b) is built**, the **per-variant deterministic cashflow ladder (#6) is built**, and now the **editable-assumptions layer's *core* is built** — the six **economic assumptions** are editable on builder step 1, defaulting to the chosen preset and deriving a user-tweakable **custom set** (stored as a sparse `assumptionOverrides` delta; applied once in `ScenarioForecaster::assumptions()`; results panel labels it *customised*). **Remaining in this layer (in order):** live in-builder preview, the longevity-lever UX, decomposed editable cost components, the per-line cost-condition override UI (#1's remainder), real-time cost toggles. Then **buy-vs-rent as a deliberate what-if/Compare**. See What's next + docs/PLAN.md + DECISIONS 2026-06-29 + docs/RESEARCH-editable-assumptions-ux.md.
_Last updated: 2026-06-29 (a **what-if now highlights what it changed from its base** — a "what changed" panel on its results page, change **tags** on the dashboard, and change **chips** in Compare, all from `App\Forecast\WhatIfChanges` reading the `overrides` delta back via the new `BuilderStateDelta::valueAt()`. Earlier this session: the **editable-assumptions core** — six economic assumptions editable on builder step 1, deriving a custom set via a sparse `assumptionOverrides` delta applied once in `ScenarioForecaster::assumptions()`. Both pending Rob's browser sign-off. Next: live preview + the longevity-lever UX.)_

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
- **Done — what-ifs highlight what they changed from the base (2026-06-29):** a delta-child what-if now surfaces its `overrides` as readable changes in three places — a **"What this what-if changes" panel** at the top of its results page (each change as **base → new**, plus a "what-if of <base>" header line + orphaned-override notice), compact **change tags** on each what-if row in the **dashboard**, and per-plan **change chips** in **Compare**. One presenter `App\Forecast\WhatIfChanges` turns the sparse override map into `{label, from, to}`: the base value an override replaced is read back via the new **`BuilderStateDelta::valueAt()`** (read mirror of `setPath`, row-id aware), each dot-path humanised (list rows named by their own label/identity, e.g. "Essentials · amount", "DC pension · current value"), money/rate/enum formatted, meta fields (name/step) excluded. A pure projection of the existing delta (no new store). Unit + Livewire tested. **Pending Rob's browser sign-off.**
- **Done — editable-assumptions layer, *core* (2026-06-29):** the six economic assumptions surfaced by the read-only panel (investment growth blended-real, CPI, house/rent/salary growth, income yield) are now **editable on builder step 1**, defaulting to the chosen preset (placeholder + named in the hint) and deriving a **custom set**. Stored as a **sparse `assumptionOverrides` delta** in `builder_state` (only changed figures; empty keeps the preset, so a re-source flows through; composes with delta-child what-ifs via `BuilderStateDelta`). Engine `AssumptionSet::with*` (immutable; `withRealReturnShift` lands the blended-real return on the target without diverging the Monte Carlo) + `App\Forecast\AssumptionOverrides::apply()`, applied in the **single** resolution point `ScenarioForecaster::assumptions()` (so deterministic + variants ladder + Monte Carlo + the frozen run snapshot all share it). Results panel labels a tuned set **(customised)** and marks each **user-set** figure. Reconciliation-tested (no overrides == the preset; an edit demonstrably reaches the forecast; the blend lands on target under any allocation). **Pending Rob's browser sign-off.**
- **Done — results-page "on this page" side nav (2026-06-29, browser-verified desktop):** a sticky 2-col grid nav on `lg+` (hidden on mobile), listing only the sections present this render (built from the same flags they render under) as real anchor links that work without JS, with a CSP-safe `IntersectionObserver` scroll-spy (`resources/js/toc.js`). **Mobile check deferred** by Rob to later in the dev timeline.
- **In progress:** nothing mid-edit; tree clean.
- **Known bugs / broken:** none open (the five 2026-06-28 re-review findings are all resolved — see Session log + docs/PLAN.md "Review findings"). Documented v1 scope limits, all flagged in code: income tax England/Wales/NI only (Scotland throws); emergency tax models the over-deduction magnitude, not PAYE-table pennies; mortality grid ages 50–100 / years 2025–2074 with clamping + a non-ONS tail above 100 (cap 110); forecast taxes GIA dividends + cash interest annually AND realises CGT on GIA disposal (ISA tax-free; GIA/cash grow at capital only; v1 omits capital-loss relief + judges the CGT band on non-savings income); income-tax thresholds frozen until 2031, then indexed with inflation; DB escalation + triple lock as smooth growth factors; buy-vs-rent takes main-home CGT as £0 (PRR) and no SDLT surcharge; house/salary growth deterministic inside the Monte Carlo.

## What's next (in order)
The go-live critical path. Longer-tail and parked work is under Open items, not repeated here.
1. **Adviser-legibility workstream** — the priority from Rob's 2026-06-29 browser walkthrough (full detail: docs/PLAN.md "Adviser-legibility workstream (2026-06-29)"; decisions: DECISIONS 2026-06-29). None of it is an engine bug (determinism + mortality re-verified); it is cost placement + missing explanation. **Guiding principle (Rob): trust comes from explanation — every headline figure must be traceable on screen to its inputs/assumptions, so this sits above remaining go-live polish.** The **explainer / show-your-working layer is built (2026-06-29, pending Rob's browser sign-off):** the house-sale waterfall (proceeds decomposition + per-option destination, selling-cost rate shown beside the £), the assumptions panel (real-vs-nominal labelled, single-source blended return), itemised per-year spend (essential/discretionary), the **life-event milestones** timeline (when each person retires / their State Pension starts / takes a pension / dies — death from a new single-source engine field `ForecastResult::deathCalendarYears`; the house-sale marker landed with the per-variant ladder, #6), and **input-sanity notes** (a heads-up when an input did something drastic — no salary from a retirement age at/below current age; a death floored to the base year) on the results page — see DECISIONS. The **#1 contingent-cost correctness fix is also built (2026-06-29, option b):** an expense line carries an auto-classified condition (mortgage / service charge → *while owning the home*; commute → *while working*; explicit override honoured first), so the **sell variants no longer pay a phantom mortgage/service charge** and the **commute stops at retirement** (engine + `HouseholdAssembler`; `HousingComparison::variantInputs()` is the new single source of the variant households; PLSA now excludes property costs too) — reconciliation-tested. The **per-line override UI is still to build** (auto-classification gives the defaults today). The **per-variant deterministic cashflow ladder (#6) is now built** too — a strategy selector runs `HousingComparison::variantInputs()` through `DeterministicForecaster`, so the ladder + milestones show the corrected per-strategy numbers and the house-sale milestone landed (see Current state + DECISIONS). The whole results page is browser-reviewed on desktop (the "on this page" nav signed off); **mobile is deferred**. See Current state + DECISIONS. Remaining, in order:
   1. **Editable-assumptions layer ("everything editable" — Rob's direction).** The **core is built (2026-06-29):** the six **economic assumptions** (investment growth, inflation, house/rent/salary growth, income yield) are editable on builder step 1, deriving a user-tweakable **custom set** from the chosen preset, stored as a sparse `assumptionOverrides` delta and applied once in `ScenarioForecaster::assumptions()` (see Current state + DECISIONS). **Remaining, in order:** (a) **live in-builder preview** (the builder is a wizard with no preview today — editing an assumption should update a cheap deterministic readout; nearest free-tool pattern: ProjectionLab); (b) the **age-of-death / longevity-lever UX** (surface the existing per-person lever + show the modelled death year `ForecastResult::deathCalendarYears`); (c) decompose **selling costs** into editable **cost components** (estate agent + legal/conveyancing + EPC/removals, each in £); (d) the **per-line cost-condition override UI** (#1's remaining piece — set each expense line to always / while-owning / while-working; the condition is already read from `builder_state`, only the control is missing); (e) **real-time cost toggles** (#7). Patterns + free-tool references: docs/RESEARCH-editable-assumptions-ux.md.
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
On `master`. A GitHub remote exists (`origin` → github.com/RobertLCraig/RetireForecast); as of this checkpoint local `master` **tracks `origin/master`** (prior work has been pushed — verify with `git rev-list --count origin/master..master` before assuming). **Pushing to `master` is gated and needs Rob's explicit go-ahead — do not push unprompted.** Otherwise commit directly to `master` (personal local-first project; no PR flow). A second Claude session has previously run concurrently in this tree; **re-check `git status` / `git log` before any commit or push** — the tree can move under you. The pre-rebuild prototype is tagged **`prototype-v1` (a8f1f68)**, the only recovery snapshot. For the commit history use **`git log`** (the source of truth — not restated here, where it would drift); the recent trajectory is in the Session log + DECISIONS.md.

## Session log
_Newest first. Keep only the recent live window here; older sessions are in `git log` + DECISIONS.md. Per-session figures are dated history and may stay._

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

_2026-06-29 (Rob's browser pass → fixes + a new "everything editable" direction + research)_ — Rob reviewed the new
explainer layer in the browser. **Fixed:** a Blade `@if` glued to a word (`price@if`) never compiled and leaked the
raw directive onto the sale waterfall — the selling-costs label is now built in `ResultPresenter::saleExplainer`
(`sellingCostsLabel`, test-guarded); named what the 2% covers (estate agent + legal/conveyancing); relabelled the
ambiguous "Rent" as the *projected cost of renting after selling* (not current rent), on the waterfall + assumptions
panel. Commit `1f544bd`. **New direction (DECISIONS 2026-06-29 "everything user-editable"):** (1) #1 contingent-cost
placement uses **option (b)** — auto-classify each expense line by category/label (mortgage/service charge → while-
owning; commute → while-working) with a per-line override; (2) **all thresholds/assumptions must be user-editable in
the UI** (investment growth, inflation, house/rent growth, **age of death**, cost components) — keep the sourced
presets as starting points that derive a custom set; (3) **buy-vs-rent becomes a deliberate what-if/Compare**, not
baked into every report; (4) costs shown as real figures with a breakdown. **Research** (Rob asked for free tools to
look at): [docs/RESEARCH-editable-assumptions-ux.md](docs/RESEARCH-editable-assumptions-ux.md) — Boldin, ProjectionLab,
the NYT rent-vs-buy calculator, Guiide (UK), the Actuaries Longevity Illustrator; the universal pattern is sensible
sourced defaults + every assumption overridable + live update, and we're already *ahead* on longevity (cohort
mortality + the lever + the on-screen death year). Proposed build order: #1 option-b → per-variant ladder → editable-
assumptions layer → buy-vs-rent as Compare. Docs-only this entry (after the `1f544bd` fix); the build is pending Rob's
confirmation of the sequence.

_2026-06-29 (adviser-legibility: input-sanity notes built)_ — Rounded out the legibility *presentation* layer with
**input-sanity notes** on the results page (`ResultPresenter::inputNotes` + an "A note on your inputs" box, placed
above the figures it affects): a neutral heads-up where an entered value did something drastic — (a) an employed
person whose **retirement age is at/below their current age**, so no salary is modelled; (b) a person **modelled to
die in the base year** (a longevity/health age below the current age, which the engine floors at the current age),
read from the new `ForecastResult::deathCalendarYears`. These are exactly the two live-edit foot-guns behind the
"wild numbers" Rob saw, now explained at the point of surprise. Factual/lint-safe; no notes when nothing is amiss.
Presenter test covers both cases + the no-noise case. **Still open from the plan's input-sanity item:** the rate/£
*builder-side* validation (live £-for-a-rate, out-of-range flag). This is the **third committed increment** of the
session (after the explainer layer and milestones); all three are local-only, pending Rob's browser pass. Next: the
correctness fix **#1 (contingent-cost placement)** + the **per-variant ladder (#6)** — bigger, engine + data-model
work that also needs Rob's input on the builder UX for tagging a cost's condition.

_2026-06-29 (adviser-legibility: life-event milestones built)_ — Continued the explainer layer with a **life-event
milestones** timeline on the results page (`ResultPresenter::milestones` + a "When the big events happen" section): a
dated, aged list of *when* each person retires, takes their first planned pension withdrawal, their State Pension
starts (SPA from the engine's `StatePensionAge`), and their modelled death — answering Rob's "what is the 2040
event?" by making the cashflow ladder's step-change drivers legible. The only engine change is a new single-source
**`ForecastResult::deathCalendarYears`** (personId → birthYear + death age, computed once in `PathProjector` from the
draws; additive field, default `[]`), so "when does each person die" is no longer buried in the projection;
everything else derives from existing inputs/helpers. Events outside the projection window are filtered out; the
**house-sale marker is deferred** to the per-variant ladder (a variant transform the raw-household ladder doesn't
apply). Guards: a presenter test (events / order / retired-person exclusion) + a death-year-is-the-engine-source
assertion. Assets rebuilt; **pending Rob's browser sign-off**. (Concurrency unchanged: the other session's CI a11y
commits are still local-only; this session owns the workstream.) Next: per-strategy ladder + contingent-cost #1.

_2026-06-29 (adviser-legibility: explainer / show-your-working layer built)_ — From Rob's choice to start the
workstream with the explainer layer (over the correctness fix first), built three deterministic results-page
additions, all factual/lint-safe: (1) a **house-sale waterfall** (`ResultPresenter::saleExplainer`) — sale −
mortgage − selling costs − CGT = net, then per-option destinations (rent: full net invested; buy cheaper: net −
buy − SDLT − moving = surplus), with the **selling-cost rate shown beside the £** so the real couple's 20% = £70k
is glaring; backed by a new reconciled engine value object **`HousingPurchase`** (buy-side surplus, single source —
`HousingComparison::buyVariant` now reads `buyOutcome()`, behaviour-preserving). (2) an **assumptions panel**
(`assumptionsPanel`) surfacing the blended **real** return (engine single-source, asset mix described) + CPI +
house/rent/salary growth (real) + income yield (nominal), each labelled so real/nominal can't be confused. (3)
**itemised per-year spend** in the cashflow ladder (essential / discretionary, reconciling to the total) + the two
new CSV columns. Reconciliation + real-vs-nominal labelling guards added; the displayed-figure provenance test was
extended to the new CSV columns. Assets rebuilt; **pending Rob's in-browser visual sign-off**. NB a **second Claude
session** was concurrently active in this working tree on the CI a11y fix (commits `60d7da9`, `bb4d8ef`, not yet
pushed); this session owns the adviser-legibility workstream, that one owns the CI push (Rob's call 2026-06-29).
Next: the per-strategy cashflow ladder + the contingent-cost placement correctness fix (#1) it acts on.

_2026-06-29 (browser walkthrough → adviser-legibility workstream; no engine bug)_ — Rob walked the rendered
results for the real "FR + YC" couple and raised: missing adviser-style explanations, an odd 2040 "shortfall then
rapid recovery", confusion over spending vs the housing decision, and that the year-by-year cashflow should show
the differences by housing strategy. Investigation was read-only (engine instrumented via `artisan tinker`): **no
engine bug** — the forecast is deterministic (repeated runs byte-identical) and cohort mortality is correct
(median death age is conditional on current age). The dramatic swings that appeared mid-session were Rob's **live
input edits**: a retirement age at/below current age zeroes the salary (P1 born 1960, retire-age 66 in base-year
2026 → £30,000 dropped from year one), and a longevity *offset* below current age floors at current age (P2 median
88, −15 → clamped to 80 → modelled dying ~2027, removing ~£23k income and collapsing the forecast). The **2040**
event was a correct income/spend crossover (triple-locked State Pension overtaking flat real spend as a thin cash
buffer empties). **The real find:** mortgage + service charge (~£22.9k/yr) live in shared `expenseProfile`, so
they are charged in *every* housing variant including sell-&-rent / buy-outright where the property is gone —
**biasing the buy-vs-rent comparison against selling** (commute fuel similarly never stops at retirement; and the
cashflow ladder runs the raw household, ignoring the variant transforms). A deterministic trace of the sell-&-rent variant
showed the £72k net proceeds draining to £0 by 2030 from three compounding issues: selling costs applied at **20%
(£70k, vs ~2%)** from a `sellingCostRate:"20"` entry, the phantom mortgage + service charge (~£22.9k/yr, #1), and
rent entered as **£1,650/yr** (≈£137/mo, almost certainly monthly) — the under-stated rent and the phantom costs
partly cancel, so totals read plausible while wrong (the trust-killer). Rob's framing: **"I can't trust the
numbers because they have not been sufficiently explained by the output"** — so explainability is the gate to
trust, above remaining go-live polish. Recorded a **contingent-cost** decision (one home per cost, charged only
while its condition holds) in DECISIONS 2026-06-29 and a seven-item **Adviser-legibility workstream** in
docs/PLAN.md (cost-placement fix + per-strategy ladder first, then milestones / sale-explainer / input-sanity /
per-option narrative / real-time cost toggles). Docs-only this session; the earlier a11y `tabindex` fix
(scrollable tables keyboard-focusable, from the axe sweep) is also in the tree, uncommitted.

_2026-06-29 (results charts reworked — spendable-money default + over-time strategy comparison)_ — From Rob's
browser pass: the fan + comparison charts read flat and near-identical because both plotted total wealth incl.
the home (a large, illiquid floor that barely moves and dwarfs the spendable variation). Reworked so both
default to **spendable money (excl. home)** with an **"Include home value" toggle** (flips both charts + their
tables); **replaced the terminal-wealth comparison bar with an over-time line per housing strategy** (median
spendable money by year — directly answers "if I live to 100, which strategy keeps the most usable money"),
keeping the per-strategy run-out stats in a table beside it; **anchored the fan y-axis at £0 + forceNiceScale**
and added a **£-abbreviating axis/tooltip formatter** in `charts.js` (`moneyAxis` flag — a JS fn can't travel
through the JSON options). **Engine:** `MonteCarlo\SimulationResult` gained a **per-year usable fan**
(`usableFanChart`) beside the total `fanChart`, same `liquid + pension` definition as the ladder, with a
`usable ≤ total` per-year reconciliation test; round-trips via `SimulationResultMapper` (empty for pre-change
runs). Suite 368 → 372 green; assets rebuilt. Rationale in DECISIONS 2026-06-29. **Pending Rob's in-browser
visual sign-off** of the reworked charts + toggle (the chart-swap-on-toggle is the one bit unverifiable without a
browser; built with a keyed non-ignored wrapper around the `wire:ignore` canvas so a basis change replaces +
re-inits it). Then, on Rob's follow-up ("why does it shoot up at 2068–2070?"), added a `partials/tail-note`
explainer under both charts: the rise is two real effects, **verified against the engine** (per-year `paths`
collapses ~1,700 → single digits over the last decade; the median drifts up, total £1.05M→£1.22M / usable
£510k→£644k) — the sample thins to a handful of very-long-lived futures, and a long survivor's guaranteed income
covers their reduced spending so the remaining pot compounds. Then two more follow-ups: (a) the calendar-year
axis + both chart tables now show each **person's age** that year (age = `calendarYear − birthYear`, the engine's
own `YearResult::ages` definition, reconciled to the cashflow ladder in a test; axis formatter in `charts.js`),
and (b) a **stale-run prompt** — a run computed before this change has no `usableFanChart`, so instead of
silently drawing total wealth as spendable (which reads as "toggle does nothing / title stuck on Total wealth"),
the page shows a neutral re-run note via a `usableFanAvailable` flag. **Existing runs must be re-run** to get the
spendable view. Suite 372 → 375 green. Commits `6283b86` (charts + worker hint) then `6a1633f` (ages/stale-run follow-up). **Rob signed off the reworked charts on 2026-06-29.**

_2026-06-29 (queued-run "waiting for a worker" hint — no silent failure)_ — Closed the go-live UX gap the
2026-06-28 browser pass surfaced: clicking *Run the full 10,000-path forecast* with no `php artisan queue:work`
worker left the run at "Queued — 0%" indefinitely with no reason. Added `SimulationRun::isAwaitingWorker()`
(queued + 0% + created past a 15s grace window) and a neutral `role="status"` note on the results page inside the
existing `wire:poll` progress block, so it appears on the next poll and clears once the run moves to running/done.
Wording stays guidance-side (clears the banned-phrasing lint). Tests: a focused model-predicate test (every
status/age/progress case) plus a Livewire test (fresh queued ⇒ hidden; stale via `travel(20)->seconds()` ⇒ shown).
Suite 366 → 368 green. PHP/Blade only; PLAN "Go-live UX backlog" item marked resolved.

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
