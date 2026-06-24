# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue building the calculation engine and then the app around it. Read `docs/PLAN.md` first: it is the full approved plan and the source of truth for scope.

**Stage:** active
**Status:** Phase 1 of 6 (engine, test-driven). Foundation done: money value objects, tax-year config, income-tax calculator. 27 engine tests passing. Roughly the first slice of the engine; no UI, persistence, Monte Carlo or domain DTOs yet.
_Last updated: 2026-06-24 (engine foundation committed)_

## Goal & success criteria
Full plan: [docs/PLAN.md](docs/PLAN.md). No PRD/DATA-MODEL/DECISIONS docs exist yet, so a PRD is owed (scaffold-docs is the next housekeeping step). Interim summary:

- **Goal:** let an older couple (one working, one retired) model whether to sell their home and either buy somewhere cheaper outright (invest the surplus) or sell and rent (invest all proceeds), and see the consequences of pension lump-sum withdrawals and whether their money lasts for life.
- **Headline outputs:** (1) the pension lump-sum tax shock (25% tax-free, marginal tax on the rest, plus the Month-1 emergency-tax overpayment and reclaim), and (2) running-out-of-money / longevity risk via Monte Carlo.
- **Success for Rob's own use:** a working **local** site where he can enter a real (known) couple himself, run buy-vs-rent, and read a trustworthy forecast. **No hardcoded client data in the repo.** If it proves useful he may later release it publicly for free.
- **Correctness bar:** the engine must reproduce known HMRC worked examples to the penny (examples A, B, C in docs/PLAN.md) before any UI is trusted.

## Canonical data shape
The single source of truth for the domain shape will be the engine's readonly DTOs under `packages/finance-engine/src/Dto/` (Eloquent models and Livewire forms map to/from these). **These DTOs are NOT built yet** — they are the next data-shape deliverable and must be created before persistence or UI. Full field lists are in docs/PLAN.md ("Data model (canonical shape)"). Agreed conventions, already honoured by the code that exists:

- **Money = integer pence**, never a float. Held by `Money` value object (GBP only). Rates = `Percent` (integer basis points). Dates = ISO `Y-m-d`. **Ages are derived from DOB + a reference date, never stored.**
- Planned entities (designed, not yet coded): Household, Person, Pension (subtype dc|db|state), Property, Account (isa|gia|cash), IncomeStream, ExpenseProfile, Scenario (variant buy_outright|rent|stay_put), AssumptionSet, SimulationRun, Result. Sensitive money/DOB/salary/pot/balance fields are flagged 🔒 in the plan and will be encrypted at rest when persistence is added.
- **What exists today as concrete shape:** `TaxYearConfig` and its parameter objects (`IncomeTaxParameters`, `DividendParameters`, `SavingsParameters`, `NationalInsuranceParameters`), plus `Money`/`Percent`. That is the only data shape currently materialised in code.

## Architecture / stack
- **Laravel 13.17** app at the repo root (SQLite locally). Livewire 3 + Filament + Fortify are planned (not yet installed); auth is deferred for local single-user use but the data model stays auth-ready.
- **`packages/finance-engine`**: a framework-free Composer **path package** (`retireforecast/finance-engine`, symlinked, required as `"*"`). Zero Laravel dependencies, no I/O, no clock. This is the product; the Laravel app is a shell around it. Keep it that way: the engine must never `use App\...` or `Illuminate\...`.
- Money handling is **hand-rolled integer pence** (see Decisions: brick/money was dropped over a dependency clash). PHPUnit 12 for tests.
- Still to come (see docs/PLAN.md): joint-life mortality (ONS cohort tables), Monte Carlo (seeded, reproducible), ApexCharts fan charts with accessible data tables, encrypted persistence + GDPR, compliance/disclaimer layer.

