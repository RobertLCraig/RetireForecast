# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue building the calculation engine and then the app around it. Read `docs/PLAN.md` first: it is the full approved plan and the source of truth for scope.

**Stage:** active
**Status:** **The entire calculation engine is COMPLETE and tested** (Phase 1 done). Deterministic tax/pension/benefits/property/IHT/care engine (HMRC worked examples A, B, C pass), canonical DTOs, signed-off sourced assumptions, ONS cohort mortality, the deterministic forecast year-stepper, the seeded Monte Carlo (reproducible golden-master), and the buy-vs-rent comparison are all built. **101 engine tests / 313 assertions passing.** Next is the Laravel **application layer** (Phases 2-6): persistence + auth + encryption + GDPR, Livewire UI + ApexCharts, compliance/disclaimer layer, demo preset, polish. A clean fresh-session boundary — the engine is the product, the app is the shell around it.
_Last updated: 2026-06-24 (calculation engine complete; app layer is the next phase)_

## Goal & success criteria
Full plan: [docs/PLAN.md](docs/PLAN.md); PRD: [PRD.md](PRD.md). Summary:

- **Goal:** let an older couple (one working, one retired) model whether to sell their home and either buy somewhere cheaper outright (invest the surplus) or sell and rent (invest all proceeds), and see the consequences of pension lump-sum withdrawals and whether their money lasts for life.
- **Headline outputs:** (1) the pension lump-sum tax shock (25% tax-free, marginal tax on the rest, plus the Month-1 emergency-tax overpayment and reclaim), and (2) running-out-of-money / longevity risk via Monte Carlo.
- **Success for Rob's own use:** a working **local** site where he can enter a real (known) couple himself, run buy-vs-rent, and read a trustworthy forecast. **No hardcoded client data in the repo.** If it proves useful he may later release it publicly for free.
- **Correctness bar:** the engine reproduces known HMRC worked examples to the penny (examples A, B, C in docs/PLAN.md). **Met** for the deterministic engine.

## Canonical data shape
The single source of truth for the domain shape will be the engine's readonly DTOs under `packages/finance-engine/src/Dto/` (Eloquent models and Livewire forms map to/from these). See [DATA-MODEL.md](DATA-MODEL.md) and docs/PLAN.md for full field lists. Conventions, honoured by all existing code:

- **Money = integer pence**, never a float. Held by `Money` value object (GBP only). Rates = `Percent` (integer basis points). Dates = ISO `Y-m-d`. **Ages are derived from DOB + a reference date, never stored.**
- Planned entities (designed, not yet coded): Household, Person, Pension (subtype dc|db|state), Property, Account (isa|gia|cash), IncomeStream, ExpenseProfile, Scenario (variant buy_outright|rent|stay_put), AssumptionSet, SimulationRun, Result. Sensitive money/DOB/salary/pot/balance fields are flagged for encryption at rest when persistence is added.
- **What exists today as concrete shape:** the money layer (`Money`/`Percent`/`IntMath`/`RoundingMode`) and the full per-year `TaxYearConfig` spine with parameter objects for income tax, dividends, savings, NI, **pension** (LSA/MPAA/AA/taper/min age), **state pension**, **SDLT**, **CGT**, **benefits** (capital rules), **IHT** and **care**. Plus per-calculator result objects (e.g. `FlexibleWithdrawalResult`, `IhtResult`, `CapitalAssessmentResult`) and shared `Support\Warning`/`WarningCode`. **The domain DTOs (Household/Person/Pension/Scenario/AssumptionSet/etc.) are still NOT built** — they are the next data-shape deliverable, gated on the two pending decisions below.

