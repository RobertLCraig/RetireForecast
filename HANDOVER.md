# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue building the calculation engine and then the app around it. Read `docs/PLAN.md` first: it is the full approved plan and the source of truth for scope.

**Stage:** active
**Status:** Phase D go-live. Rebuild + Tier-1/Tier-2 + the results-chart rework are all built and signed off. A new **adviser-legibility workstream** is now the priority, opened from Rob's 2026-06-29 browser walkthrough: one **correctness** fix (housing + commute costs leak across the buy-vs-rent variants) plus four legibility gaps (life-event milestones, house-sale explainer, input-sanity notes, per-option "why"). See What's next + docs/PLAN.md + DECISIONS 2026-06-29.
_Last updated: 2026-06-29 (adviser-legibility: the explainer / show-your-working layer built — sale waterfall, assumptions panel, itemised spend; pending Rob's browser sign-off)._

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
- **In progress:** nothing mid-edit; tree clean.
- **Known bugs / broken:** none open (the five 2026-06-28 re-review findings are all resolved — see Session log + docs/PLAN.md "Review findings"). Documented v1 scope limits, all flagged in code: income tax England/Wales/NI only (Scotland throws); emergency tax models the over-deduction magnitude, not PAYE-table pennies; mortality grid ages 50–100 / years 2025–2074 with clamping + a non-ONS tail above 100 (cap 110); forecast taxes GIA dividends + cash interest annually AND realises CGT on GIA disposal (ISA tax-free; GIA/cash grow at capital only; v1 omits capital-loss relief + judges the CGT band on non-savings income); income-tax thresholds frozen until 2031, then indexed with inflation; DB escalation + triple lock as smooth growth factors; buy-vs-rent takes main-home CGT as £0 (PRR) and no SDLT surcharge; house/salary growth deterministic inside the Monte Carlo.

## What's next (in order)
The go-live critical path. Longer-tail and parked work is under Open items, not repeated here.
1. **Adviser-legibility workstream** — the priority from Rob's 2026-06-29 browser walkthrough (full detail: docs/PLAN.md "Adviser-legibility workstream (2026-06-29)"; decisions: DECISIONS 2026-06-29). None of it is an engine bug (determinism + mortality re-verified); it is cost placement + missing explanation. **Guiding principle (Rob): trust comes from explanation — every headline figure must be traceable on screen to its inputs/assumptions, so this sits above remaining go-live polish.** The **explainer / show-your-working layer is built (2026-06-29, pending Rob's browser sign-off):** the house-sale waterfall (proceeds decomposition + per-option destination, selling-cost rate shown beside the £), the assumptions panel (real-vs-nominal labelled, single-source blended return), and itemised per-year spend (essential/discretionary) on the results page — see DECISIONS. Remaining, in order:
   1. **[correctness] Contingent-cost placement.** Housing costs (mortgage payment, service charge, owner maintenance) and status costs (commute fuel) currently sit in shared `expenseProfile`, so they're charged in *every* buy-vs-rent variant — phantom mortgage + service charge in *sell & rent* and *buy outright*, commute that never stops at retirement. Give each cost one home tied to what it depends on (property/decision, or employment status), charged only while its condition holds; guard with reconciliation tests (property costs in zero post-sale years; commute zero from the retirement year).
   2. **Per-strategy cashflow ladder** — the year-by-year cashflow must show the differences *by housing strategy* (it is currently a single projection of the raw household that ignores the variant transforms). Needs a deterministic per-variant projection; pairs with step 1.
   3. Life-event **milestones** — show *when* retire / SPA start / pension access / house sale / each death happen (list + markers on the ladder and charts), from figures the engine already produces.
   4. **Input-sanity** notes — retirement age ≤ current age (salary dropped); longevity offset below current age (death within the year); **rate/£ validation** (the real couple's selling-cost rate applied as 20% = £70k vs ~2%, rent as £1,650/yr ≈ £137/mo likely monthly, both with no on-screen feedback). *(The sale waterfall now shows the selling-cost rate beside its £, so the 20% case is at least visible; active validation still to do.)*
   5. Per-option plain-English **"why"** narrative, milestone-anchored, lint-safe.
   6. **Real-time cost toggles** — switch individual cost lines (mortgage / service charge / commute) on/off and see the forecast move live.
2. **Finish the real-browser verification pass.** The a11y axe/Lighthouse sweep is underway — one finding fixed (the scrollable data-table wrappers are now keyboard-focusable via `tabindex="0"`; see docs/A11Y.md sweep log). PDF layout looked right to Rob; **2FA QR scan deferred by Rob**. NB the local DB has **0 completed runs**, so re-run a forecast before checking the Monte Carlo charts / PDF.
3. **Optional:** tighten the CSP `script-src` to nonces (Alpine CSP build) — needs the browser.

## Open items
Open decisions and parked work, off the immediate go-live path (which is under What's next).
- [ ] **Spreadsheet import** — the line-item expense-categories data-model decision; re-verify IWT CSP vs the real 2023 export (the fixture was built from a masked dump); Nischa deprioritised (`isAvailable()=false`); imported income → Person 1, no start age (by design, flagged). Real sample `.xlsx` live in gitignored `docs/*.xlsx` (never commit).
- [ ] **Assumption-set figures** are not numerically editable in Filament (curate-metadata-only; figures seeded from the engine library — editing one means re-sourcing with a new verified-on date).
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
| docs/RESEARCH-document-import.md | PARKED post-v1 feature: statement-driven onboarding + document import (sector evidence, document→builder-field map, gotchas). |
| PRD.md | Goal, success criteria, scope, non-goals, open questions. |
| DATA-MODEL.md | Canonical data shape; what is materialised in code today vs planned. |
| DECISIONS.md | Append-only decision log with rationale. |
| CLAUDE.md | Root orient tripwire + build/test conventions + "Doc hygiene" rules. |

## Branch status
On `master`, local repo only (no remote, no PR) — personal local-first project; commit directly to `master`. The pre-rebuild prototype is tagged **`prototype-v1` (a8f1f68)**, the only recovery snapshot. For the commit history use **`git log`** (it is the source of truth — not restated here, where it would drift); the recent trajectory is summarised in the Session log + DECISIONS.md.

## Session log
_Newest first. Keep only the recent live window here; older sessions are in `git log` + DECISIONS.md. Per-session figures are dated history and may stay._

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

_2026-06-28 (handover consolidation + doc-hygiene guard)_ — A doc-drift review found a stale test count in the
headline; the root cause was an append-only handover that restated derivable facts (counts, the commit list, the
file tree) in several places with no reconciliation. Consolidated the doc (~1097 → ~165 lines: dropped the
duplicated REBUILD callout, the mirrored file tree, the hand-kept commit lists and the old session-log tail — all
derivable from git/filesystem or duplicated in DECISIONS/Session log) and made the discipline enforceable rather
than manual: `tests/Feature/Docs/HandoverHygieneTest` fails the build on a test count in live prose, a finished
item lingering under "What's next", or a resolved `[x]` under "Open items", and CLAUDE.md gained a "Doc hygiene"
convention + "Green is the invariant". Also de-staled PRD/DATA-MODEL/PLAN. Commit `349fe20` (+ a follow-up that
de-duplicated the CLAUDE.md wording and disjoined What's next / Open items, per Rob's review).

_2026-06-28 (parked: statement-driven onboarding + document import — the local-AI question, reframed)_ — Rob
asked whether a local **Ollama** AI could run the forecasting/modelling. Investigated (web research): **no** —
chat LLMs are unreliable at arithmetic, non-deterministic, non-auditable and would break HMRC-to-the-penny +
reproducibility + sourcing + no-silent-failure; the real "AI forecasters" (time-series foundation models —
Chronos/TimesFM/Moirai) aren't Ollama models and still can't model UK tax law or a specific household; the
engine's deterministic-rules + Monte-Carlo design is already the right tool. Rob then reframed to the genuinely
useful idea: **upload documents** (bank/credit-card statements, payslips, benefit statements), extract +
pre-fill the wizard, ask only the remainder, and build the budget from **actual** spend rather than "average
user" figures. Captured as a **parked, post-v1** feature across three docs:
**[docs/RESEARCH-document-import.md](docs/RESEARCH-document-import.md)**, a **PARKED** section in docs/PLAN.md, and a
DECISIONS.md entry. Load-bearing calls: **transfer-matching is deterministic-only** (the "£1,258 card payment
looks like £2,516 of spend" internal-transfer double-count is the inconsistent-aggregation bug class the project
was burned by — not an LLM job); **categorisation is rules-first** with an **optional, walled-off, LOCAL-only LLM
assist** for the long tail (bank data never leaves the machine; a mis-tier never changes the grand total); benefit
statements must classify **taxable vs tax-free** (the DLA completeness rule); **actuals = the input baseline, PLSA
stays the benchmark**; Open Banking out of scope; architecture extends `app/Import/`, app-layer only. Docs-only.

_2026-06-28 (run-out verdict — keep the punch, fix the contradiction)_ — Rob wanted to keep the visceral
"you'll run out of money" framing while fixing the "55% runs out vs £659k wealth left" contradiction. Added
`ResultPresenter::runOutVerdict()` — a blunt plain-English verdict per option on the results cards, scaling from
"the money lasts in every simulated future" to "you'd very likely run out of money before the end", colour-coded
(role=alert at high risk). It is a **factual** statement about the simulated futures (anchored "on these figures"),
never a recommendation, so it stays guidance-side and clears the banned-phrasing partition lint. Kept the "Chance
of running out" label and rewrote the footnote to reconcile the two figures: "running out" = a future with ≥1 year
essentials weren't fully covered (may later recover); "wealth left" = the median end-of-life amount; "total"
includes any home still owned. Blade/PHP only.

_2026-06-28 (results-page fan chart fix + a wealth-over-time burndown overlay on Compare)_ — From live use of the
full 10k run. **(1) Fan chart was blank** while the bar chart rendered: the fan's `yaxis.labels.formatter` was set
to `null`, which ApexCharts calls as a function and throws, failing that chart's render. Removed it, and
**hardened the Alpine chart wrapper** (`resources/js/charts.js`): render is wrapped in try/catch — on failure it
logs and shows a visible "chart could not be drawn, the figures are in the table below" fallback rather than a
silent blank (charts are a progressive enhancement; the table is the source of truth). **(2) Burndown overlay:**
the Compare page now shows "Usable wealth over time" — the base + each delta-child what-if as one overlaid line via
`ResultPresenter::burndown()` (usable wealth excl. home = `liquidWealth + pensionWealth`, the SAME definition the
ladder uses, so no drift), reusing the one deterministic projection per plan the summary table already computes;
backed by an accessible year × plan table. **Verified live:** the full 10k run completes end-to-end and the
comparison bar chart renders under the CSP (so the CSP eyeball passes for that chart type).

_2026-06-28 (re-review findings RESOLVED — all five fixed)_ — Implemented every finding from the prior re-review.
**Finding 1 (PDF/screen MC divergence + provenance):** added `Scenario::latestCompletedRun()` as the one source
for the presented run; `ScenarioResults` and the PDF both read it, so they can't diverge; the PDF MC section stamps
mode/paths/seed/date; `DisplayedFigureProvenanceTest` extended to the PDF (the surface that wasn't covered — why
the divergence slipped through). **Finding 4:** `medianDepletionYear` now in `ResultPresenter::comparison()` rows →
reaches the comparison table + PDF. **Finding 3:** `PathProjector::disposeGiaSlice()` extracted — rounds the
realised gain and derives the consumed basis as the remainder (gain + basis == take exactly, no drift), pinned by a
multi-disposal conservation test. **Finding 5:** cash-interest conservation test added. **Finding 2 (freezeEndYear):**
implemented threshold un-freezing — the income-tax function is homogeneous degree 1 in (income, thresholds), so
`indexedTotalPence()` taxes income deflated to the freeze-end price level against the frozen thresholds and
re-inflates (factor 1.0 = identity during the freeze + for the HMRC unit tests); threaded through the main pass +
drawdown grossing-up; `ThresholdFreezeTest` pins it. Commits `fff3f07` + `86d5d82`.

_Earlier sessions (the engine build; app Phase 2 steps 1–4; rebuild Phases A, C3, B, C2, C1, C4; Phase D — A5, the
gov.uk figure-verification pass, the admin-panel lockdown, the Tier-1 data-integrity guardrails, and the earlier
Tier-2 items: demo seeder, 10k perf, CSP headers, 2FA UI, PDF export, a11y scaffold) — see `git log` and
DECISIONS.md for the dated detail._