## Key files / structure
```
C:\Dev\RetireForecast
├─ docs/PLAN.md                         # the full approved plan (READ FIRST)
├─ HANDOVER.md                          # this file
├─ composer.json                        # path repo + autoload-dev wired for the engine
├─ phpunit.xml                          # has the "Engine" testsuite + engine in coverage
└─ packages/finance-engine
   ├─ composer.json                     # standalone, version 0.1.0, php ^8.3, phpunit dev
   └─ src
      ├─ Money/{Money,Percent,IntMath,RoundingMode}.php
      ├─ TaxYear/{TaxYearConfig,TaxYearRegistry,RegionProfile,
      │           IncomeTaxParameters,DividendParameters,
      │           SavingsParameters,NationalInsuranceParameters}.php
      └─ Tax/{IncomeTaxCalculator,IncomeTaxResult}.php
   └─ tests/{Money/*,Tax/IncomeTaxCalculatorTest}.php
```
Engine namespace: `RetireForecast\FinanceEngine\...`. Tests namespace: `RetireForecast\FinanceEngine\Tests\...` (registered in the root app's `autoload-dev`).

## Decisions locked
- **Local-first, personal use, no hardcoded client data.** Rob enters the couple via the UI himself. Any first-run sample must be obviously fictional/synthetic. Possible free public release later, so do not design accounts out, just defer them.
- **Modelling depth (locked earlier):** HMRC-accurate deterministic engine PLUS Monte Carlo, with **stochastic joint-life mortality**. Pensions: DC, DB and State Pension. Housing: buy-cheaper-outright vs rent, on identical seeds. IHT/legacy is **in as a toggle** (incl. pensions entering the estate from April 2027). Assumptions are a runtime/display choice across several sourced sets (FCA default), not baked in.
- **Regulatory posture: education/guidance only, never a personal recommendation.** A build-time test must fail if any result template contains banned recommendation phrasing. Signpost Pension Wise / MoneyHelper.
- **Money = hand-rolled integer pence.** `brick/money` could not resolve against `brick/math` 0.18 in the Laravel 13 lock; the plan already listed integer-pence as the primary option, and zero-dependency strengthens the engine's isolation. Do not re-add brick/money without checking that clash.
- **Engine is framework-free and lives in a path package.** Tests run as pure `PHPUnit\Framework\TestCase`, no Laravel bootstrap. This is what makes the HMRC worked-example tests trustworthy.
- **Tax figures are versioned per tax year with a source + verified-on date.** Two stale-brief corrections are already baked in: income-tax freeze now runs to **April 2031** (not 2028); **dividend rates rise in 2026/27** (8.75→10.75 ordinary, 33.75→35.75 upper).
- **The app is Laravel 13** (installer pulled 13.17), not 12. The initial commit message says "Laravel 12 skeleton"; that label is wrong but harmless, left as-is.

## Current state
- **Done:** Laravel 13 app scaffolded (SQLite migrated). finance-engine path package wired in. `Money`/`Percent`/`IntMath`/`RoundingMode` with explicit per-call rounding. `TaxYearConfig` + `TaxYearRegistry` for 2025-26 and 2026-27 (England/Wales/NI; Scotland throws rather than faking rUK bands). `IncomeTaxCalculator` (non-savings income) with personal-allowance taper and band stacking. **27 tests / 60 assertions passing.**
- **In progress:** nothing mid-edit; the tree is committed and clean (commit `71cfc8f`).
- **Known bugs / broken:** none known. The income-tax calculator currently handles **non-savings income only** by design; savings and dividends stack on top and are not built yet (see What's next).

## What's next (in order)
1. **Scaffold the project doc set** (scaffold-docs): PRD.md, DATA-MODEL.md, DECISIONS.md and a root CLAUDE.md orient tripwire. Port Goal/Data-model/Decisions out of docs/PLAN.md into them. (Todo #2.)
2. **NI / Savings / Dividend calculators** + tests. Savings and dividends stack on the income-tax bands already consumed; `IncomeTaxCalculator` exposes the granted allowance and is built for this extension. (Todo #6.)
3. **Pension calculators:** PCLS, UFPLS, drawdown, EmergencyTax (Month-1 + P55/P50Z/P53Z determination), MPAA, AnnualAllowance. Encode **worked examples A and B** from docs/PLAN.md as the acceptance gate. (Todo #7.)
4. **State Pension** incl. SPA-computed-from-DOB (66→67 transition by date of birth), taxable, triple lock. (Todo #8.)
5. **CGT (Private Residence Relief) + SDLT** incl. the additional-property surcharge timing edge. (Todo #9.)
6. **Benefits:** Pension Credit capital tariff + the £16k HB/CTS cliff; **worked example C**. (Todo #10.)
7. **IHT (toggle) + Care.** Then the forecast year-stepper, joint-life mortality, Monte Carlo, persistence/auth, Filament admin, Livewire UI + ApexCharts, compliance layer, then a verified manual couple entry end-to-end. Full order in the todo list / docs/PLAN.md phases 2-6.

## Blockers / open questions
- [ ] **Default assumption-set numbers need Rob's source pick.** He chose FCA projection rates as the default baseline, with DMS/EGS and OBR/BoE as alternative compare sets. The actual return/volatility/inflation figures still need sourcing and citing before any forecast is shown as real.
- [ ] **Build-time gov.uk verification pass** on every figure marked ⚠️ in docs/PLAN.md (Scottish bands, NI 26/27, tapered-AA thresholds, LSDBA, access-age-57 date, CGT rates + £3,000 AEA, LBTT/LTT, PC/HB weekly rates 26/27, IHT NRB/RNRB freeze-end + the April-2027 pensions-in-estate change, care thresholds). Do this before go-live, not before each calculator.
- [ ] **Demo data:** Rob will supply the anonymised real couple's figures (exact field list in docs/PLAN.md "Data Rob supplies"). Not needed until the scenario-builder UI exists; entered through the UI, not hardcoded.

## How to pick up
Run from the **project root** (the test runner shells out to a relative phpunit path, so it fails from `C:\Users\r`):
```powershell
Set-Location "C:\Dev\RetireForecast"
php artisan test --testsuite=Engine        # expect: 27 passed (60 assertions)
```
If `vendor/` is missing: `composer install`. If the engine classes are not found, re-register the path package: `composer update retireforecast/finance-engine`.
To run everything (engine + Laravel's default suites): `php artisan test`.

## Sibling docs
| Doc | Purpose |
|-----|---------|
| docs/PLAN.md | The full approved implementation plan. Source of truth for scope, data model, tax rules, Monte Carlo design, phasing. |
| PRD.md / DATA-MODEL.md / DECISIONS.md / CLAUDE.md | **Owed** (not yet created). First housekeeping task, via scaffold-docs. |
| (also) C:\Users\r\.claude\plans\quiet-sleeping-gosling.md | Original plan-mode copy of docs/PLAN.md (same content). |

## Branch status
On `master`, local repo only (no remote, no PR). Personal local-first project; commit directly to `master`. Two commits: `0fddf85` (skeleton), `71cfc8f` (engine foundation).

## Session log
_2026-06-24 (engine foundation)_ — Planned the project end-to-end (plan approved, in docs/PLAN.md). Scaffolded Laravel 13 + the framework-free finance-engine path package. Built the money layer (integer pence), the 2025-26 / 2026-27 tax-year config, and the non-savings income-tax calculator. 27 engine tests green. Scope refined mid-session to local-first / personal / no-hardcoded-data. Committed `71cfc8f`. Next: scaffold the doc set, then NI/savings/dividend, then the pension lump-sum calculators (worked examples A & B).