## Architecture / stack
- **Laravel 13.17** app at the repo root (SQLite locally). Livewire 3 + Filament + Fortify are planned (not yet installed); auth deferred for local single-user use but the data model stays auth-ready.
- **`packages/finance-engine`**: a framework-free Composer **path package** (`retireforecast/finance-engine`, symlinked, required as `"*"`). Zero Laravel dependencies, no I/O, no clock. This is the product; the Laravel app is a shell around it. Keep it that way: the engine must never `use App\...` or `Illuminate\...`.
- Money handling is **hand-rolled integer pence** (see Decisions: brick/money was dropped over a dependency clash). PHPUnit 12 for tests.
- Still to come (see docs/PLAN.md): joint-life mortality (ONS), Monte Carlo (seeded, reproducible), ApexCharts fan charts with accessible data tables, encrypted persistence + GDPR, compliance/disclaimer layer.

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
Engine namespace: `RetireForecast\FinanceEngine\...`. Tests namespace: `RetireForecast\FinanceEngine\Tests\...` (registered in the root app's `autoload-dev`). Pint enforces house style (snake_case test methods, no-space concatenation); run `vendor/bin/pint packages/finance-engine` after adding files.

## Decisions locked
See [DECISIONS.md](DECISIONS.md) for the full log. Highlights:
- **Local-first, personal use, no hardcoded client data.** Rob enters the couple via the UI himself. Any first-run sample must be obviously fictional. Possible free public release later, so do not design accounts out, just defer them.
- **Modelling depth:** HMRC-accurate deterministic engine PLUS Monte Carlo, with **stochastic joint-life mortality**. Pensions: DC, DB and State Pension. Housing: buy-cheaper-outright vs rent on identical seeds. IHT/legacy **in as a toggle** (incl. pensions entering the estate from April 2027). Assumptions are a runtime/display choice across several sourced sets (FCA default), not baked in.
- **Regulatory posture: education/guidance only, never a personal recommendation.** A build-time test must fail if any result template contains banned recommendation phrasing. Signpost Pension Wise / MoneyHelper.
- **Money = hand-rolled integer pence** (brick/money dropped over a brick/math clash). **Engine is framework-free in a path package.** **Tax figures versioned per tax year with source + verified-on.** Two stale-brief corrections baked in: income-tax freeze now to April 2031; dividend rates rise in 2026/27.
- **Savings + dividends in one combined income-tax pass** (`IncomeTaxCalculator::compute`), not separate calculators — the band stacking demands it.
- **The app is Laravel 13** (installer pulled 13.17), not 12; the initial commit's "Laravel 12" label is wrong but harmless.

## Current state
- **Done:** Laravel 13 app scaffolded (SQLite migrated). finance-engine path package wired in. Standard doc set scaffolded. **The entire deterministic engine is built and tested:** money layer; full per-year `TaxYearConfig`/`TaxYearRegistry` (2025-26 + 2026-27, England/Wales/NI; Scotland throws); income tax (incl. combined savings/dividend stacking) + NI; pension lump-sum suite (PCLS/UFPLS/drawdown, Month-1 emergency tax + P55/P50Z/P53Z, MPAA, annual allowance + taper) — **worked examples A & B**; State Pension (SPA-from-DOB transition, deferral, triple lock); SDLT (+surcharge) and CGT (PRR); benefits capital tariff + £16k cliff — **worked example C**; IHT (pensions-in-estate toggle) and care means-test. **79 tests / 188 assertions passing.**
- **Also done:** canonical DTOs (`src/Dto/`, incl. `HousingAction`); `Assumptions/AssumptionSetLibrary` (3 signed-off sets); `Mortality/` (embedded ONS period q(x), `CohortLifeTable`, seeded `JointLifeSampler`); `Forecast/` (`PathProjector` year-stepper + `DeterministicForecaster`, `DrawdownStrategy`, `PortfolioAllocation` cautious-40/60 default, `ForecastSettings`, `PathDraws`/`DeterministicPathDraws`, `YearResult`/`ForecastResult`); `MonteCarlo/` (`Cholesky`, `ReturnModel`, `SampledPathDraws`, `Simulator`, `SimulationResult`); `Housing/HousingComparison` (buy-vs-rent on identical seeds). **101 tests / 313 assertions.**
- **In progress:** nothing mid-edit; tree committed and clean. The calculation engine is feature-complete; the Laravel app layer has not been started.
- **Known bugs / broken:** none known. Documented v1 scope limits (all flagged in code): income tax is England/Wales/NI only (Scotland throws); emergency tax models the over-deduction magnitude, not PAYE-table pennies; mortality grid ages 50–100 / years 2025–2074 with clamping + a non-ONS tail above 100 (cap 110); forecast taxes non-savings income only (GIA/cash income tax + CGT-on-disposal deferred; ISA tax-free; pots grow at total return); tax thresholds held frozen for the whole projection; DB escalation + triple lock as smooth growth factors; buy-vs-rent takes main-home CGT as £0 (PRR) and no SDLT surcharge; house/salary growth deterministic inside the Monte Carlo.

## What's next (the application layer — docs/PLAN.md phases 2-6)
1. **Persistence + auth + encryption + GDPR.** Eloquent models mapping to/from the `src/Dto/` DTOs (do NOT redefine the shape); one encrypted JSON payload per scenario (`encrypted:array`) + clear structural columns; Fortify; anonymous-vs-saved; GDPR export + hard delete. Filament admin for AssumptionSet + tax-year config audit.
2. **Forecast/scenario services in the app** that hand the engine a `Household` + `ForecastSettings` + `AssumptionSet` and run `DeterministicForecaster`, `Simulator`, and `HousingComparison`. Queue (Horizon) the full 10k-path runs with live progress (`wire:poll`) and a cancel; sync ~1k-path preview. Nothing long-running may run silently.
3. **Livewire scenario builder + result views; ApexCharts** fan chart + buy-vs-rent + compare-assumptions overlay, each with the mandatory accessible `<table>` and headline numbers as text first (WCAG 2.1 AA).
4. **Compliance/disclaimer layer + the banned-phrasing build test** (fail the build if any result template contains a personal recommendation); Pension Wise / MoneyHelper signposting beside pension/benefits outputs.
5. **Demo preset** (Rob's anonymised couple, entered via the UI, not hardcoded), a11y CI (axe/Pa11y), 10k-path perf tuning, PDF export.

## Blockers / open questions
- [x] **DECISIONS — mortality, assumptions, forecast mechanics:** all made. ONS cohort tables; FCA+DMS assumptions (signed off); dual drawdown strategy + cautious-40/60 default. See DECISIONS.md.
- [ ] **Build-time gov.uk verification pass** on every figure marked with a warning sign in the `TaxYearRegistry` helpers and parameter docblocks (income/NI bands, pension allowances, CGT/SDLT, PC/HB rates, IHT bands + April-2027 pensions-in-estate, care thresholds, SPA boundary dates) plus the ONS mortality and assumption sources (docs/ASSUMPTIONS.md, docs/MORTALITY.md). Do before go-live.
- [ ] **v1 modelling refinements** (deferred, listed under Current state → Known bugs): GIA/cash income tax + CGT-on-disposal, post-2031 threshold reindexing, per-scheme DB escalation, stochastic house/salary growth, SDLT surcharge timing in buy-vs-rent. Revisit when the app surfaces them.
- [ ] **Demo data:** Rob supplies the anonymised couple's figures later, entered via the UI, not hardcoded (field list in docs/PLAN.md "Data Rob supplies").

## How to pick up
Run from the **project root** (the test runner shells out to a relative phpunit path, so it fails from `C:\Users\r`):
```powershell
Set-Location "C:\Dev\RetireForecast"
php artisan test --testsuite=Engine        # expect: 101 passed (313 assertions)
vendor/bin/pint packages/finance-engine     # house style after adding files
```
If `vendor/` is missing: `composer install`. If engine classes are not found, re-register the path package: `composer update retireforecast/finance-engine`.
To run everything (engine + Laravel's default suites): `php artisan test`.

## Sibling docs
| Doc | Purpose |
|-----|---------|
| docs/PLAN.md | The full approved implementation plan. Source of truth for scope, data model, tax rules, Monte Carlo design, phasing. |
| PRD.md | Goal, success criteria, scope, non-goals, open questions. |
| DATA-MODEL.md | Canonical data shape; what is materialised in code today vs planned. |
| DECISIONS.md | Append-only decision log with rationale. |
| CLAUDE.md | Root orient tripwire + build/test conventions. |
| C:\Users\r\.claude\plans\quiet-sleeping-gosling.md | Original plan-mode copy of docs/PLAN.md (same content). |

## Branch status
On `master`, local repo only (no remote, no PR). Personal local-first project; commit directly to `master`. Engine built across a series of small committed milestones (docs scaffold, NI+savings/dividends, pension suite, State Pension, SDLT+CGT, benefits, IHT+care).

## Session log
_2026-06-24 (forecast + Monte Carlo + buy-vs-rent — engine complete)_ — Made the forecast-mechanics decisions (dual drawdown strategy; cautious-40/60 default). Built the deterministic `PathProjector` year-stepper (income assembly, per-person tax + NI, drawdown strategies with pension grossing-up, fiscal drag via nominal-internal/real-output, depletion detection), the seeded Monte Carlo (`Cholesky`/`ReturnModel`/`Simulator`, reproducible golden-master, success probability + fan chart + depletion rate), and the `HousingComparison` buy-vs-rent on identical seeds (rent + property running costs added to the projector). 89 → **101 engine tests**. The calculation engine is now feature-complete; next session starts the Laravel app layer (persistence, UI, charts, compliance, demo).
_2026-06-24 (assumptions + mortality)_ — Made the two gating decisions: embed ONS 2024-based cohort mortality (via period diagonal), and default assumptions = FCA real returns + DMS vols (researched, cited, Rob signed off). Built canonical domain DTOs, `AssumptionSetLibrary` (3 sets), the ONS mortality model (embedded period grid + `CohortLifeTable` + seeded `JointLifeSampler`), and docs ASSUMPTIONS.md/MORTALITY.md. 82 → 89 engine tests. Stopped before the forecast year-stepper to get Rob's call on forecast mechanics (drawdown order + default allocation). Sources for assumptions/mortality still need the build-time verification pass.
_2026-06-24 (deterministic engine complete)_ — Resumed from the engine foundation. Scaffolded the standard doc set. Built the rest of the deterministic engine, each as a tested, committed milestone: NI + combined savings/dividend income-tax stacking; the pension lump-sum suite (PCLS/UFPLS/drawdown, Month-1 emergency tax + reclaim forms, MPAA, annual allowance + taper) encoding worked examples A & B; State Pension with SPA-from-DOB; SDLT + CGT (PRR); benefits capital tariff + £16k cliff (worked example C); IHT (pensions-in-estate toggle) + care means-test. Went from 27 to **79 engine tests**, all green; worked examples A, B, C all pass. Stopped at the forecast year-stepper, which needs Rob's two decisions (mortality data approach + default assumption figures). Next: make those decisions, then build the domain DTOs and the forecast layer.
_2026-06-24 (engine foundation)_ — Planned the project end-to-end (plan approved, docs/PLAN.md). Scaffolded Laravel 13 + the framework-free finance-engine path package. Built the money layer, the 2025-26 / 2026-27 tax-year config, and the non-savings income-tax calculator. 27 engine tests green. Scope refined to local-first / personal / no-hardcoded-data.
