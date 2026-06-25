# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue building the calculation engine and then the app around it. Read `docs/PLAN.md` first: it is the full approved plan and the source of truth for scope.

**Stage:** active
**Status:** Rebuild **Phase A (engine enrichment) + Phase C3 (results drill-down) done; Phase B (storage inversion — `scenarios.builder_state` as source of truth + edit/clone) is next.** Suite **235 green** (engine 113 / app 122); tree clean, all committed (`2587324`). _The narrative that follows is the full build history; the live picture is the **▶ REBUILD** callout below + the newest session-log entry._

_Pre-rebuild prototype history:_ Engine complete (Phase 1); **Phase 2 steps 1–4 of the app layer are in.** Step 1: encrypted DTO persistence, Fortify auth, GDPR, Filament admin. Step 2: forecast/scenario services (`ScenarioForecaster`, `SimulationRun`/`Result` persistence, `SimulationRunner` + queued job with progress + cancel). Step 3: the **Livewire UI + ApexCharts** (auth screens, scenario builder, results page with fan chart + buy-vs-rent, each as text + accessible `<table>` + CSV + signposting). Step 4 (this session): the **compliance/disclaimer layer** — `App\Compliance\OutputPhrasing` directive-only banned-phrase lint + a **partition build test** over every view and app PHP file; a **first-run acknowledgement gate** (`EnsureDisclaimerAcknowledged` middleware + `users.disclaimer_acknowledged_at` + a dedicated screen); reusable `<x-disclaimer.result>` + `<x-signpost>` on every result and a disclaimer prefix on the CSV export; the **admin-granted, off-by-default interpretation toggle** (`users.can_interpret` → `interpret` Gate → walled-off `App\Compliance\Interpretation` service + `interpretation`-named partial, set via a Filament `UserResource` toggle). Also folded in the tagged **no-silent-failure hardening**: GDPR `export()` now includes runs+results, `RunScenarioSimulation::failed()` lands a dead worker's run in Failed, `ScenarioResults::currentRun()` is owner-scoped. **Full suite at that point: 224 tests / 894 assertions (105 engine + 119 app)** — now **235 / 929** (engine 113) after the rebuild; see the callout. This autonomous session landed several committed stages on top of step 4: (1) the **lump-sum tax-shock panel** (headline output #1, `App\Forecast\LumpSumTaxShock` reproducing worked example A through the app) is now **rendered** on the results page; (2) the scenario builder is a **free-navigation wizard** — five steps (About & people; Pensions & income; **Your net worth** = savings + the home; Spending; The decision) with a11y (stepper `aria-current`, focusable headings + error summary, `aria-invalid`/`aria-describedby`, Save double-submit guard, `endAge ≥ startAge`, jump-to-first-error on save); (3) a **spreadsheet-import** layer (`app/Import/`) — an `ImportProfile` registry with the calibrated **RetireForecast CSV** profile (pre-fills spending + salary in exact pence), the **IWT Conscious Spending Plan** profile calibrated to its published structure (header-driven, frequency-aware; Fixed→essential, Guilt-Free→discretionary, net-income so no gross salary), and **Nischa stubbed** pending a sample; (4) the **compare-assumptions overlay** (`App\Forecast\AssumptionComparison`) — the central best-estimate projection under each shipped sourced set (FCA / DMS / OBR), rendered immediately as an accessible sensitivity table; (5) the **IWT CSP import** made live (`$`-currency fix in `MoneyText`); (6) **`.xlsx` import + the personal workbook** — added `phpoffice/phpspreadsheet` (uploads can be `.xlsx`, app-layer only), a sheet-aware `Spreadsheet`/`SpreadsheetReader` (reads Excel's cached values), a **tab picker** for multi-tab workbooks (`updatedImportFile` → sheet names → `Spreadsheet::select`), and a bespoke **`PayAndExpenditures`** profile that reads Rob's scenario tab — expenditure→essential, salary→gross, **State Pension→state pension, DLA→tax-free income, partner pension→annuity** — **verified against the real file** (£24,600/yr essential, £190.00/wk SP, etc.; income lands on Person 1 with no start age, flagged). **Still OPEN**: **calibrating the Nischa import** (deprioritised by Rob; layout captured — it's a 50/30/20 dashboard) and re-verifying the IWT CSP profile against the real 2023 export; the **line-item expense categories** data-model decision; a full per-field a11y sweep + axe/Pa11y CI; 2FA enrolment UI. **This session** added **scenario-draft auto-save**, **person names** (persisted), the **State Pension "full" shortcut**, and **`.xlsx`/Cancel import fixes** (all tested), then captured **sector research** ([docs/RESEARCH-cashflow-modelling.md](docs/RESEARCH-cashflow-modelling.md)) + a **research-backed build plan** (docs/PLAN.md "Sector-informed build plan"). **Next (as planned then — now superseded by the current build order in Status + What's next + the REBUILD callout):** that plan — edit → clone/compare, line-item 3-tier budgets, projection drill-down — plus Phase 2 step 5 (demo/perf/PDF).
_Last updated: 2026-06-25 (handover refresh, no code changed). Two corrections: (1) reconciled the stale 224/105 headline counts to the verified **235 tests / 929 assertions**, engine 113; (2) corrected the rebuild work's date labels from a mis-stamped **2026-06-26** to **2026-06-25** across all five docs (37 occurrences) — git commit dates and the session clock confirm every commit was 2026-06-25; a prior session had wrongly believed the clock rolled to the 26th. Build history: the rebuild (Phase A + C3) and the builder-UX/sector-research work were both this same day; the prototype's Phase 1 engine + Phase 2 steps 1–3 were 2026-06-24._

**▶ REBUILD IN PROGRESS (2026-06-25 build session).** Rob authorised a clean rebuild treating existing
code as a prototype, with **no need to preserve any user data / DB layout / data shape** — build storage to
the new world directly. Decisions locked (DECISIONS 2026-06-25 ×2): **keep the framework-free engine + sound
app code; rebuild the storage layer freely**; **ratify Livewire 4 + Filament 5 + SQLite**; **interleave trust
+ features**; the prototype is tagged **`prototype-v1`** (a8f1f68) for recovery (no remote). Build order is
**A (engine) → B (storage) → C (features) → D (trust + go-live)**.
**Done this session (5 commits, suite 224→235 green, engine 105→113):**
- **Phase A (engine, all golden-master/reconciliation tested):** account + DC **ongoing contributions**
  (funded from surplus); per-person **`LongevityAdjustment`** what-if; terminal **usable**-vs-total wealth;
  **`YearResult::incomeBySource`** (8 canonical sources). **A5 (GIA/cash income tax + CGT-on-disposal)
  DELIBERATELY DEFERRED to Phase D** — the projector grows GIA/cash at *total* return, so taxing a yield on
  top would double-count; it needs return decomposition, done with the figure verification.
- **Phase C3 (results page, on current storage):** **usable-vs-total wealth** in the headline cards + the
  buy-vs-rent table (fixes the "wealth left" paradox end-to-end), and a deterministic **cashflow ladder**
  (income-by-source → tax → spend → usable/total wealth) as an accessible table + CSV, shown immediately.
**Still to build:** **Phase B** — the storage inversion (`scenarios.builder_state` as source of truth, engine
`Household` derived on save, **edit** route owner-scoped, invalidate stale runs, drop `households` +
`scenario_drafts`); **C1** 3-tier line-item budget (totals = sum of lines; wire account contributions) +
reconciliation invariants; **C2** delta-child what-ifs (`parent_scenario_id` + one merge fn + stable list-item
IDs) + Compare; **C4** income-floor + PLSA benchmark; **D** gov.uk ⚠️ verification + the deferred GIA/CGT
modelling + go-live polish (a11y CI, CSP, panel lockdown, perf, PDF). Phase B is an atomic-ish rewrite (no
dual sources) — best started with fresh context. Full plan: docs/PLAN.md "Sector-informed build plan".

## Goal & success criteria
Full plan: [docs/PLAN.md](docs/PLAN.md); PRD: [PRD.md](PRD.md). Summary:

- **Goal:** let an older couple (one working, one retired) model whether to sell their home and either buy somewhere cheaper outright (invest the surplus) or sell and rent (invest all proceeds), and see the consequences of pension lump-sum withdrawals and whether their money lasts for life.
- **Headline outputs:** (1) the pension lump-sum tax shock (25% tax-free, marginal tax on the rest, plus the Month-1 emergency-tax overpayment and reclaim), and (2) running-out-of-money / longevity risk via Monte Carlo.
- **Success for Rob's own use:** a working **local** site where he can enter a real (known) couple himself, run buy-vs-rent, and read a trustworthy forecast. **No hardcoded client data in the repo.** If it proves useful he may later release it publicly for free.
- **Correctness bar:** the engine reproduces known HMRC worked examples to the penny (examples A, B, C in docs/PLAN.md). **Met** for the deterministic engine.

## Canonical data shape
The single source of truth for the domain shape will be the engine's readonly DTOs under `packages/finance-engine/src/Dto/` (Eloquent models and Livewire forms map to/from these). See [DATA-MODEL.md](DATA-MODEL.md) and docs/PLAN.md for full field lists. Conventions, honoured by all existing code:

- **Money = integer pence**, never a float. Held by `Money` value object (GBP only). Rates = `Percent` (integer basis points). Dates = ISO `Y-m-d`. **Ages are derived from DOB + a reference date, never stored.**
- Entities — **all now coded** as readonly DTOs under `src/Dto/` and persisted as encrypted payloads: Household, Person, Pension (subtype dc|db|state), Property, Account (isa|gia|cash|premium_bonds), IncomeStream, ExpenseProfile, Scenario (variant buy_outright|rent|stay_put), AssumptionSet, SimulationRun, Result. Sensitive money/DOB/salary/pot/balance fields are encrypted at rest.
- **What exists today as concrete shape:** the money layer (`Money`/`Percent`/`IntMath`/`RoundingMode`); the full per-year `TaxYearConfig` spine (income tax, dividends, savings, NI, **pension**, **state pension**, **SDLT**, **CGT**, **benefits**, **IHT**, **care**) + per-calculator result objects + shared `Support\Warning`/`WarningCode`; and **the domain DTOs (`src/Dto/`), which are built** — all three consumers map to/from them: the engine, encrypted storage (`app/Finance/Mapping/` Eloquent bridges), and the Livewire wizard (`app/Forecast/HouseholdAssembler`, plus the `app/Import/` spreadsheet importers).

## Architecture / stack
- **Laravel 13.17** app at the repo root (SQLite locally). **Fortify** (auth) and **Filament 5** (admin, which pulled **Livewire 4** — a bump from the plan's stated Livewire 3) are **installed**. Fortify's views are now **on** (`config/fortify.php` `views => true`); the `FortifyServiceProvider` points each view route (login/register/forgot/reset) at a Blade screen. 2FA enrolment UI is still deferred (no user can enable it, so its challenge view never fires).
- **Front end (step 3):** hand-rolled **Livewire 4** full-page components (`app/Livewire/`) rendering into one Blade layout component (`resources/views/components/layouts/app.blade.php`) via `#[Layout('components.layouts.app')]` — **not** Livewire 4's default `layouts::app` (which is only a component namespace here, so it has no view hint path). **ApexCharts** is bundled via npm (`resources/js/app.js` + `charts.js`, an Alpine `chart` wrapper, reduced-motion aware). `public/build` is gitignored, so the base `TestCase` calls `withoutVite()` to keep view tests independent of `npm run build`.
- **`packages/finance-engine`**: a framework-free Composer **path package** (`retireforecast/finance-engine`, symlinked, required as `"*"`). Zero Laravel dependencies, no I/O, no clock. This is the product; the Laravel app is a shell around it. Keep it that way: the engine must never `use App\...` or `Illuminate\...`.
- **App ↔ engine boundary:** Eloquent models map to/from the engine DTOs via `app/Finance/Mapping/`; the mappers (not the engine) own serialization, so the engine stays serialization-agnostic. Sensitive data is one `encrypted:array` payload per row; structural columns stay clear for listing.
- Money handling is **hand-rolled integer pence** (see Decisions: brick/money was dropped over a dependency clash). PHPUnit 12 for tests. **`phpoffice/phpspreadsheet`** is an **app-layer** dependency (for `.xlsx` import only); the engine stays dependency-free.
- **Spreadsheet import (`app/Import/`):** profiles read a sheet-aware `Spreadsheet` (CSV or `.xlsx`); the Livewire wizard offers a tab picker + applies the parsed partial form-state. Real sample `.xlsx` are gitignored under `docs/*.xlsx`.
- Still to come (see docs/PLAN.md): **Phase 2 step 5** — demo preset, a11y CI (axe/Pa11y), 10k-path perf tuning, PDF export; the **line-item expense categories** data-model decision; a full per-field a11y sweep.

## Key files / structure
```
C:\Dev\RetireForecast
├─ docs/PLAN.md                         # the full approved plan (READ FIRST)
├─ PRD.md / DATA-MODEL.md / DECISIONS.md / CLAUDE.md   # standard doc set
├─ HANDOVER.md                          # this file
├─ composer.json                        # path repo + autoload-dev wired for the engine
├─ phpunit.xml                          # has the "Engine" testsuite + engine in coverage
└─ packages/finance-engine
   └─ src
      ├─ Money/{Money,Percent,IntMath,RoundingMode}.php
      ├─ Support/{Warning,WarningCode}.php
      ├─ TaxYear/{TaxYearConfig,TaxYearRegistry,RegionProfile, + *Parameters/band objects:
      │           IncomeTax,Dividend,Savings,NationalInsurance,Pension,StatePension,
      │           Sdlt(+SdltBand),Cgt,Benefits,Iht,Care}.php
      ├─ Tax/{IncomeTaxCalculator,IncomeTaxResult,ComprehensiveIncomeTaxResult,
      │       TaxableIncome,NationalInsuranceCalculator,NationalInsuranceResult}.php
      ├─ Pension/{TaxFreeCashCalculator,TaxFreeCashSplit,EmergencyTaxCalculator(+Result),
      │           FlexibleWithdrawalAssessor(+Result),AnnualAllowanceCalculator(+Result),
      │           WithdrawalKind,ReclaimForm}.php
      ├─ StatePension/{StatePensionAge(+Result),StatePensionCalculator(+Result)}.php
      ├─ Property/{SdltCalculator(+Result),CgtPrivateResidenceCalculator(+CgtResult)}.php
      ├─ Benefits/{CapitalAssessment(+Result)}.php
      ├─ Iht/{InheritanceTaxCalculator,IhtResult}.php
      ├─ Care/{CareMeansTest,CareMeansTestResult}.php
      ├─ Dto/{Household,Person,DcPension,DbPension,StatePensionEntitlement,Pension,
      │       WithdrawalInstruction,Property,Account,IncomeStream,ExpenseProfile,
      │       HousingAction,AssetClassAssumption,AssumptionSet, + enums}.php
      ├─ Assumptions/AssumptionSetLibrary.php
      ├─ Mortality/{OnsPeriodMortalityData(generated),CohortLifeTable,JointLifeSampler}.php
      ├─ Forecast/{PathProjector,DeterministicForecaster,DeterministicPathDraws,PathDraws,
      │            DrawdownStrategy,PortfolioAllocation,ForecastSettings,YearResult,ForecastResult}.php
      ├─ MonteCarlo/{Cholesky,ReturnModel,SampledPathDraws,Simulator,SimulationResult}.php
      └─ Housing/HousingComparison.php
   ├─ resources/mortality/ons-2024-period-qx.json   # sourced ONS data (engine class generated from it)
   └─ tests/{Money,Tax,Pension,StatePension,Property,Benefits,Iht,Care,Dto,
             Assumptions,Mortality,Forecast,MonteCarlo,Housing}/*Test.php
```
Engine namespace: `RetireForecast\FinanceEngine\...`. Tests namespace: `RetireForecast\FinanceEngine\Tests\...` (registered in the root app's `autoload-dev`). Pint enforces house style (snake_case test methods, no-space concatenation); run `vendor/bin/pint packages/finance-engine` after adding files, or `vendor/bin/pint --dirty` for changed app files.

App layer added this session (`App\...`):
```
app/
├─ Compliance/{OutputPhrasing, Interpretation}.php     # step 4: banned-phrase lint (directive-only
│                                                      #  patterns) + the WALLED-OFF advice-style narrator
├─ Enums/{ScenarioVariant, ScenarioStatus, SimulationMode, SimulationStatus}.php
├─ Finance/Mapping/{Codec, HouseholdMapper, HousingActionMapper,   # DTO <-> storage-array, the
│                   AssumptionSetMapper, SimulationResultMapper}.php  #  one place serialization lives
├─ Models/{Household, Scenario, AssumptionSet, SimulationRun, Result, User}.php  # Eloquent + DTO bridges
├─ Forecast/{ScenarioForecaster, SimulationRunner, RunCancelled,    # assemble inputs, run, persist
│            HouseholdAssembler,                       #  form-state -> engine DTOs (lossless, no float)
│            ResultPresenter,                          #  SimulationResults -> headline text + chart + table
│            LumpSumTaxShock,                          #  headline #1: deterministic pension tax shock
│            AssumptionComparison}.php                 #  compare-assumptions sensitivity table
├─ Import/{ImportProfile, ImportRegistry, ImportResult, ImportException,  # spreadsheet import:
│          Spreadsheet, SpreadsheetReader (csv + xlsx), MoneyText,        #  sheet-aware, exact pence
│          Profiles/{RetireForecastTemplate, ConsciousSpendingPlan,      #  CSV + IWT CSP (calibrated),
│                    PayAndExpenditures, IntentionalSpendingTracker,     #  bespoke personal workbook,
│                    UncalibratedProfile}}.php                            #  Nischa stub (deprioritised)
├─ Livewire/{Dashboard, ScenarioBuilder (wizard + import), ScenarioResults}.php  # full-page UI components
├─ Jobs/RunScenarioSimulation.php                      # queued full run (holds run id only) + failed()
├─ Gdpr/GdprService.php                                # export() (incl. runs+results) + erase()
├─ Http/Controllers/{AccountController, DisclaimerController}.php    # GDPR routes; first-run ack screen
├─ Http/Middleware/EnsureDisclaimerAcknowledged.php    # gates forecast pages on acceptance
├─ Providers/{AppServiceProvider(interpret Gate), FortifyServiceProvider, Filament/AdminPanelProvider}.php
├─ Actions/Fortify/*                                   # Fortify scaffolding
└─ Filament/
   ├─ Resources/AssumptionSets/{AssumptionSetResource, Schemas/*, Tables/*, Pages/*}.php
   ├─ Resources/Users/{UserResource, Tables/UsersTable, Pages/ListUsers}.php  # can_interpret ToggleColumn
   └─ Pages/TaxYearAudit.php                           # read-only registry audit
resources/
├─ js/{app.js, charts.js}                              # ApexCharts (npm) + Alpine `chart` wrapper
└─ views/{components/layouts/app.blade.php,            # the app shell: skip link, nav, disclaimer footer
          components/{signpost.blade.php, disclaimer/result.blade.php},   # reusable compliance blocks
          home.blade.php, disclaimer.blade.php,        # landing; first-run acknowledgement screen
          auth/{login,register,forgot-password,reset-password}.blade.php,
          livewire/{dashboard,scenario-builder,scenario-results}.blade.php,
          livewire/partials/interpretation.blade.php}  # WALLED-OFF directive partial (lint-exempt by name)
routes/web.php                                         # /, /welcome (ack), /dashboard, /scenarios/*, /account/*
database/migrations/{2026_06_24_*_create_{assumption_sets,households,scenarios,simulation_runs,results}_table,
                    2026_06_25_*_add_compliance_columns_to_users_table}.php  # disclaimer_acknowledged_at, can_interpret
database/seeders/AssumptionSetSeeder.php               # mirrors the engine library
tests/{Unit/{Finance/{MappingRoundTripTest, SimulationResultMappingTest},
             Forecast/HouseholdAssemblerTest},        # form-state -> DTO is lossless vs the fixture
             Import/{RetireForecastTemplateTest, ConsciousSpendingPlanTest, PayAndExpendituresTest}},
       Feature/{Persistence/*, Gdpr/GdprTest, Admin/FilamentAdminTest,
                Auth/AuthScreensTest,                  # login/register/reset render + flow
                Compliance/{BannedPhrasingTest,        # the partition build test (+ non-vacuity guard)
                            DisclaimerAcknowledgementTest, InterpretationTest},
                Import/{ImportRegistryTest, SpreadsheetReaderTest},   # registry + csv/xlsx reader
                Forecast/{ScenarioForecasterTest, SimulationRunnerTest, RunScenarioSimulationFailureTest,
                          LumpSumTaxShockTest, AssumptionComparisonTest},
                Livewire/{ScenarioBuilderTest, ScenarioBuilderImportTest, ScenarioResultsTest}}}.php
tests/Support/{HouseholdFixture, BuilderStateFixture}.php   # rich DTO + matching form-state strings
```
Engine gained (this session, non-breaking): an optional progress callback on
`MonteCarlo/Simulator::run` and `Housing/HousingComparison::compare` (default null), covered by
`tests/MonteCarlo/SimulatorProgressTest.php`.

## Decisions locked
See [DECISIONS.md](DECISIONS.md) for the full log. Highlights:
- **Local-first, personal use, no hardcoded client data.** Rob enters the couple via the UI himself. Any first-run sample must be obviously fictional. Possible free public release later, so do not design accounts out, just defer them.
- **Modelling depth:** HMRC-accurate deterministic engine PLUS Monte Carlo, with **stochastic joint-life mortality**. Pensions: DC, DB and State Pension. Housing: buy-cheaper-outright vs rent on identical seeds. IHT/legacy **in as a toggle** (incl. pensions entering the estate from April 2027). Assumptions are a runtime/display choice across several sourced sets (FCA default), not baked in.
- **Regulatory posture: education/guidance only, never a personal recommendation.** A build-time test must fail if any result template contains banned recommendation phrasing. Signpost Pension Wise / MoneyHelper.
- **Money = hand-rolled integer pence** (brick/money dropped over a brick/math clash). **Engine is framework-free in a path package.** **Tax figures versioned per tax year with source + verified-on.** Two stale-brief corrections baked in: income-tax freeze now to April 2031; dividend rates rise in 2026/27.
- **Savings + dividends in one combined income-tax pass** (`IncomeTaxCalculator::compute`), not separate calculators — the band stacking demands it.
- **The app is Laravel 13** (installer pulled 13.17), not 12; the initial commit's "Laravel 12" label is wrong but harmless.
- **UI = hand-rolled Livewire 4** (Filament stays admin-only); form input → engine DTOs via a standalone, unit-tested `HouseholdAssembler` (money parsed to exact pence, no float). Charts are a progressive enhancement: every figure also renders as headline text + an accessible `<table>` + CSV. Full-page Livewire uses the Blade layout component, not Livewire's `layouts::app`; the region guard asks the engine's `TaxYearRegistry` (so Scotland is refused until its bands land). See DECISIONS 2026-06-24 UI entries.
- **Compliance = a directive-only banned-phrase lint + a path/namespace partition test** (step 4). Neutral zone = every Blade view + all app PHP; the only exemptions are the `App\Compliance` namespace and `interpretation`-named views. First-run acceptance is a **middleware gate** (not a JS modal) storing a timestamp; GDPR routes sit outside it. The **interpretation (advice-style) toggle** is admin-granted, off by default, walled-off in `App\Compliance\Interpretation` behind an `interpret` Gate — the sole home of directive wording. See DECISIONS 2026-06-25 compliance entry.

## Current state
- **Done:** Laravel 13 app scaffolded (SQLite migrated). finance-engine path package wired in. Standard doc set scaffolded. **The entire deterministic engine is built and tested:** money layer; full per-year `TaxYearConfig`/`TaxYearRegistry` (2025-26 + 2026-27, England/Wales/NI; Scotland throws); income tax (incl. combined savings/dividend stacking) + NI; pension lump-sum suite (PCLS/UFPLS/drawdown, Month-1 emergency tax + P55/P50Z/P53Z, MPAA, annual allowance + taper) — **worked examples A & B**; State Pension (SPA-from-DOB transition, deferral, triple lock); SDLT (+surcharge) and CGT (PRR); benefits capital tariff + £16k cliff — **worked example C**; IHT (pensions-in-estate toggle) and care means-test. **79 tests / 188 assertions passing.**
- **Also done:** canonical DTOs (`src/Dto/`, incl. `HousingAction`); `Assumptions/AssumptionSetLibrary` (3 signed-off sets); `Mortality/` (embedded ONS period q(x), `CohortLifeTable`, seeded `JointLifeSampler`); `Forecast/` (`PathProjector` year-stepper + `DeterministicForecaster`, `DrawdownStrategy`, `PortfolioAllocation` cautious-40/60 default, `ForecastSettings`, `PathDraws`/`DeterministicPathDraws`, `YearResult`/`ForecastResult`); `MonteCarlo/` (`Cholesky`, `ReturnModel`, `SampledPathDraws`, `Simulator`, `SimulationResult`); `Housing/HousingComparison` (buy-vs-rent on identical seeds). **101 tests / 313 assertions.**
- **App layer (this session — Phase 2 step 1):** encrypted DTO persistence — `app/Finance/Mapping/` (`Codec` + Household/HousingAction/AssumptionSet mappers), Eloquent `Household`/`Scenario`/`AssumptionSet` with `encrypted:array` payloads and to/from-DTO bridges, three migrations, `AssumptionSetSeeder` (from the engine library, one default). **Fortify** auth installed headless; `User` owns households/scenarios. **GDPR** export + hard delete (`GdprService`, `AccountController`, auth-gated routes). **Filament 5** admin at `/admin`: assumption-set resource (curate name/source/default, at-most-one-default guarantee) + read-only tax-year config audit page. Tests: lossless DTO round-trip, decrypts-to-identical-DTO, encrypted at rest, GDPR export/erase, anonymous-writes-nothing, admin smoke. **+18 app tests → 119 total / 374 assertions.**
- **App layer (this session — Phase 2 step 2):** forecast/scenario services. `app/Forecast/ScenarioForecaster` assembles engine inputs from a saved scenario (household, assumption set, housing action, tax-year config, settings; base year derived from the tax year) and runs `deterministic()` / `simulate()` / `compareHousing()`. `SimulationRun` + `Result` Eloquent models + migrations: a run records mode/n_paths/seed/status/progress + a frozen encrypted assumption snapshot; a `Result` per variant holds the encrypted `SimulationResult` (`SimulationResultMapper`). `SimulationRunner` orchestrates create → run → persist with live progress + cancel (preview sync, full queued via `RunScenarioSimulation`). Engine got a small non-breaking progress callback (`Simulator`/`HousingComparison`). Tests: forecaster runs + reproducible; result/snapshot round-trip + encrypted at rest; preview persists 3 results + completes, full run queued, job completes, cancel writes nothing, same-seed reproducible; engine progress hook. **+14 app/engine tests → 133 total / 619 assertions.**
- **App layer (this session — Phase 2 step 3):** the Livewire UI + ApexCharts, in three committed milestones. (A) Front-end foundation + **real auth**: app Blade layout (skip link, auth-aware nav, persistent guidance-only disclaimer footer), ApexCharts bundled via npm + an Alpine `chart` wrapper, real Fortify login/register/forgot/reset screens (`config/fortify.php` `views => true`, view routes wired in `FortifyServiceProvider`), public landing + authed `Dashboard`; base `TestCase` calls `withoutVite()`. (B) **Scenario builder** (`ScenarioBuilder` + `HouseholdAssembler`): a full household (two people, all three pension subtypes with a nested withdrawal plan, accounts, income, spending + one-offs, the home) and the housing decision entered by hand, validated (salary-if-employed, money non-negative & ≤2dp, Scotland refused via the registry), assembled losslessly into engine DTOs, persisted encrypted (`HouseholdAssembler` rebuilds the rich `HouseholdFixture` exactly). (C) **Results page** (`ScenarioResults` + `ResultPresenter`): preview sync / full queued with `wire:poll` progress + cancel, then headline numbers as text, the **Monte Carlo fan chart** and **buy-vs-rent comparison** — each with an accessible `<table>` (+`<caption>`), CSV download, and Pension Wise/MoneyHelper signposting; ownership enforced. Tests: auth screens render + flow; assembler lossless; builder validation + round-trip-to-identical-DTO; preview renders headline-as-text + fan-chart table present, full run queued + cancellable, ownership guard. **+24 app tests → 157 total / 690 assertions** (engine still 104).
- **App layer (this session — Phase 2 step 4 — compliance/disclaimer + interpretation toggle + hardening):** (A) **Banned-phrasing guard:** `App\Compliance\OutputPhrasing` holds directive-only regex patterns; `BannedPhrasingTest` is a **partition** check scanning every Blade view + all app PHP for zero violations, exempting only the `App\Compliance` namespace and `interpretation`-named views, with a non-vacuity guard + a test proving the walled-off layer *does* carry directive phrasing (so the wall is load-bearing). (B) **Disclaimers + signposting:** reusable `<x-disclaimer.result>` + `<x-signpost>` render on every result + an output-mode label; the fan-chart CSV is prefixed with the guidance-only disclaimer; deleted the unused stock `welcome.blade.php` (it tripped the lint). (C) **First-run acknowledgement:** `EnsureDisclaimerAcknowledged` middleware redirects unacknowledged users to a dedicated `/welcome` screen; `users.disclaimer_acknowledged_at` records acceptance; GDPR/account routes stay outside the gate. (D) **Interpretation toggle:** `users.can_interpret` (admin-set via a Filament `UserResource` `ToggleColumn`) behind an `interpret` Gate; the walled-off `App\Compliance\Interpretation` service produces the advice-style readouts into an `interpretation`-named partial, shown only when the gate allows; the public default stays neutral. (E) **No-silent-failure hardening folded in:** GDPR `export()` now includes the user's runs+results (erase already cascades; both covered by tests), `RunScenarioSimulation::failed()` marks a dead worker's run Failed-with-reason, and `ScenarioResults::currentRun()` is owner-scoped against a forged `$runId`. Tests: partition lint (+non-vacuity), acknowledgement gate (redirect/record/GDPR-still-reachable), interpretation gate (off→neutral, granted→walled-off block), per-result + CSV disclaimer, owner-scope tamper, job `failed()` (live/fallback/already-terminal), GDPR runs+results in export & erase, Filament user toggle smoke. **+20 app tests → 177 total / 743 assertions** (engine still 104).
- **App layer (this session — on top of step 4):** the **lump-sum tax-shock panel** (`LumpSumTaxShock`, reproduces worked example A); the scenario builder reworked into a **free-navigation wizard** (5 steps, a11y, net-worth grouping); the **compare-assumptions overlay** (`AssumptionComparison` sensitivity table); and the full **spreadsheet-import** layer (`app/Import/`) — registry + CSV/`.xlsx` reading (`phpoffice/phpspreadsheet`) + tab picker + profiles (RetireForecast CSV, IWT CSP, the bespoke **PayAndExpenditures** verified on Rob's real workbook incl. income, Nischa stub). **157 → 212 tests / 857 assertions** (engine still 104).
- **In progress:** nothing mid-edit; tree clean and **all committed** (`2587324`). Beyond the prototype's Phase 2 steps 1–4: the rebuild's **Phase A (engine enrichment) + C3 (results drill-down)** are done (engine 113 / suite 235); **Phase B (storage inversion) is next** — see the **▶ REBUILD** callout + What's next. Phase 2 step 5 (demo/polish) is deferred behind the rebuild.
- **Known bugs / broken:** none known. Documented v1 scope limits (all flagged in code): income tax is England/Wales/NI only (Scotland throws); emergency tax models the over-deduction magnitude, not PAYE-table pennies; mortality grid ages 50–100 / years 2025–2074 with clamping + a non-ONS tail above 100 (cap 110); forecast taxes non-savings income only (GIA/cash income tax + CGT-on-disposal deferred; ISA tax-free; pots grow at total return); tax thresholds held frozen for the whole projection; DB escalation + triple lock as smooth growth factors; buy-vs-rent takes main-home CGT as £0 (PRR) and no SDLT surcharge; house/salary growth deterministic inside the Monte Carlo.

## What's next (in order) — the research-backed rebuild
The engine + a working prototype app are built and green (235 tests). The rebuild is underway — **Phase A
(engine enrichments: ongoing contributions, longevity, usable-vs-total wealth, income-by-source) and Phase
C3 (results: usable-vs-total + the deterministic cashflow ladder) are DONE** (committed, green; A5 GIA/CGT
deferred to Phase D). **What remains: Phase B (storage inversion) FIRST, then C1 → C2 → C4 features, then D
(trust + go-live).** Items 4–5 below are kept only to record that they are now done. **Read first:
docs/PLAN.md "Sector-informed build plan (2026-06-25)" (full steps + the gotchas table A–P) · DATA-MODEL.md
"Planned shape changes (2026-06-25)" · DECISIONS.md 2026-06-25 (×3) · docs/RESEARCH-cashflow-modelling.md.**
The rebuild is **authorised** even though it reworks the prototype builder (the UI wins — person names, the
State Pension shortcut — carry over; the per-user draft mechanism folds into `builder_state`). Build order:

1. **Phase B — scenario data-shape inversion + Edit a saved forecast.** Persist the builder **form-state** on
   the scenario (encrypted `builder_state`) as the **source of truth**; the engine `Household` DTO becomes a
   *derived* artifact regenerated on save (no reverse-mapper — single source). Edit route
   `/scenarios/{scenario}/edit`, **owner-scoped**; `save()` → update-or-create; **invalidate stale
   runs/results on edit** (gotcha B). Drop the now-redundant `households` + `scenario_drafts` tables (the
   draft mechanism folds into `builder_state`); no data migration needed (rebuild authorised). **Atomic-ish
   rewrite — best begun with fresh context.**
2. **Phase C2 — "Create child" what-ifs + Compare.** A child = a **delta** of overridden form-state paths on a base
   (`parent_scenario_id`); anything-overridable, curated levers as presets; effective = base ⊕ overrides via
   **one merge function** (+ round-trip test). **List items (expense lines, pensions, accounts) gain stable
   IDs** so overrides target the right row (gotcha N). Compare reuses the variant side-by-side rendering.
3. **Phase C1 — 3-tier line-item budget.** Line items `{id,label,amount(annual),category,savedAsAsset}` as the
   **source**; category ∈ essential/discretionary/self-investment; totals = `sum(lines)` (reconciliation
   invariant + extend the import golden fixtures, gotcha A). `savedAsAsset`: *spent*→expense, *saved*→a
   contribution (needs **ongoing contributions on accounts**, gotcha O). Importers populate the lines.
   Framed as the goal, not a %. + the **income-floor readout**; PLSA benchmark a fast-follow.
4. ✅ **DONE (rebuild Phase C3 + A3).** Drill-down + usable-vs-total wealth — the results page now shows
   **usable (liquid, excl. home) vs total wealth** plus a deterministic **cashflow ladder** from `YearResult[]`
   (income-by-source → tax → spend → wealth), fixing the cards paradox (gotcha P) end-to-end. **Residual for
   Phase D:** the per-pension current→projected→income drill-down.
5. ✅ **DONE (rebuild Phase A2).** Per-person longevity adjustment — `LongevityAdjustment` (peer / fixed age /
   ±years / mortality multiplier) feeds the deterministic death age and the Monte-Carlo `JointLifeSampler`
   (cohort-table q(x) multiplier), golden-master tested. The lifespan what-if gets wired into the builder in
   Phase B/C2.

**Independent of the rebuild (Phase 2 step 5 + go-live):** demo preset/seeder, a11y CI (axe/Pa11y), 10k
perf, PDF export, CSP header, `User::canAccessPanel()` lockdown, 2FA UI, and the **gov.uk ⚠️ verification
pass** (Tier-1 go-live gate). See "Readiness gaps" + "Blockers".

## Readiness gaps (2026-06-25 doc/code review — suite verified green at 212/857)
A review against the docs confirmed **no drift** (the 212/857 suite, the file structure and the stack
all match the narrative; only the stale "step 4 not committed" branch line, now fixed). The genuine
gaps, tiered by severity:
- **Tier 1 — trust (blocks "shown as real"):** the **gov.uk verification pass** — ~10 figures still
  flagged ⚠️ in the `TaxYear/` docblocks (tapered-AA + LSDBA, CGT residential rates + £3k AEA, IHT
  NRB/RNRB + the Apr-2027 pensions-in-estate change, care thresholds, SPA boundary dates, benefits
  £16k boundary, SDLT Wales/Scotland, lettings relief); the **forecast taxes non-savings income only**
  (GIA/cash income tax + CGT-on-disposal deferred), a material drag understatement for a household
  holding unwrapped assets; and the **data-layer integrity guardrails** (below).
- **Tier 2 — Phase 5–6 not built:** demo preset/seeder, PDF export, **CSP header** (none set, charts
  are embedded), a11y CI (axe/Pa11y), 10k-path perf tuning. Scotland config pack is deliberately out of v1.
- **Tier 3 — shipped-surface gaps:** 2FA enrolment UI (Fortify 2FA on, no screens), real-browser
  ApexCharts verification (only the tables/text are tested), `User::canAccessPanel()` still returns
  true for any authenticated user (tighten before public release — the `interpret` toggle lives behind
  it), assumption-set figures not numerically editable in Filament.
- **Tier 4 — open data-model/import decisions:** line-item expense categories; re-verify IWT CSP vs the
  real 2023 export; Nischa stub; imported income lands on Person 1 with no start age (flagged).

## Blockers / open questions
- [~] **Data-layer integrity guardrails — importer layer BUILT 2026-06-25; provenance test + panel still to do.**
  Rob's hard requirement (a past project was burned not by hallucinated numbers but by the data layer
  *inconsistently aggregating the same information*). **Built:** `tests/Fixtures/Import/GoldenWorkbooks.php`
  (sanitised real-file fixtures — layout-faithful, fake figures) + `tests/Unit/Import/ImportReconciliationTest.php`,
  reconciling each importer's output to the sheet's own stated totals. On its first run the guardrail
  **caught and we fixed two live wrong-aggregation bugs** in the IWT `ConsciousSpendingPlan` importer
  (a per-bucket "… TOTAL" double-counted on top of its line items → essential ~2×; the `NET WORTH`
  Investments/Savings rows miscounted as monthly contributions) — exactly the class of bug a synthetic
  happy-path test misses. The fix makes a bucket's own TOTAL authoritative; `PayAndExpenditures` +
  `RetireForecastTemplate` reconcile cleanly, locking in their real shapes. **Still to do:** a
  one-value-per-displayed-figure test (panel == CSV == interpretation), an import reconciliation panel
  surfaced to the user, and extending the discipline to the forecast boundaries (net proceeds, terminal
  wealth). **Caveat:** the IWT fixture was built from a *masked* dump of the real export, so it still
  warrants Rob's own eyeball against the real 2023 file. See DECISIONS 2026-06-25 "Data-layer integrity" + CLAUDE.md.
- [ ] **Spreadsheet import — Nischa deprioritised; line-item expenses still a decision (2026-06-25).** Built + tested + (where noted) **verified against Rob's real files**: the import infrastructure, **`.xlsx` reading** (`phpoffice/phpspreadsheet`), the **tab picker**, the **RetireForecast CSV** profile, the **IWT CSP** profile (calibrated from the published structure), and the bespoke **`PayAndExpenditures`** profile (expenditure + salary + State Pension/DLA/partner-pension income; verified on the real workbook). **Nischa** is deprioritised by Rob (layout captured: a 50/30/20 dashboard) — still `isAvailable()=false`. **Open:** re-verify IWT CSP against the real 2023 export; the **line-item expense categories** data-model decision (imports/wizard still roll up to essential/discretionary totals); imported income lands on **Person 1 with no start age** (by design — the sheet has neither ages nor a person split — flagged for the user). Real sample `.xlsx` live in gitignored `docs/*.xlsx` (never commit).
- [x] **Code-review refinements (2026-06-25) — all five DONE.** Logged in **docs/PLAN.md → "Refinements found in code review (2026-06-25)"**. (1) ✅ **compliance** — banned-phrasing partition test + disclaimer layer + acknowledgement gate + interpretation toggle; (2) ✅ the **lump-sum tax-shock panel** (headline output #1, now rendered); (3) ✅ **"no silent failure" hardening** — `GdprService::export()` includes runs+results, `RunScenarioSimulation::failed()` lands a dead worker's run in Failed, `ScenarioResults::currentRun()` is owner-scoped; (4) ✅ **a11y/form UX** — the wizard added `aria-describedby`/`aria-invalid` on validated fields, focus-to-first-error/step, a Save double-submit guard and the `endAge ≥ startAge` check; (5) ✅ the **compare-assumptions overlay** — `App\Forecast\AssumptionComparison` renders an accessible sensitivity table per shipped set. The remaining a11y work is only a *full* per-field sweep + axe/Pa11y CI (step 5). None break the green suite.
- [ ] **External-review enhancement backlog (2026-06-25) — post-v1, not blocking.** A second-opinion review was triaged into **docs/PLAN.md → "External review triage"**: adopt (when their phase arrives) a cashflow timeline table, longevity distribution visual, stress-test panel + what-if sliders (all reuse engine outputs we already compute), plus v2 annuitisation + care-cost stochasticity, CSP + tamper-evident run hash, and a source-freshness CI check. Adviser-style metrics (withdrawal rate, critical yield, replacement rate, narrative) only behind the `OutputPhrasing` lint. **Declined** (DECISIONS 2026-06-25): per-row/envelope encryption, a native MC accelerator, gov.uk scraping. Note: login rate-limiting the review flagged is already implemented.
- [x] **DECISIONS — mortality, assumptions, forecast mechanics:** all made. ONS cohort tables; FCA+DMS assumptions (signed off); dual drawdown strategy + cautious-40/60 default. See DECISIONS.md.
- [ ] **Build-time gov.uk verification pass** on every figure marked with a warning sign in the `TaxYearRegistry` helpers and parameter docblocks (income/NI bands, pension allowances, CGT/SDLT, PC/HB rates, IHT bands + April-2027 pensions-in-estate, care thresholds, SPA boundary dates) plus the ONS mortality and assumption sources (docs/ASSUMPTIONS.md, docs/MORTALITY.md). Do before go-live.
- [ ] **v1 modelling refinements** (deferred, listed under Current state → Known bugs): GIA/cash income tax + CGT-on-disposal, post-2031 threshold reindexing, per-scheme DB escalation, stochastic house/salary growth, SDLT surcharge timing in buy-vs-rent. Revisit when the app surfaces them.
- [ ] **Demo data:** Rob supplies the anonymised couple's figures later, entered via the UI, not hardcoded (field list in docs/PLAN.md "Data Rob supplies").
- [ ] **Results page — still open:** the **lump-sum tax-shock panel** ✅ and **compare-assumptions overlay** ✅ are now built; remaining: **2FA enrolment UI** (Fortify 2FA feature is on but has no screens, so no user can enable it) and **real-browser verification** of the ApexCharts canvases (the accessible tables/text are tested, the rendered chart is not). Run `npm run build` before viewing the app (`public/build` is gitignored).
- [ ] **Deferred earlier, still open:** numeric editing of assumption-set figures in Filament (currently curate-metadata-only, figures seeded from the engine library); tighten `User::canAccessPanel()` beyond "any authenticated user" before any public release (now more pressing — a Filament `UserResource` exists and public multi-user release is a genuine goal; the `interpret` toggle is admin-grantable there, so admin access must be locked down first).

## How to pick up
Run from the **project root** (the test runner shells out to a relative phpunit path, so it fails from `C:\Users\r`):
```powershell
Set-Location "C:\Dev\RetireForecast"
# NB: php / artisan / composer / npm are NOT on the Git Bash PATH on this machine — run them via the
# PowerShell tool (PHP 8.4 is provided by Laravel Herd). Bash is fine for git / grep / file ops. See CLAUDE.md.
php artisan test                            # everything: expect 235 passed (929 assertions)
php artisan test --testsuite=Engine        # engine only: expect 113 passed (547 assertions)
vendor/bin/pint --dirty                      # house style on changed files
npm run build                                # build assets (public/build is gitignored); `npm run dev` to watch
```
If `vendor/` is missing: `composer install`. If engine classes are not found, re-register the path package: `composer update retireforecast/finance-engine`. To use the app locally: migrate + `php artisan db:seed --class=AssumptionSetSeeder` (populates assumption sets), `npm run build`, then `php artisan serve` — a **local test user already exists** (`td@test.com` / `password`, disclaimer accepted) for quick sign-in, or register at `/register` and **accept the one-time guidance-only disclaimer at `/welcome`**. Build a forecast at `/scenarios/create`, run it on its results page. The full queued run needs a worker (`php artisan queue:listen`); the synchronous preview does not. Admin panel at `/admin` (any authenticated user) — the `UserResource` there toggles `can_interpret` to unlock the advice-style interpretation. Tests neutralise Vite, so they pass without a build.

## Sibling docs
| Doc | Purpose |
|-----|---------|
| docs/PLAN.md | The full approved implementation plan. Source of truth for scope, data model, tax rules, Monte Carlo design, phasing. Holds the "Sector-informed build plan (2026-06-25)". |
| docs/RESEARCH-cashflow-modelling.md | How the sector (Voyant/Timeline/CashCalc, PLSA/SMPI) solves edit/clone/compare, line-item expenditure, drill-down — what we adopt + the gaps it surfaced. |
| PRD.md | Goal, success criteria, scope, non-goals, open questions. |
| DATA-MODEL.md | Canonical data shape; what is materialised in code today vs planned. |
| DECISIONS.md | Append-only decision log with rationale. |
| CLAUDE.md | Root orient tripwire + build/test conventions. |
| C:\Users\r\.claude\plans\quiet-sleeping-gosling.md | Original plan-mode copy of docs/PLAN.md (same content). |

## Branch status
On `master`, local repo only (no remote, no PR). Personal local-first project; commit directly to `master`.
**Prototype tagged `prototype-v1` (a8f1f68)** before the rebuild — the recovery point (no remote, so the tag
is the only snapshot). Rebuild commits this session (newest first): `49637e4` results usable-vs-total +
cashflow ladder (C3); `b50f2a5` income-by-source on YearResult (A4); `12bd216` per-person longevity (A2);
`9316e7c` ongoing contributions + usable-vs-total terminal wealth (A1+A3). Built across a series of small committed milestones — engine (docs scaffold, NI+savings/dividends, pension suite, State Pension, SDLT+CGT, benefits, IHT+care, forecast+MonteCarlo+housing); app layer (persistence; Fortify+GDPR; Filament admin; forecast services; run persistence; queued runs + engine progress hook; UI foundation + auth; scenario builder; results page + ApexCharts; this session's builder UX + sector planning). **Everything described above is committed; the working tree is clean.** Recent commits (newest first): `6551219` results-card label clarity ("Total wealth left (incl. home)"); `84292c5` planning close-out (delta what-ifs / 3-tier budget / longevity / usable-vs-total); `2b5abc8` scenario drafts + person names + State Pension shortcut + sector research; `7219f72` import reconciliation guardrails + IWT CSP double-count fix. No remote; commit directly to `master`.

## Session log
_2026-06-25 (handover hygiene — no code)_ — Fresh session; `/handover` refresh + `/checkpoint`. The
post-rebuild checkpoint (`2587324`) had left the **Status** one-liner, **How to pick up**, and **What's
next** citing the pre-rebuild **224 / engine 105** and listing already-done Phase A2/C3 work as pending.
Verified the real state (suite **235/929**, engine **113/547**, tree clean) and reconciled those sections:
Status now reads "Phase A + C3 done, Phase B next"; What's next is reordered to the remaining B → C1 → C2 →
C4 → D with A2/C3 marked done. Also **corrected a date mis-stamp**: a prior 06-25 session had labelled the
rebuild work **2026-06-26** (tomorrow); git commit dates + the session clock confirm it was all **2026-06-25**,
so replaced 37 occurrences across the five docs (HANDOVER, DECISIONS, DATA-MODEL, PLAN, RESEARCH). No code
or test changes. **Next:** unchanged — Phase B (storage inversion), best begun with fresh context.
_2026-06-25 (REBUILD session — engine enrichment Phase A + results drill-down C3)_ — Fresh session to
rebuild the project; Rob framed existing code as a prototype and confirmed **no data/DB/shape must be
preserved** (build storage to the new world), to **interpret/keep the engine**, and to **interleave trust +
features** to a "natural slightly beyond MVP". Oriented on all docs; verified the baseline green (224/894);
tagged the prototype **`prototype-v1`** (a8f1f68). Locked the rebuild decisions (DECISIONS 2026-06-25 ×2).
Then built **Phase A** (engine), in four committed green milestones: **A1** `Account.ongoingContributions`
+ projector applies DC + account contributions funded from surplus (`9316e7c`); **A3** terminal
**usable**-vs-total wealth on `ForecastResult`/`SimulationResult` (`9316e7c`); **A2** per-person
`LongevityAdjustment` feeding the deterministic death age + the MC sampler via a cohort-table q(x) multiplier
(`12bd216`); **A4** `YearResult::incomeBySource` (8 canonical sources) + `fundShortfall` reporting
pension-vs-asset draws (`b50f2a5`). **A5 deferred to Phase D** (GIA/CGT needs return decomposition to avoid
double-counting). Then **Phase C3** (`49637e4`): the results page now shows **usable wealth (excl. home)**
beside total in the headline cards + buy-vs-rent table (fixing gotcha P end-to-end), and a deterministic
**cashflow ladder** (income-by-source → tax → spend → usable/total) as an accessible table + CSV, shown
immediately. Engine 105→113, full suite 224→235; pint clean throughout. **Next:** Phase B — the storage
inversion (`builder_state` source of truth + edit/clone), then C1 line items, C2 delta-child Compare, C4
income-floor/PLSA, D trust + go-live.
_2026-06-25 (builder UX from live use + sector research/planning)_ — Rob ran the app and gave UX
feedback. Built + tested: **scenario-draft auto-save** (`scenario_drafts` table + `ScenarioDraft`; the
builder saves form-state on every step move, resumes on return, deleted only on final save/discard —
retires the data-loss "Cancel"); **person names** (display-only `Person::$name`, persisted via
mapper/assembler, used as field labels); the **State Pension "full" shortcut** (pick a level → pre-fill
the sourced full rate + a gov.uk link); **import fixes** (accept `.xlsx`, panel open-by-default).
215 → **223 tests / 892 assertions**; pint clean; committed at the checkpoint. Then (no code, per Rob)
**sector research** — how Voyant/Timeline/CashCalc + PLSA/SMPI solve edit/clone/compare, line-item
expenditure, drill-down — captured in **docs/RESEARCH-cashflow-modelling.md** + a research-backed build
plan in **docs/PLAN.md**. Decision: scenario model = **base plan + delta what-if children + Compare**
(corrected from an initial full-copy lean to **delta/override** — single-source, "tweak 1–2 params", no
fork; DECISIONS 2026-06-25). Expenditure to go **3-tier** (essential / discretionary / self-investment),
framed as the goal not a %. Planning then **closed** (DECISIONS 2026-06-25 ×3 + docs/PLAN.md "Sector-informed
build plan"): anything-overridable **delta** children + "Create child" + stable IDs; 3-tier expenditure with
spent/saved; a per-person **longevity** adjustment. Live use also surfaced that the results cards **conflate
total wealth (incl. the home) with usable cash** (stay-put: 100% run out yet the highest "wealth left") —
**verified not a bug** (the engine separates `liquidWealth`/`propertyWealth`/`totalWealth`; success/depletion
is liquid-based, "wealth left" is total incl. home); fix is to **split usable vs total** + graph cashflow per
scenario (gotcha P). Also (live use): found + **fixed a real engine bug** — tax-free income streams (e.g.
DLA) were dropped from the "will the money last" calc (`PathProjector::incomeStreamsNominal` only summed
taxable streams), so income was understated and depletion overstated; now counted untaxed into net cash,
regression-tested (223 → **224 tests**). Captured the drill-down requirement (income by source + drawdown
allocation, so such gaps get caught) + a later product/adviser signposting idea in docs/PLAN.md. **Next:**
build the rebuild, starting with the scenario data-shape + edit/clone.
_2026-06-25 (data-layer integrity guardrails — golden fixtures + reconciliation invariants)_ — Reviewed
the project against the docs (suite verified green at 212; no drift beyond one stale "step 4 not
committed" branch line, fixed). Captured a gap analysis (tiered readiness) and Rob's standing rule
about a past project burned by **inconsistent aggregation of the same data** — added it to CLAUDE.md,
DECISIONS, PLAN and a memory. Then built the first guardrails: `tests/Fixtures/Import/GoldenWorkbooks.php`
(sanitised real-file fixtures, built from masked dumps of the real `.xlsx` so no PII is committed) +
`tests/Unit/Import/ImportReconciliationTest.php`, reconciling each importer's output to the sheet's own
stated totals. The guardrail immediately **caught two live wrong-aggregation bugs** in the IWT
`ConsciousSpendingPlan` importer (per-bucket "… TOTAL" double-counted → essential ~2×; `NET WORTH`
Investments/Savings miscounted as contributions); fixed by making a bucket's own TOTAL authoritative.
`PayAndExpenditures` + `RetireForecastTemplate` reconcile cleanly (real shapes locked in). 212 → **215
tests / 874 assertions**; pint clean. Also recorded the env fact that php runs via Herd/PowerShell (not
the bash PATH). **Next:** displayed-figure provenance test (panel == CSV == interpretation), an import
reconciliation panel for the user, and the forecast-boundary invariants (net proceeds, terminal wealth).
Not committed — run `/checkpoint` to land.
_2026-06-25 (interactive — `.xlsx` import + the personal "Pay and Expenditures" workbook)_ — Rob supplied the real IWT / Nischa / personal `.xlsx` files (gitignored under `docs/*.xlsx` — never commit). Read all three via stdlib (no openpyxl). Findings: IWT CSP is a US 4-bucket sheet (NET WORTH + INCOME incl. gross + Fixed/Investments/Savings/Guilt-Free, with `… TOTAL` rows + a net-worth section to avoid double-counting); **Nischa is a 50/30/20 formula dashboard** (deprioritised by Rob); the **personal workbook is already a buy-vs-rent comparison** (Demo Flat A=buy / Rental B=rent, + RC variants + a Net Worth tab). Built, per Rob's choices: (1) **`.xlsx` upload** via `phpoffice/phpspreadsheet` (app-layer; engine stays dependency-free) — a sheet-aware `Spreadsheet`/`SpreadsheetReader` reading Excel's cached values, profiles refactored onto it; (2) a **tab picker** for multi-tab workbooks; (3) a bespoke **`PayAndExpenditures`** profile. **Two real bugs caught only by running on the actual file** (Rob flagged my over-confidence): the expenditure header was first matched on bare "take home" (also in "Mum Take home"/"Combined Take home Pay" row labels) then on "Expenditure Item" (reused by the deductions header) — the unique anchor is **"% of Take Home Pay"**; fixed → essential reconciles at £24,600/yr. Income mapping verified live: State Pension→state pension (£190.00/wk), DLA→tax-free income, partner pension→annuity; lands on Person 1 with no start age (flagged). 199 → **212 tests / 857 assertions**; pint clean. Committed across: xlsx/sheet-aware refactor, bespoke profile, header-fix, tab picker, income mapping. **Next:** line-item-expenses decision; re-verify IWT CSP vs the real 2023 file; Nischa later.
_2026-06-25 (autonomous build session — lump-sum panel + scenario-builder wizard + spreadsheet-import infrastructure)_ — Resumed from the handover; found the true baseline was **red** (an in-tree WIP lump-sum panel left `scenario-results.blade.php` with an unbalanced `@if/@endif` — `HMRC@if(...)` glued the directive to a word so Blade skipped the opening `@if` but compiled the closing `@endif`). Fixed it, then shipped three committed, green stages. (1) **Lump-sum tax-shock panel** — `App\Forecast\LumpSumTaxShock` runs the engine's `FlexibleWithdrawalAssessor` on the earliest UFPLS/drawdown and renders the 25/75 split + Month-1 over-deduction + reclaim form; acceptance test reproduces HMRC worked example A through the app. (2) **Wizard** — split the 480-line builder into five free-navigation steps (server-side `@if($step===N)` + `wire:click`, so fully unit-testable with no browser), grouping savings + the home as **"Your net worth"**, with a11y (stepper `aria-current`, focusable headings/error summary, `aria-invalid`/`aria-describedby`, double-submit guard, `endAge ≥ startAge`, jump-to-first-error). Existing builder tests passed unchanged (they set properties + save, never navigate). (3) **Import** — `app/Import/` profile registry + `ImportResult` + `MoneyText` (exact-pence summing of monthly line items → annual); the **RetireForecast CSV** profile pre-fills spending + salary; **IWT + Nischa profiles stubbed** (`UncalibratedProfile`, refuse with a reason) pending sample exports. Then a fourth stage: (4) the **compare-assumptions overlay** — `App\Forecast\AssumptionComparison` runs the deterministic central projection under each shipped sourced set (FCA/DMS/OBR) and renders an accessible sensitivity table immediately (no run needed), via a new `ScenarioForecaster::deterministicWith()`. 182 → **199 tests / 827 assertions** (engine still 104); pint clean. Decisions logged (DECISIONS 2026-06-25 wizard/import entry). Wrote **docs/morning-worklist.md** for Rob (provide IWT/Nischa samples; line-item-expenses + XLSX-dependency calls). **Still owed:** IWT/Nischa calibration (blocked on samples); line-item expenses; a full per-field a11y sweep + axe/Pa11y CI.
_2026-06-25 (app layer phase 2 step 4 — compliance/disclaimer layer + interpretation toggle + no-silent-failure hardening)_ — Resumed from the handover, sanity-checked the suite green (157), then built Phase 2 step 4. (A) **Banned-phrasing guard:** `App\Compliance\OutputPhrasing` (directive-only patterns) + `BannedPhrasingTest`, a path/namespace **partition** check over every Blade view + all app PHP (exempting only the `App\Compliance` namespace and `interpretation`-named views), with a non-vacuity guard and a test proving the walled-off layer carries directive phrasing. The lint immediately caught (and we deleted) the unused stock `welcome.blade.php`. (B) **Disclaimers:** reusable `<x-disclaimer.result>` + `<x-signpost>` on every result + an output-mode label; the CSV export prefixed with the guidance-only disclaimer. (C) **First-run acknowledgement gate:** `EnsureDisclaimerAcknowledged` middleware → `/welcome` screen, `users.disclaimer_acknowledged_at`; GDPR routes left outside the gate. (D) **Interpretation toggle:** `users.can_interpret` (Filament `UserResource` `ToggleColumn`) → `interpret` Gate → walled-off `App\Compliance\Interpretation` service → `interpretation` partial, neutral by default. (E) **Hardening folded in:** GDPR `export()` now includes runs+results; `RunScenarioSimulation::failed()` lands a dead worker's run Failed; `ScenarioResults::currentRun()` owner-scoped. 157 → **177 tests / 743 assertions** (engine still 104); pint clean. Decisions logged (DECISIONS 2026-06-25 compliance entry): directive-only patterns + path partition; middleware gate over JS modal; reusable disclaimer/signpost components; admin-grant via `UserResource`. **Not yet committed.** Next: Phase 2 step 5 — demo preset + a11y CI + perf + PDF export, and (when the results page is next touched) the lump-sum tax-shock panel + compare-assumptions overlay still owed.
_2026-06-25 (review + planning, docs only — no code changed)_ — Reviewed the in-progress app layer (sanity-checked the suite green at 157, two read-only Explore passes over the UI + forecast/service layers, then verified the high-severity findings in code directly). Folded the verified refinements into the build rather than acting on them: docs/PLAN.md gained a "Refinements found in code review" subsection (compliance build test still unbuilt; lump-sum tax-shock panel + compare-assumptions overlay unrendered; GDPR **export** omits runs/results though erase cascades correctly; no job `failed()` handler so a dead worker strands a run in `Running`; `ScenarioResults::currentRun()` not owner-scoped; a11y error-association + form-UX gaps). Triaged a second-opinion external review (MS Copilot): adopted the engine-output-reuse wins (cashflow timeline, longevity distribution, stress-test, what-if sliders) + v2 modelling (annuitisation, care-cost stochasticity) + cheap hardening (CSP, run hash, source-freshness CI); **declined** envelope encryption / native MC accelerator / gov.uk scraping (DECISIONS); noted login rate-limiting is already implemented. Decided the **optional per-user interpretation ("advice-style") toggle** — admin-granted, off by default, walled-off `Interpretation` narrator, build test reframed as a partition check — and recorded that a "not financial advice" banner is necessary-but-not-sufficient (classification is by substance, not label), so the public view stays neutral and the toggle is self/family-only. Clarified intent: public multi-user release is a genuine goal, which raises the priority of `User::canAccessPanel()` tightening + run-ownership scoping. No code touched; suite still 157/690. Next (unchanged): Phase 2 step 4 — compliance/disclaimer layer + banned-phrasing build test, now incorporating the interpretation toggle.
_2026-06-24 (app layer phase 2 step 3 — Livewire UI + auth screens + ApexCharts results)_ — Resumed from the handover, sanity-checked the suite green (133), then built Phase 2 step 3 in three committed milestones. (A) Front-end foundation + real auth: app Blade layout (skip link, nav, disclaimer footer), ApexCharts bundled via npm + Alpine `chart` wrapper, real Fortify login/register/reset screens with `config/fortify.php` views flipped on, landing + `Dashboard`; `TestCase` neutralises Vite so view tests skip the gitignored build. (B) Scenario builder: `HouseholdAssembler` (lossless form-state → engine DTOs, money parsed to exact pence) + `ScenarioBuilder` Livewire form (full household + housing decision, validated, persisted encrypted); proven by rebuilding the rich `HouseholdFixture` exactly and a save→decrypt-to-identical-DTO round-trip. (C) Results page: `ScenarioResults` wired to `SimulationRunner` (preview sync, full queued + `wire:poll` + cancel) + `ResultPresenter` → headline text, Monte Carlo fan chart and buy-vs-rent comparison, each with an accessible `<table>` + CSV + signposting. 133 → **157 tests / 690 assertions** (engine still 104). Decisions logged: hand-rolled Livewire + separate assembler + charts-as-enhancement; full-page Livewire uses the Blade layout component (not `layouts::app`) + `withoutVite()` + registry-driven region guard. Deferred: compare-assumptions overlay, lump-sum tax-shock panel, real-browser chart check, 2FA UI. Next: Phase 2 step 4, the compliance/disclaimer layer + banned-phrasing build test.
_2026-06-24 (app layer phase 2 step 2 — forecast services + run persistence + queued runs)_ — Continued straight from step 1 (same session). Built the forecast/scenario services in three committed milestones: (A) `ScenarioForecaster` assembles engine inputs from a persisted scenario and runs deterministic / Monte Carlo / buy-vs-rent, reproducibly; (B) `SimulationRun` + `Result` persistence (encrypted `SimulationResult` per variant + frozen assumption snapshot, `SimulationResultMapper`); (C) `SimulationRunner` + `RunScenarioSimulation` job orchestrate create → run → persist with live progress + cancel (preview sync, full queued), enabled by a small non-breaking progress callback added to the engine `Simulator`/`HousingComparison`. 119 → **133 tests / 619 assertions** (engine 101 → 104). Decisions logged: run = 3-variant comparison → 3 Results (deterministic on demand), engine progress hook + throw-to-cancel, preview-sync/full-queued with driver-agnostic queue. Next: Phase 2 step 3, the Livewire UI + ApexCharts, wired to `SimulationRunner`.
_2026-06-24 (app layer phase 2 step 1 — persistence + auth + GDPR + Filament)_ — Resumed from the handover, sanity-checked the engine green (101), then built the whole of Phase 2 step 1 on top, in three committed milestones. (1) Encrypted DTO persistence: `app/Finance/Mapping/` mappers (the one place serialization lives, keeping the engine agnostic), Eloquent Household/Scenario/AssumptionSet with `encrypted:array` payloads + clear structural columns + to/from-DTO bridges, three migrations, the assumption-set seeder; tests prove a saved row decrypts to an identical DTO and the payload is a ciphertext envelope at rest. (2) Fortify (headless) + GDPR export/erase behind auth; anonymous use writes nothing. (3) Filament 5 admin (it pulled Livewire 4) — assumption-set resource + read-only tax-year audit page. 101 → **119 tests / 374 assertions**. Decisions logged: one-payload persistence + app-side mappers, withdrawals on the pension, SimulationRun/Result deferred, Fortify-headless, Filament-5/Livewire-4, assumption-set figures stay sourced. Next: Phase 2 step 2, the forecast/scenario services (where SimulationRun + Result persistence land).
_2026-06-24 (forecast + Monte Carlo + buy-vs-rent — engine complete)_ — Made the forecast-mechanics decisions (dual drawdown strategy; cautious-40/60 default). Built the deterministic `PathProjector` year-stepper (income assembly, per-person tax + NI, drawdown strategies with pension grossing-up, fiscal drag via nominal-internal/real-output, depletion detection), the seeded Monte Carlo (`Cholesky`/`ReturnModel`/`Simulator`, reproducible golden-master, success probability + fan chart + depletion rate), and the `HousingComparison` buy-vs-rent on identical seeds (rent + property running costs added to the projector). 89 → **101 engine tests**. The calculation engine is now feature-complete; next session starts the Laravel app layer (persistence, UI, charts, compliance, demo).
_2026-06-24 (assumptions + mortality)_ — Made the two gating decisions: embed ONS 2024-based cohort mortality (via period diagonal), and default assumptions = FCA real returns + DMS vols (researched, cited, Rob signed off). Built canonical domain DTOs, `AssumptionSetLibrary` (3 sets), the ONS mortality model (embedded period grid + `CohortLifeTable` + seeded `JointLifeSampler`), and docs ASSUMPTIONS.md/MORTALITY.md. 82 → 89 engine tests. Stopped before the forecast year-stepper to get Rob's call on forecast mechanics (drawdown order + default allocation). Sources for assumptions/mortality still need the build-time verification pass.
_2026-06-24 (deterministic engine complete)_ — Resumed from the engine foundation. Scaffolded the standard doc set. Built the rest of the deterministic engine, each as a tested, committed milestone: NI + combined savings/dividend income-tax stacking; the pension lump-sum suite (PCLS/UFPLS/drawdown, Month-1 emergency tax + reclaim forms, MPAA, annual allowance + taper) encoding worked examples A & B; State Pension with SPA-from-DOB; SDLT + CGT (PRR); benefits capital tariff + £16k cliff (worked example C); IHT (pensions-in-estate toggle) + care means-test. Went from 27 to **79 engine tests**, all green; worked examples A, B, C all pass. Stopped at the forecast year-stepper, which needs Rob's two decisions (mortality data approach + default assumption figures). Next: make those decisions, then build the domain DTOs and the forecast layer.
_2026-06-24 (engine foundation)_ — Planned the project end-to-end (plan approved, docs/PLAN.md). Scaffolded Laravel 13 + the framework-free finance-engine path package. Built the money layer, the 2025-26 / 2026-27 tax-year config, and the non-savings income-tax calculator. 27 engine tests green. Scope refined to local-first / personal / no-hardcoded-data.
