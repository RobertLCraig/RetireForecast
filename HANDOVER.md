# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue building the calculation engine and then the app around it. Read `docs/PLAN.md` first: it is the full approved plan and the source of truth for scope.

**Stage:** active
**Status:** Rebuild through **C4 done**; now in **Phase D (trust + go-live)**. **A5 complete** — GIA dividends + cash interest taxed annually (asset grows at capital only, conservation tested) **and CGT realised on GIA disposal** (pro-rata gain vs cost basis, shared £3k AEA, 18/24% by band; CGT-incidence tested). GIA/CGT no longer deferred. **Forecast-boundary reconciliation invariants now built** (Tier-1 data-integrity): a home sale reconciles to net proceeds + its deductions, and total wealth is derived from liquid + pension + property every year (which caught + fixed a 1-pence round-of-sum drift in `PathProjector`). **Displayed-figure provenance also built** — the fan/ladder CSVs reproduce the panel tables figure-for-figure and the interpretation shares the panel's one `%` formatter (`DisplayedFigureProvenanceTest`). **The user-facing import reconciliation panel is now built too** — on import, each aggregated total is shown beside the sheet's own independent figure for the same quantity, and any divergence turns the block red (`ReconciliationLine` value object + `ImportResult::reconciliation` + the three calibrated profiles emit it; a deliberately-inconsistent golden fixture + its Livewire twin prove a mismatch is surfaced, not silent) — which **completes Tier-1 (trust)**. Suite **330 green** (engine 137 / app 193). **Figure-verification pass DONE (2026-06-27)** — every ⚠️ statutory figure re-confirmed against gov.uk and stamped `verified_on: 2026-06-27`; **no value changed** (all already correct), and the **April-2027 pensions-in-IHT change is now enacted** (Finance Act 2026). **Admin-panel lockdown DONE (2026-06-27)** — `canAccessPanel()` gated on a new `is_admin` flag (first admin via `php artisan user:make-admin`). **Phase D Tier-2 STARTED (2026-06-28)** — the **demo preset/seeder** is built (`App\Demo\DemoScenario` + `DemoScenarioSeeder`: an obviously-fictional sample plan + one delta-child what-if, idempotent, production-safe), **and the 10k-path Monte Carlo perf item is done** — a lean integer `IncomeTaxCalculator::totalPence()` sharing one band core with `compute()` (the projector's hot tax calls route through it; byte-identical, pinned by a 1,120-cell equivalence test) cut a 10k run **13.9 s → 8.9 s interpreted**, and the queue worker should run with **OPcache JIT** (off by default here) for **→ 4.75 s** (2.9× vs original). **Phase D Tier-2 — security headers DONE (2026-06-28)** — a compatible-by-construction **Content-Security-Policy** + static hardening headers (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`) on the `web` group via `App\Http\Middleware\SecurityHeaders` (policy + toggles in `config/security.php`; Filament `/admin` out of scope). **Phase D Tier-2 — PDF export + a11y CI DONE (2026-06-28), completing the Tier-2 BUILD** — results download as a PDF (`ScenarioPdfController` + `pdf.results` via `barryvdh/laravel-dompdf`, reusing the on-screen `ResultPresenter` so figures can't drift), and the accessibility gate is scaffolded (`Pa11y CI` axe-only over WCAG2AA + `docs/A11Y.md`). **A local a11y sweep then ran (2026-06-28)** and fixed **3 real WCAG AA contrast failures** (`text-gray-400/300` → `text-gray-600/500` + `aria-hidden`); the authoritative check is **in-browser axe DevTools / Lighthouse** (this machine's `ignore-scripts=true` blocks headless browser binaries, and Pa11y's bundled axe-4.2 emits false positives). Earlier same day: 2FA enrolment UI (`App\Livewire\AccountSecurity`), CSP/security headers, 10k perf, demo seeder. Suite **359 green** (engine 143 / app 216). **Verification pass underway (2026-06-28):** `npm run build` + `php artisan serve` confirmed working; a11y swept (3 fixes); the full 10k run was confirmed end-to-end after a **queued-run-needs-a-worker UX gap** was found (full runs need `php artisan queue:work`; now backlogged in docs/PLAN.md "Go-live UX backlog"). **Remaining browser eyeballs:** the 2FA QR scan + the PDF layout; then optionally tighten the CSP `script-src` to nonces. Phase D / go-live is otherwise essentially complete. _The narrative that follows is the full build history; the live picture is the **▶ REBUILD** callout below + the newest session-log entry._

_Pre-rebuild prototype history:_ Engine complete (Phase 1); **Phase 2 steps 1–4 of the app layer are in.** Step 1: encrypted DTO persistence, Fortify auth, GDPR, Filament admin. Step 2: forecast/scenario services (`ScenarioForecaster`, `SimulationRun`/`Result` persistence, `SimulationRunner` + queued job with progress + cancel). Step 3: the **Livewire UI + ApexCharts** (auth screens, scenario builder, results page with fan chart + buy-vs-rent, each as text + accessible `<table>` + CSV + signposting). Step 4 (this session): the **compliance/disclaimer layer** — `App\Compliance\OutputPhrasing` directive-only banned-phrase lint + a **partition build test** over every view and app PHP file; a **first-run acknowledgement gate** (`EnsureDisclaimerAcknowledged` middleware + `users.disclaimer_acknowledged_at` + a dedicated screen); reusable `<x-disclaimer.result>` + `<x-signpost>` on every result and a disclaimer prefix on the CSV export; the **admin-granted, off-by-default interpretation toggle** (`users.can_interpret` → `interpret` Gate → walled-off `App\Compliance\Interpretation` service + `interpretation`-named partial, set via a Filament `UserResource` toggle). Also folded in the tagged **no-silent-failure hardening**: GDPR `export()` now includes runs+results, `RunScenarioSimulation::failed()` lands a dead worker's run in Failed, `ScenarioResults::currentRun()` is owner-scoped. **Full suite at that point: 224 tests / 894 assertions (105 engine + 119 app)** — now **235 / 929** (engine 113) after the rebuild; see the callout. This autonomous session landed several committed stages on top of step 4: (1) the **lump-sum tax-shock panel** (headline output #1, `App\Forecast\LumpSumTaxShock` reproducing worked example A through the app) is now **rendered** on the results page; (2) the scenario builder is a **free-navigation wizard** — five steps (About & people; Pensions & income; **Your net worth** = savings + the home; Spending; The decision) with a11y (stepper `aria-current`, focusable headings + error summary, `aria-invalid`/`aria-describedby`, Save double-submit guard, `endAge ≥ startAge`, jump-to-first-error on save); (3) a **spreadsheet-import** layer (`app/Import/`) — an `ImportProfile` registry with the calibrated **RetireForecast CSV** profile (pre-fills spending + salary in exact pence), the **IWT Conscious Spending Plan** profile calibrated to its published structure (header-driven, frequency-aware; Fixed→essential, Guilt-Free→discretionary, net-income so no gross salary), and **Nischa stubbed** pending a sample; (4) the **compare-assumptions overlay** (`App\Forecast\AssumptionComparison`) — the central best-estimate projection under each shipped sourced set (FCA / DMS / OBR), rendered immediately as an accessible sensitivity table; (5) the **IWT CSP import** made live (`$`-currency fix in `MoneyText`); (6) **`.xlsx` import + the personal workbook** — added `phpoffice/phpspreadsheet` (uploads can be `.xlsx`, app-layer only), a sheet-aware `Spreadsheet`/`SpreadsheetReader` (reads Excel's cached values), a **tab picker** for multi-tab workbooks (`updatedImportFile` → sheet names → `Spreadsheet::select`), and a bespoke **`PayAndExpenditures`** profile that reads Rob's scenario tab — expenditure→essential, salary→gross, **State Pension→state pension, DLA→tax-free income, partner pension→annuity** — **verified against the real file** (£24,600/yr essential, £190.00/wk SP, etc.; income lands on Person 1 with no start age, flagged). **Still OPEN**: **calibrating the Nischa import** (deprioritised by Rob; layout captured — it's a 50/30/20 dashboard) and re-verifying the IWT CSP profile against the real 2023 export; the **line-item expense categories** data-model decision; a full per-field a11y sweep + axe/Pa11y CI; 2FA enrolment UI. **This session** added **scenario-draft auto-save**, **person names** (persisted), the **State Pension "full" shortcut**, and **`.xlsx`/Cancel import fixes** (all tested), then captured **sector research** ([docs/RESEARCH-cashflow-modelling.md](docs/RESEARCH-cashflow-modelling.md)) + a **research-backed build plan** (docs/PLAN.md "Sector-informed build plan"). **Next (as planned then — now superseded by the current build order in Status + What's next + the REBUILD callout):** that plan — edit → clone/compare, line-item 3-tier budgets, projection drill-down — plus Phase 2 step 5 (demo/perf/PDF).
_Last updated: 2026-06-28 (**Docs-only session — parked a post-v1 feature: statement-driven onboarding + document import**: upload bank/credit-card statements, payslips and benefit statements → extract + pre-fill the wizard → ask only the remainder → build the budget from actual spend, not "average user" figures. Design + sector evidence captured in **[docs/RESEARCH-document-import.md](docs/RESEARCH-document-import.md)**, a PARKED section in docs/PLAN.md, and a DECISIONS entry. Reframes a "could a local Ollama AI do the forecasting?" investigation — answer: **no model in the trusted numeric path** (it would break HMRC-to-the-penny + reproducibility + sourcing), but wrangling/explaining imported documents **locally** is a genuine fit. Load-bearing calls: **transfer-matching is deterministic-only** (the "£1,258 card payment ≠ £2,516 of spend" internal-transfer double-count is the data-integrity bug class, not an AI problem); **LLM categorisation is an optional, walled-off, local-only assist** for the long tail (rules cover 60–80%); actuals = input baseline, PLSA stays the benchmark. No code changed; suite unchanged at 359. Earlier same day: Phase D **Tier-2 — PDF export + a11y CI done, completing the Tier-2 build**: results download as a PDF via `ScenarioPdfController` + `pdf.results` (`barryvdh/laravel-dompdf`, pure-PHP, reusing the on-screen `ResultPresenter` so figures can't drift; owner-scoped, draft 404s; `ScenarioPdfTest` streams a real `%PDF` + renders the figures/disclaimer), and the a11y gate is automated with `Pa11y CI` (axe+HTMLCS, WCAG2AA) — `.pa11yci.json` + `npm run a11y` + `.github/workflows/a11y.yml` + `docs/A11Y.md` (axe-only); a local sweep then ran (2026-06-28) and fixed 3 WCAG AA contrast issues, with **in-browser axe DevTools the authoritative check** (this machine's `ignore-scripts=true` blocks headless browser binaries; Pa11y's axe-4.2 emits false positives). Suite 348→353. The remaining Tier-2 activity is a single real-browser verification pass (run the a11y sweep + eyeball charts under the CSP, the 2FA QR, the PDF layout). Earlier same day: Phase D **Tier-2 — 2FA enrolment UI done**: Fortify two-factor enrolment is now a real screen — `App\Livewire\AccountSecurity` at `/account/security` (behind `password.confirm`) drives Fortify's enable/confirm/regenerate/disable actions directly (single-page UX), the `User` model gains the `TwoFactorAuthenticatable` trait, and the login two-factor-challenge + password-confirmation views are wired; `TwoFactorAuthenticationTest` proves the whole flow headlessly incl. driving the login challenge to completion (test gotcha: Fortify rejects TOTP reuse within its window, so the enrol helper stamps `two_factor_confirmed_at` rather than burning a code); residual = a browser eyeball that the QR scans; suite 340→348. Earlier same day: Phase D **Tier-2 — security headers done**: a compatible-by-construction CSP + static hardening headers on the `web` group — `App\Http\Middleware\SecurityHeaders` reading `config/security.php` (env toggles `SECURITY_HEADERS_ENABLED` + `SECURITY_CSP_REPORT_ONLY`); enforcing but permissive exactly where the self-hosted Livewire/Alpine/ApexCharts stack needs it, with `object-src`/`base-uri`/`form-action`/`frame-ancestors` locked down; Filament `/admin` out of scope by design; `SecurityHeadersTest` covers it headlessly; suite 332→340. Residual: a real-browser eyeball to confirm charts render under the CSP, then tighten `script-src` to nonces. Earlier same day: Phase D **Tier-2 — 10k-path Monte Carlo perf done**: a lean integer `IncomeTaxCalculator::totalPence()` sharing one band core with `compute()` — the projector's hot tax calls route through it, byte-identical, pinned by a 1,120-cell equivalence test — cut a 10k run 13.9 s → 8.9 s interpreted, and the queue worker should run with OPcache JIT for → 4.75 s; suite 330 → 332. Earlier same day: Phase D **Tier-2 go-live polish STARTED — demo preset/seeder built**: `App\Demo\DemoScenario` is the one home for an obviously-fictional sample plan in the canonical `builder_state` shape (so it assembles + runs exactly like a user-built scenario), and `DemoScenarioSeeder` persists it — a base plan + one delta-child what-if (retire two years earlier, derived via `BuilderStateDelta::diff` so it stores only the override) to showcase Compare. Idempotent (matched by owner+name, drops stale runs); no-silent-failure + release-safe (provisions a fictional `demo@example.com`/`password` account outside production, refuses to mint default credentials in production unless `DEMO_USER_EMAIL` names an existing user). Opt-in seeder (`php artisan db:seed --class=Database\Seeders\DemoScenarioSeeder`), not wired into `DatabaseSeeder`. New tests: `DemoScenarioTest` (assembly + spend reconciliation + value-only delta + the lever lowers central wealth) and `DemoScenarioSeederTest` (runnable base+child, what-if changes the forecast, idempotent, explicit-user, production-refusal). Suite **320→330 green** (app 183→193; engine 137); pint clean. Earlier same day: Phase D Tier-1 data-integrity **COMPLETE** — user-facing import reconciliation panel: on import, each imported/aggregated total is shown beside the sheet's own independent figure and a mismatch is flagged loudly (`ReconciliationLine` + `ImportResult::reconciliation` + the three calibrated profiles + the Blade panel); a deliberately-inconsistent golden fixture + its Livewire twin prove a divergence is surfaced, not silent; one latent CSP parser fix fell out — skip the `NET WORTH`/`INCOME` sections so the line-item cross-check is faithful (no imported figure changed). Suite 320 green (engine 137 / app 183). Earlier same day: displayed-figure provenance (panel == CSV == interpretation, one shared `%` formatter); forecast-boundary reconciliation invariants (HousingProceeds single-source net-proceeds + total-wealth-from-parts, which caught + fixed a 1-pence PathProjector round-of-sum drift). Earlier: admin-panel lockdown — canAccessPanel() gated on a new is_admin flag, first admin via `php artisan user:make-admin`; suite 298 green. Earlier today: gov.uk figure-verification pass complete (every ⚠️ figure re-confirmed + stamped verified_on 2026-06-27, no value changed, pensions-in-IHT now enacted); A5 complete. See newest session-log entries). Earlier notes:_
_Two corrections were made in a prior refresh: (1) reconciled the stale 224/105 headline counts to the verified **235 tests / 929 assertions**, engine 113; (2) corrected the rebuild work's date labels from a mis-stamped **2026-06-26** to **2026-06-25** across all five docs (37 occurrences) — git commit dates and the session clock confirm every commit was 2026-06-25; a prior session had wrongly believed the clock rolled to the 26th. Build history: the rebuild (Phase A + C3) and the builder-UX/sector-research work were both this same day; the prototype's Phase 1 engine + Phase 2 steps 1–3 were 2026-06-24._

**▶ REBUILD IN PROGRESS (2026-06-25 build session).** Rob authorised a clean rebuild treating existing
code as a prototype, with **no need to preserve any user data / DB layout / data shape** — build storage to
the new world directly. Decisions locked (DECISIONS 2026-06-25 ×2): **keep the framework-free engine + sound
app code; rebuild the storage layer freely**; **ratify Livewire 4 + Filament 5 + SQLite**; **interleave trust
+ features**; the prototype is tagged **`prototype-v1`** (a8f1f68) for recovery (no remote). Build order is
**A (engine) → B (storage) → C (features) → D (trust + go-live)**.
**Done across the rebuild (suite 224→293 green, engine 105→129, app →164; through C4 + Phase D's A5):**
- **Phase A (engine, all golden-master/reconciliation tested):** account + DC **ongoing contributions**
  (funded from surplus); per-person **`LongevityAdjustment`** what-if; terminal **usable**-vs-total wealth;
  **`YearResult::incomeBySource`** (8 canonical sources). **A5 (GIA/cash income tax + CGT-on-disposal)
  DELIBERATELY DEFERRED to Phase D** — the projector grows GIA/cash at *total* return, so taxing a yield on
  top would double-count; it needs return decomposition, done with the figure verification.
- **Phase C3 (results page):** **usable-vs-total wealth** in the headline cards + the
  buy-vs-rent table (fixes the "wealth left" paradox end-to-end), and a deterministic **cashflow ladder**
  (income-by-source → tax → spend → usable/total wealth) as an accessible table + CSV, shown immediately.
- **Phase B (storage inversion — newest, this session):** **`scenarios.builder_state`** (encrypted) is now
  the **single source of truth**; the engine `Household` + `HousingAction` DTOs are **derived** from it
  (`Scenario::toHousehold()`/`toHousingAction()` via the `HouseholdAssembler` — no reverse-mapper). Clear
  columns (name/variant/tax-year/iht/assumption-set) are a **projection** refreshed on every save
  (`Scenario::fillFromBuilderState()`). **Edit a saved forecast** at `/scenarios/{scenario}/edit`
  (owner-scoped); `save()` is **update-or-create** and **invalidates stale runs/results** on edit (gotcha B).
  The `households` + `scenario_drafts` tables, the `Household`/`ScenarioDraft` models and the
  `HouseholdMapper`/`HousingActionMapper` are **dropped**; the draft folds into a **`draft`-status scenario**
  (one per user, promoted to `ready` on save). No data migration (rebuild authorised).
- **Phase C2 (delta-child what-ifs + Compare — newest, this session):** a child scenario references a base via
  **`parent_scenario_id`** and stores ONLY its **`overrides`** (encrypted) — a sparse delta of changed
  form-state leaves, never a full copy — so the base stays the single source of truth. Effective inputs =
  base ⊕ overrides via **one merge function** (`App\Forecast\BuilderStateDelta::merge`/`diff`/`orphans`/
  `structurallyDiffers`, round-trip tested); `Scenario::effectiveBuilderState()` resolves it and the engine
  DTOs derive from that. **List rows (pensions/accounts/income/one-offs/withdrawals) gained stable ids** so an
  override targets the right row across base reorders (gotcha N); people keep p1/p2. The builder runs a
  **child mode** (`/scenarios/{base}/child`, owner-scoped; full builder pre-filled from the base; save diffs to
  the delta) — a **structural add/remove is refused** (a delta can't fork the base) and a **base edit
  propagates** to children (refresh their projected columns + drop their stale runs); deleting a base
  **cascades** to its children. **Compare** (`/scenarios/{base}/compare`) lays the base beside its what-ifs
  using each one's **deterministic** central projection (shows immediately, never ranked) as an accessible
  table; orphaned overrides are surfaced, not dropped. Dashboard now nests children under their base with
  Create-what-if / Compare links.
- **Phase C1 core (3-tier line-item budget — newest, this session):** spending is now **line items**
  (`builder_state.expenseLines`: `{id, label, amount, category ∈ essential|discretionary|self_investment,
  savedAsAsset}`) — the **single source of truth**. The `HouseholdAssembler` **derives** the engine totals
  (essential = Σ essential lines; discretionary = Σ discretionary + *spent* self-investment), and a **saved
  self-investment** line becomes a balance-zero **contributing ISA** (`ongoingContributions`, funded from
  surplus — the engine already applies it), counted **once** (one home per pound, gotcha O). The flat
  `expense.essential/discretionary` are **dropped when lines exist** (no drifting total); a legacy/imported
  scenario **seeds lines from its flat totals** on load (gotcha G). The builder's Spending step is a 3-tier
  editable list with live subtotals; validation requires ≥1 line. **Reconciliation + completeness tested**
  (`ExpenseLineReconciliationTest`: totals == Σ lines; saving a line builds more wealth than spending it).
  The C2 override examples now target an expense line by id.
- **Phase C1 fast-follow (committed `c967426`):** (1) **results 3-tier display + income-floor
  readout** — the engine exposes `YearResult::essentialSpend` (real terms, the essential floor incl. rent/
  running costs, the one definition both the ladder and the readout read); `ResultPresenter::expenseBreakdown()`
  echoes the budget in 3 tiers (per-line + spent/saved split, **reconciling to the assembled spend**) and
  `incomeFloor()` shows essential spending vs **secure income** (DB + State Pension + annuity + **tax-free**,
  at the last all-alive year), both rendered on the results page before any run. (2) **importer
  line-population** — `ImportResult::expenseLines`; the three calibrated profiles emit line items
  (RetireForecast per-row, PayAndExpenditures per-outgoing, CSP per-bucket keeping the authoritative-TOTAL
  guard); the builder applies them as the source; the reconciliation guardrail (gotcha A) extended to assert
  the line sums reconcile to the sheet-verified totals. (3) **per-person longevity lever** — the assembler maps
  a `longevityMode`(`peer`/`fixed_age`/`offset_years`)+`longevityValue` person field to the engine
  `LongevityAdjustment`; the builder's people step has the control + validation; an end-to-end completeness
  test proves it reaches and shortens the forecast.
- **Phase C4 (PLSA Retirement Living Standards benchmark — newest, this session):** the results page now shows
  where the household's spending lands against the recognised **Minimum / Moderate / Comfortable** standards.
  Sourced figures live in the engine (`src/Benchmark/RetirementLivingStandards` + `…Result`, framework-free,
  golden-master tested) carrying `SOURCE`/`EDITION`/`VERIFIED_ON` (read 2026-06-26 from
  retirementlivingstandards.org.uk; **⚠️ re-verify in the go-live figure pass**). The comparison is put on the
  **PLSA basis** (excludes rent + mortgage — assumes outright ownership — but *includes* home running costs,
  gotcha J): comparable spend = the household's lifestyle spend (`ExpenseProfile::targetAnnualSpend()`, i.e.
  essential + discretionary, excluding *saved* self-investment) + owned-home running costs, rent excluded by
  construction. `ResultPresenter::plsaBenchmark()` reuses the *same* `ExpenseProfile` the forecast runs on, so
  the benchmark can't drift from the projection (reconciliation tested in `PlsaBenchmarkTest`); London uses the
  outside-London figures with a caveat. Wording is neutral (passes the `OutputPhrasing` lint). Also added a
  **`EngineIsolationTest`** (engine suite) that scans `src/` for forbidden `use App\…`/`use Illuminate\…`
  imports — added after Pint's `fully_qualified_strict_types` fixer silently turned a docblock cross-reference
  into a real `use App\…` import in the engine (caught + removed; now guarded).
- **Phase D — A5 (GIA/cash income tax + CGT-on-disposal, 2026-06-27):** the unwrapped-asset tax drag the
  forecast omitted is now modelled. A GIA's/cash's total return is split into **income** (cash interest taxed
  as savings, GIA dividends as dividend income — paid out to net cash, taxed yearly via the combined pass) and
  **capital growth** (the asset grows at capital only; income + capital == total, no double count; ISA stays
  tax-free, reinvesting). On a GIA **disposal** the pro-rata gain vs a tracked **cost basis** (from
  `Account::$unrealisedGain`, raised by GIA contributions) is realised and charged **CGT** (shared £3k AEA,
  18/24% by band, reusing `CgtParameters`). New sourced `AssumptionSet::$investmentIncomeYield` (2%, ⚠️). Tested:
  `InvestmentIncomeTaxTest` (conservation + the wrapper matters) + `GiaCapitalGainsTaxTest` (CGT incidence).
- **Phase D — figure-verification pass (2026-06-27):** the Tier-1 trust gate is **complete**. Every statutory
  figure carrying a ⚠️ marker (income tax/NI/dividends/savings, pensions incl. LSA/LSDBA/MPAA/tapered-AA + the
  NMPA-57 date, State Pension + SPA dates, CGT rates + £3k AEA + PRR/lettings, SDLT + surcharge, benefits capital
  tariff, IHT NRB/RNRB + £2m taper, care thresholds, PLSA RLS) was **re-confirmed against gov.uk** and its
  `verified_on`/`VERIFIED_ON` moved to 2026-06-27, with each docblock rewritten to record the source + confirmation.
  **No figure value changed — all were already correct.** Material finding: the **April-2027 unused-pensions-in-IHT
  change is now ENACTED** (Finance Act 2026, Royal Assent 18 Mar 2026), upgraded from "proposed". `investmentIncomeYield`
  (2%) was reclassified as a **modelling assumption, not a statutory figure** (reviewed + kept). Out of v1 scope and
  deliberately unverified: Scottish bands + LBTT/LTT (region resolver throws). 4 coupled date-assertions updated; suite
  stays **293 green**. See DECISIONS 2026-06-27 (figure-verification pass).
- **Phase D — admin-panel lockdown (2026-06-27):** `User::canAccessPanel()` is now gated on a new
  **`is_admin`** boolean (migration, default false; cast on the model) instead of returning `true` for every
  authenticated user — closing the privilege-escalation gap whereby any user could reach `/admin` and the
  advice-style `interpret` grant that lives behind it. The first admin is bootstrapped from the CLI
  (`php artisan user:make-admin {email}`, `--revoke` to undo — no-silent-failure: unknown email fails loudly);
  thereafter an admin can toggle others via a new **Admin access** `ToggleColumn` on the Users resource. Tests:
  a non-admin gets **403** from the panel, admins pass, the command grants/revokes/no-ops/fails-on-unknown.
- **Phase D — Tier-2 10k-path Monte Carlo perf (2026-06-28):** profiling put **93%** of per-path time in
  `PathProjector::project()`, dominated by the income-tax calculator, which the projector calls only for
  `->total->pence`. Added `IncomeTaxCalculator::totalPence()` — a lean integer total sharing the **same private
  band core** (`bandedTax()`) as `compute()`, skipping the `Money`/`lines` decoration the hot loop discards (and
  moved the allowance taper to an integer `grantedAllowancePence()` the Money `personalAllowance()` delegates to).
  The projector's main pass + `marginalTax()` route through it. **Byte-identical** (per-slice rounding mirrors
  `Money::applyRate`), pinned by `IncomeTaxTotalPenceTest` (1,120-cell grid: every band crossing × taper × PSA tier
  × dividend allowance × both tax years). 10k run: **13.9 s → 8.9 s** interpreted (1.57×); with worker **JIT → 4.75 s**
  (2.9×). See DECISIONS 2026-06-28 (10k-path perf) + How to pick up (JIT flags).
- **Phase D — Tier-2 security headers (2026-06-28):** `App\Http\Middleware\SecurityHeaders` (appended to the
  `web` group) sets a **CSP** + `X-Content-Type-Options`/`X-Frame-Options`/`Referrer-Policy`/`Permissions-Policy`
  on the public surface (landing, auth, the Livewire UI). Policy + toggles in `config/security.php` (one home; the
  test asserts against it). CSP is enforcing but **compatible-by-construction** — `default-src 'self'`, self-hosted
  Vite + Bunny fonts, `script-src`/`style-src` keep `'unsafe-inline'`/`'unsafe-eval'` (Livewire inline init, Alpine
  Function-constructor eval, ApexCharts inline styles), while `object-src 'none'` / `base-uri 'self'` /
  `form-action 'self'` / `frame-ancestors 'none'` are locked down. `SECURITY_CSP_REPORT_ONLY` stages a rollout;
  Filament `/admin` is out of scope (own stack, own font source). `SecurityHeadersTest` verifies it headlessly.
  **Residual:** a real-browser eyeball (charts/Livewire still render under the CSP), then tighten `script-src` to
  nonces via Alpine's CSP build.
- **Phase D — Tier-2 2FA enrolment UI (2026-06-28):** Fortify's two-factor feature (long enabled, columns long
  migrated) finally has screens. `App\Livewire\AccountSecurity` (`/account/security`, behind a `password.confirm`
  "sudo" gate, outside the disclaimer gate like the GDPR controls) drives Fortify's own actions directly — enable
  → show QR + setup key → confirm a code → reveal recovery codes; regenerate codes; turn off. The `User` model
  gains the `TwoFactorAuthenticatable` trait (secret/codes stay encrypted-and-read-raw, so **no** `encrypted`
  cast, and they are `$hidden`); `FortifyServiceProvider` wires the login **two-factor-challenge** + the
  **password-confirmation** views (both new Blade screens); a "Security" nav link is added. `TwoFactorAuthenticationTest`
  covers it headlessly (enable+confirm with a computed TOTP, wrong-code rejected, recovery-code regen, disable,
  the full login challenge completed, the password-confirm gate). **Residual:** a browser eyeball that the QR
  renders + an authenticator app round-trips.
- **Phase D — Tier-2 PDF results export (2026-06-28):** `App\Http\Controllers\ScenarioPdfController`
  (`GET /scenarios/{scenario}/results/pdf`, owner-scoped, in the disclaimer-acknowledged group; a draft 404s)
  renders `resources/views/pdf/results.blade.php` with **`barryvdh/laravel-dompdf`** (pure-PHP, no browser/binary,
  app-layer only). Built from the **same `ResultPresenter`** the screen uses (tax shock, income floor, 3-tier
  budget, PLSA, the cashflow ladder, + the Monte Carlo summary if a run exists), so the print can't drift; carries
  the guidance-only disclaimer (passes the lint). A "Download PDF summary" link sits on the results page.
  `ScenarioPdfTest` (5) streams a real `%PDF`, owner-scopes, 404s a draft, and renders the figures/disclaimer.
  **Residual:** a PDF-viewer eyeball of layout fidelity.
- **Phase D — Tier-2 accessibility CI (2026-06-28, scaffolded):** **Pa11y CI** (axe-core + HTML CodeSniffer,
  WCAG2AA) over the rendered pages — `.pa11yci.json` (public pages + authed shell pages via a scripted demo login),
  `npm run a11y`, `.github/workflows/a11y.yml` (dormant until a remote exists), `docs/A11Y.md`. **Not yet run
  green** here — it needs a headless Chrome + the served app, i.e. the real-browser verification pass this always
  required. Rendered ApexCharts canvases stay a manual check.
**Still to build:** **Tier-2 build is COMPLETE.** The one remaining go-live activity is a **real-browser
verification pass** (run `npm run a11y`; eyeball charts under the CSP, the 2FA QR, the PDF layout), then optionally
tighten the CSP `script-src` to nonces. **One small UX item is now on the backlog** (docs/PLAN.md "Go-live UX
backlog 2026-06-28"): a **"waiting for a worker" hint** when a full run sits `queued` with no progress (the full
10k run needs `php artisan queue:work`; with none running it shows "Queued — 0%" forever — a silent-failure gap hit
during the verification pass). Full plan: docs/PLAN.md "Sector-informed build plan".

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
- **App ↔ engine boundary (Phase B):** a scenario stores the raw builder **form-state** (`builder_state`, one `encrypted:array` per row) as the single source of truth and **derives** the engine `Household` + `HousingAction` DTOs from it via the `HouseholdAssembler` (`Scenario::toHousehold()`/`toHousingAction()`); there is no reverse-mapper. AssumptionSet / SimulationResult still serialise via `app/Finance/Mapping/` mappers. Structural columns stay clear for listing and are a projection of the form-state.
- **Delta-child boundary (Phase C2):** a what-if **child** holds no `builder_state` — only its `parent_scenario_id` + a sparse encrypted `overrides` delta. `Scenario::effectiveBuilderState()` resolves base ⊕ overrides via `App\Forecast\BuilderStateDelta` (the single id-aware merge fn), and the same `toHousehold()`/`toHousingAction()` + clear-column projection then run off that. So the base is the one source; children track it (a base edit refreshes them and drops their stale runs). List rows carry stable ids so an override targets the right row, not an array index.
- Money handling is **hand-rolled integer pence** (see Decisions: brick/money was dropped over a dependency clash). PHPUnit 12 for tests. **`phpoffice/phpspreadsheet`** is an **app-layer** dependency (for `.xlsx` import only); the engine stays dependency-free.
- **Spreadsheet import (`app/Import/`):** profiles read a sheet-aware `Spreadsheet` (CSV or `.xlsx`); the Livewire wizard offers a tab picker + applies the parsed partial form-state. Real sample `.xlsx` are gitignored under `docs/*.xlsx`.
- Still to come (see docs/PLAN.md): **Phase 2 step 5 / Tier-2 go-live** — a11y CI (axe/Pa11y), PDF export, 2FA enrolment UI (✅ demo preset, ✅ 10k-path perf, ✅ CSP/security-headers all DONE 2026-06-28); a full per-field a11y sweep + real-browser chart verification.

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
      ├─ Benchmark/{RetirementLivingStandards,RetirementLivingStandardsResult}.php  # PLSA RLS (sourced, C4)
      ├─ Mortality/{OnsPeriodMortalityData(generated),CohortLifeTable,JointLifeSampler}.php
      ├─ Forecast/{PathProjector,DeterministicForecaster,DeterministicPathDraws,PathDraws,
      │            DrawdownStrategy,PortfolioAllocation,ForecastSettings,YearResult,ForecastResult}.php
      ├─ MonteCarlo/{Cholesky,ReturnModel,SampledPathDraws,Simulator,SimulationResult}.php
      └─ Housing/HousingComparison.php
   ├─ resources/mortality/ons-2024-period-qx.json   # sourced ONS data (engine class generated from it)
   └─ tests/{Money,Tax,Pension,StatePension,Property,Benefits,Iht,Care,Dto,
             Assumptions,Benchmark,Mortality,Housing,MonteCarlo,
             Forecast/{PathProjectorTest, InvestmentIncomeTaxTest, GiaCapitalGainsTaxTest(A5)},
             Architecture(EngineIsolationTest — no use App\/Illuminate\ in src/)}/*Test.php
```
Engine namespace: `RetireForecast\FinanceEngine\...`. Tests namespace: `RetireForecast\FinanceEngine\Tests\...` (registered in the root app's `autoload-dev`). Pint enforces house style (snake_case test methods, no-space concatenation); run `vendor/bin/pint packages/finance-engine` after adding files, or `vendor/bin/pint --dirty` for changed app files.

App layer added this session (`App\...`):
```
app/
├─ Compliance/{OutputPhrasing, Interpretation}.php     # step 4: banned-phrase lint (directive-only
│                                                      #  patterns) + the WALLED-OFF advice-style narrator
├─ Demo/DemoScenario.php                              # the obviously-fictional sample plan (one canonical
│                                                      #  builder_state); seeded by DemoScenarioSeeder
├─ Enums/{ScenarioVariant, ScenarioStatus, SimulationMode, SimulationStatus}.php
├─ Finance/Mapping/{Codec, AssumptionSetMapper, SimulationResultMapper}.php  # DTO <-> storage-array
│                                                      #  (Household/HousingAction mappers dropped in Phase B)
├─ Models/{Scenario, AssumptionSet, SimulationRun, Result, User}.php  # Scenario holds builder_state +
│                                                      #  derives the engine DTOs (toHousehold/toHousingAction)
├─ Forecast/{ScenarioForecaster, SimulationRunner, RunCancelled,    # assemble inputs, run, persist
│            HouseholdAssembler,                       #  form-state -> engine DTOs (SOLE source); C1: derives spend
│                                                      #   totals from expenseLines, saved self-investment -> contributing ISA
│            BuilderStateDelta,                        #  C2: the one merge/diff fn (base ⊕ overrides, id-aware)
│            ResultPresenter,                          #  SimulationResults -> headline text + chart + table
│            LumpSumTaxShock,                          #  headline #1: deterministic pension tax shock
│            AssumptionComparison}.php                 #  compare-assumptions sensitivity table
├─ Import/{ImportProfile, ImportRegistry, ImportResult, ImportException,  # spreadsheet import:
│          Spreadsheet, SpreadsheetReader (csv + xlsx), MoneyText,        #  sheet-aware, exact pence
│          Profiles/{RetireForecastTemplate, ConsciousSpendingPlan,      #  CSV + IWT CSP (calibrated),
│                    PayAndExpenditures, IntentionalSpendingTracker,     #  bespoke personal workbook,
│                    UncalibratedProfile}}.php                            #  Nischa stub (deprioritised)
├─ Livewire/{Dashboard (roots + nested what-ifs), ScenarioBuilder (wizard + import + EDIT + draft + CHILD),
│            ScenarioResults, ScenarioCompare,         # full-page UI (Compare = C2 base-vs-children, deterministic)
│            AccountSecurity}.php                       # 2FA enrolment: drives Fortify actions (enable/confirm/regen/disable)
├─ Jobs/RunScenarioSimulation.php                      # queued full run (holds run id only) + failed()
├─ Gdpr/GdprService.php                                # export() (incl. runs+results) + erase()
├─ Http/Controllers/{AccountController, DisclaimerController,        # GDPR routes; first-run ack screen
│                    ScenarioPdfController}.php                       # PDF results export (dompdf; reuses ResultPresenter)
├─ Http/Middleware/{EnsureDisclaimerAcknowledged,     # gates forecast pages on acceptance
│                   SecurityHeaders}.php                # CSP + hardening headers on the web group (config/security.php)
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
          auth/{login,register,forgot-password,reset-password,two-factor-challenge,confirm-password}.blade.php,
          livewire/{dashboard,scenario-builder,scenario-results,account-security}.blade.php,
          pdf/results.blade.php,                       # dompdf-rendered results summary (inline CSS; not browser-served)
          livewire/partials/interpretation.blade.php}  # WALLED-OFF directive partial (lint-exempt by name)
config/security.php                                    # CSP directives + hardening headers + toggles (one home)
.pa11yci.json / .github/workflows/a11y.yml / docs/A11Y.md  # Pa11y CI a11y scaffold (axe-only); authoritative check = in-browser axe DevTools (see docs/A11Y.md)
routes/web.php                                         # /, /welcome (ack), /dashboard, /scenarios/{create,edit,child,compare,results,results/pdf}, /account/{export,security,destroy}
database/migrations/{2026_06_24_*_create_{assumption_sets,scenarios,simulation_runs,results}_table,  # scenarios holds builder_state
                    2026_06_25_*_add_compliance_columns_to_users_table,    # (households + scenario_drafts dropped in Phase B)
                    2026_06_26_*_add_parent_and_overrides_to_scenarios_table}.php  # C2: parent_scenario_id + encrypted overrides (child delta)
database/seeders/{AssumptionSetSeeder.php,             # mirrors the engine library
                  DemoScenarioSeeder.php}              # opt-in: seeds the fictional demo plan (base + what-if child)
tests/{Unit/{Demo/DemoScenarioTest,               # demo preset assembles + reconciles + value-only delta + lever reaches forecast
             Finance/{MappingRoundTripTest, SimulationResultMappingTest},
             Forecast/{HouseholdAssemblerTest,         # form-state -> DTO is lossless; C1: line-item spend derivation
                       BuilderStateDeltaTest,           # C2: merge/diff round-trip + id-stability + orphan + structural guard
                       ExpenseLineReconciliationTest,   # C1: totals == Σ lines + saved-builds-wealth completeness
                       IncomeFloorTest, PlsaBenchmarkTest},  # C1 income-floor; C4 PLSA benchmark reconciles to ExpenseProfile
             Import/{RetireForecastTemplateTest, ConsciousSpendingPlanTest, PayAndExpendituresTest}},
       Feature/{Demo/DemoScenarioSeederTest,      # seeds a runnable base+child, idempotent, production-safe
                Persistence/*, Gdpr/GdprTest, Admin/FilamentAdminTest,
                Scenario/ScenarioDeltaTest,            # C2: child effective state + cascade + orphan (model level)
                Auth/{AuthScreensTest,                 # login/register/reset render + flow
                      TwoFactorAuthenticationTest},    # Tier-2: 2FA enable/confirm/regen/disable + login challenge + sudo gate
                Security/SecurityHeadersTest,          # Tier-2: CSP + hardening headers on the web group
                Compliance/{BannedPhrasingTest,        # the partition build test (+ non-vacuity guard)
                            DisclaimerAcknowledgementTest, InterpretationTest},
                Import/{ImportRegistryTest, SpreadsheetReaderTest},   # registry + csv/xlsx reader
                Forecast/{ScenarioForecasterTest, SimulationRunnerTest, RunScenarioSimulationFailureTest,
                          LumpSumTaxShockTest, AssumptionComparisonTest, ScenarioPdfTest},  # PDF export: real %PDF + figures/disclaimer
                Livewire/{ScenarioBuilderTest, ScenarioBuilderImportTest, ScenarioResultsTest,
                          ScenarioEditTest, ScenarioDraftTest,         # edit-in-place + draft (Phase B)
                          ScenarioChildTest, ScenarioCompareTest}}}.php  # C2: create-child delta + structural guard; Compare page
tests/Support/{HouseholdFixture, BuilderStateFixture (rows carry stable ids), ScenarioFixture}.php   # expected DTO + form-state + persisted scenario
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
- **In progress:** nothing mid-edit; tree clean (all committed). The rebuild's **Phase A (engine) + C3 (results drill-down) + Phase B (storage inversion) + Phase C2 (delta-child what-ifs + Compare) + Phase C1 (3-tier line items, core + fast-follow) + Phase C4 (PLSA benchmark)** are all done, and **Phase D Tier-1 (trust) is now COMPLETE** — A5 (GIA/cash income tax + CGT-on-disposal), the gov.uk ⚠️ figure-verification pass, the admin-panel lockdown, the forecast-boundary reconciliation invariants, the displayed-figure provenance test, **and the user-facing import reconciliation panel** (suite **320**). **Phase D Tier-2 (go-live polish) BUILD is complete: demo preset/seeder, 10k-path Monte Carlo perf, CSP/security headers, 2FA enrolment UI, PDF export and the a11y CI scaffold are all DONE** (`App\Demo\DemoScenario` + `DemoScenarioSeeder`; `IncomeTaxCalculator::totalPence()`; `App\Http\Middleware\SecurityHeaders` + `config/security.php`; `App\Livewire\AccountSecurity` + the `TwoFactorAuthenticatable` trait + challenge/confirm-password screens; `App\Http\Controllers\ScenarioPdfController` + `pdf.results` via dompdf; `Pa11y CI` scaffold; engine 139 / app 214 / suite **353**). **Next: a single real-browser verification pass** (run `npm run a11y`; eyeball charts under the CSP, the 2FA QR, the PDF layout), then optionally tighten the CSP to nonces; see the **▶ REBUILD** callout + What's next.
- **Known bugs / broken:** **none open — the five 2026-06-28 re-review findings are all RESOLVED** (commits `fff3f07` + `86d5d82`; detail in docs/PLAN.md "Review findings"): the PDF/screen Monte-Carlo run divergence + provenance (both now read `Scenario::latestCompletedRun()`; the PDF stamps mode/paths/seed/date; the provenance test now covers the PDF); `freezeEndYear` is now implemented (thresholds index with inflation after the freeze); the GIA basis split now derives one part from the other (no drift); `medianDepletionYear` now reaches the comparison table + PDF; and a cash-interest conservation test was added. Documented v1 scope limits (all flagged in code): income tax is England/Wales/NI only (Scotland throws); emergency tax models the over-deduction magnitude, not PAYE-table pennies; mortality grid ages 50–100 / years 2025–2074 with clamping + a non-ONS tail above 100 (cap 110); forecast taxes GIA dividends + cash interest annually AND realises CGT on GIA disposal (A5 complete; ISA tax-free; GIA/cash grow at capital only; v1 omits capital-loss relief + judges the CGT band on non-savings income); **income-tax thresholds frozen until `freezeEndYear` (2031), then indexed with inflation**; DB escalation + triple lock as smooth growth factors; buy-vs-rent takes main-home CGT as £0 (PRR) and no SDLT surcharge; house/salary growth deterministic inside the Monte Carlo.

## What's next (in order) — the research-backed rebuild
The engine + app are built and green (287 tests). The rebuild is nearly complete — **Phase A
(engine enrichments: ongoing contributions, longevity, usable-vs-total wealth, income-by-source), Phase
C3 (results: usable-vs-total + the deterministic cashflow ladder), Phase B (storage inversion:
`builder_state` source of truth + edit-in-place + stale-run invalidation), Phase C2 (delta-child what-ifs
+ Compare), Phase C1 (3-tier line items, core + fast-follow), and **Phase C4 (PLSA Retirement Living Standards
benchmark)** are DONE**, and **Phase D has started: A5 (GIA/cash income tax + CGT-on-disposal) is complete**
(green). **Phase D Tier-1 (trust) is now COMPLETE** (gov.uk ⚠️ figure pass, admin-panel lockdown, forecast-boundary
reconciliation invariants, displayed-figure provenance, and the user-facing import reconciliation panel — suite
320 green). **Phase D Tier-2 (go-live polish) has started: the demo preset/seeder is DONE, and the 10k-path Monte
Carlo perf, the CSP/security-headers item, the 2FA enrolment UI, PDF export and the a11y CI scaffold are ALL DONE (suite 359 green; the 2026-06-28 re-review's 5 data-presentation findings are all resolved).
The Tier-2 BUILD is complete; the only remaining go-live activity is a single real-browser verification pass.** Items 1–6 below are kept only to record that they
are now done. **Read first:
docs/PLAN.md "Sector-informed build plan (2026-06-25)" (full steps + the gotchas table A–P) · DATA-MODEL.md
"Planned shape changes (2026-06-25)" · DECISIONS.md 2026-06-25 (×3) · docs/RESEARCH-cashflow-modelling.md.**
The rebuild is **authorised** even though it reworks the prototype builder (the UI wins — person names, the
State Pension shortcut — carry over; the per-user draft mechanism folds into `builder_state`). Build order:

1. ✅ **DONE (rebuild Phase B, 2026-06-25).** Scenario data-shape inversion + Edit a saved forecast.
   `scenarios.builder_state` (encrypted) is the **single source of truth**; the engine `Household` +
   `HousingAction` DTOs are **derived** from it (`Scenario::toHousehold()`/`toHousingAction()` via the
   `HouseholdAssembler` — no reverse-mapper). Clear columns are a projection (`fillFromBuilderState()`).
   **Edit** at `/scenarios/{scenario}/edit` (owner-scoped); `save()` is update-or-create and **invalidates
   stale runs/results** (gotcha B). The `households` + `scenario_drafts` tables, the `Household`/`ScenarioDraft`
   models and the `HouseholdMapper`/`HousingActionMapper` are **dropped**; the draft is a `draft`-status
   scenario. New tests: `ScenarioEditTest` (prefill / in-place update / run-invalidation / owner-403 /
   draft→edit redirect), rewritten persistence/draft/GDPR tests, shared `Tests\Support\ScenarioFixture`.
2. ✅ **DONE (rebuild Phase C2, 2026-06-26).** "Create what-if" delta children + Compare. A child = a
   **delta** of overridden form-state leaves on a base (`parent_scenario_id` + encrypted `overrides`); effective =
   base ⊕ overrides via **one merge function** (`App\Forecast\BuilderStateDelta`, round-trip + id-stability +
   orphan + structural-guard tested). **List rows gained stable ids** (gotcha N). Builder child mode pre-fills
   from the base and stores only the delta; **structural add/remove refused**; **base edits propagate** to
   children (refresh + invalidate runs); base delete **cascades**. **Compare** (`/scenarios/{base}/compare`)
   shows base + children on their deterministic projection, neutral, immediate. **v1 boundary:** a child
   overrides *values* only (the canonical levers — rent, a salary, variant, lifespan-once-wired); add/remove a
   person/pension/account belongs in the base or a new forecast. **Residual:** wire the per-person longevity
   lever into the builder as a what-if field (the merge already handles it).
3. ✅ **DONE (Phase C1 — 3-tier line-item budget: core + fast-follow, 2026-06-26).** Line items
   `{id,label,amount(annual),category,savedAsAsset}` are the **source** (`builder_state.expenseLines`);
   category ∈ essential/discretionary/self_investment; totals = `sum(lines)`, derived in the
   `HouseholdAssembler` (reconciliation invariant tested). `savedAsAsset`: *spent*→discretionary,
   *saved*→a balance-zero contributing ISA (`ongoingContributions`, applied by the engine from surplus —
   gotcha O, one home per pound). Flat totals dropped when lines exist; legacy/imported scenarios seed lines
   from their flat totals (gotcha G). Builder Spending step is a 3-tier editable list with live subtotals.
   **Fast-follow DONE:** results **3-tier breakdown display** (`ResultPresenter::expenseBreakdown`, reconciles
   to the assembled spend) + the **income-floor readout** (`incomeFloor()` — essential spending vs secure
   income = State Pension + DB + annuity + **tax-free**, off the new `YearResult::essentialSpend`);
   **importer line-population** (`ImportResult::expenseLines`; the three calibrated profiles emit lines; gotcha-A
   reconciliation guard extended to the line sums); and the **per-person longevity** lever wired as a builder
   field (assembler → `LongevityAdjustment`, completeness-tested end to end).
6. ✅ **DONE (Phase C4 — PLSA Retirement Living Standards benchmark, 2026-06-26).** The results page shows
   where the household's spending lands against the **Minimum / Moderate / Comfortable** standards. Sourced
   engine reference data (`src/Benchmark/RetirementLivingStandards` + `…Result`, framework-free, golden-master
   tested) with `SOURCE`/`EDITION`/`VERIFIED_ON` (read 2026-06-26; **⚠️ re-verify in the Phase D figure pass**).
   `ResultPresenter::plsaBenchmark()` compares on the **PLSA basis** (excludes rent/mortgage, includes home
   running costs — gotcha J), reusing the same `ExpenseProfile` the forecast runs on so it can't drift
   (reconciliation tested). Outside-London figures with a London caveat; neutral wording (passes the lint).
   Also added **`EngineIsolationTest`** guarding `src/` against `use App\…`/`Illuminate\…` (after Pint nearly
   introduced one). **Remaining:** Phase D (trust + go-live).
4. ✅ **DONE (rebuild Phase C3 + A3).** Drill-down + usable-vs-total wealth — the results page now shows
   **usable (liquid, excl. home) vs total wealth** plus a deterministic **cashflow ladder** from `YearResult[]`
   (income-by-source → tax → spend → wealth), fixing the cards paradox (gotcha P) end-to-end. **Residual for
   Phase D:** the per-pension current→projected→income drill-down.
5. ✅ **DONE (rebuild Phase A2).** Per-person longevity adjustment — `LongevityAdjustment` (peer / fixed age /
   ±years / mortality multiplier) feeds the deterministic death age and the Monte-Carlo `JointLifeSampler`
   (cohort-table q(x) multiplier), golden-master tested. The lifespan what-if gets wired into the builder in
   Phase B/C2.

**Independent of the rebuild (Phase 2 step 5 + go-live):** all built; the one remaining activity is a real-browser
verification pass (✅ demo preset/seeder, ✅ 10k perf, ✅ CSP header, ✅ 2FA enrolment UI, ✅ PDF export,
✅ a11y CI scaffold all DONE 2026-06-28; ✅ `User::canAccessPanel()` lockdown DONE 2026-06-27;
✅ gov.uk verification pass DONE 2026-06-27). See "Readiness gaps" + "Blockers".

## Readiness gaps (2026-06-25 doc/code review — suite verified green at 212/857)
A review against the docs confirmed **no drift** (the 212/857 suite, the file structure and the stack
all match the narrative; only the stale "step 4 not committed" branch line, now fixed). The genuine
gaps, tiered by severity:
- **Tier 1 — trust (blocks "shown as real") — ✅ COMPLETE (2026-06-28):** ✅ **the gov.uk verification pass is DONE (2026-06-27).** Every
  figure formerly flagged ⚠️ in the `TaxYear/` docblocks (tapered-AA + LSA/LSDBA, CGT residential rates + £3k AEA,
  IHT NRB/RNRB + the Apr-2027 pensions-in-estate change, care thresholds, SPA boundary dates, benefits £16k
  boundary, lettings relief) **plus the PLSA Retirement Living Standards figures** (now eyeballed against the
  published 2026 table — all 12 match exactly) was re-confirmed against gov.uk and stamped `verified_on: 2026-06-27`;
  no value changed; pensions-in-IHT is now enacted (Finance Act 2026). The A5 `investmentIncomeYield` (2%) was
  reviewed and reclassified as a modelling assumption (not a statutory figure). **Still deliberately unverified
  (out of v1 scope, region resolver throws):** SDLT Wales/Scotland (LBTT/LTT) + Scottish income-tax bands. The
  remaining Tier-1 item is the **data-layer integrity guardrails** (below) — the **forecast-boundary reconciliation
  invariants are now built** (net sale proceeds + total/usable wealth derived from parts; a 1-pence drift caught +
  fixed), the **displayed-figure provenance test is built** (panel == CSV == interpretation, one shared %
  formatter), **and the user-facing import reconciliation panel is now built** (each imported/aggregated total
  shown beside the sheet's own independent figure, a mismatch flagged loudly) — so **Tier-1 is COMPLETE**. See
  DECISIONS 2026-06-27 + 2026-06-28.
- **Tier 2 — Phase 5–6:** ✅ **demo preset/seeder DONE (2026-06-28)** (`App\Demo\DemoScenario` +
  `DemoScenarioSeeder`); ✅ **10k-path perf DONE (2026-06-28)** (lean `totalPence()` integer tax twin + worker JIT;
  13.9 s → 8.9 s → 4.75 s, byte-identical); ✅ **CSP header DONE (2026-06-28)** (`SecurityHeaders` middleware +
  `config/security.php` on the `web` group; compatible-by-construction, residual = browser eyeball + nonce
  tightening); ✅ **2FA enrolment UI DONE (2026-06-28)** (`App\Livewire\AccountSecurity` + the `TwoFactorAuthenticatable`
  trait + challenge/confirm-password screens; residual = browser eyeball that the QR scans); ✅ **PDF export DONE
  (2026-06-28)** (`ScenarioPdfController` + dompdf, reuses the presenter; residual = layout eyeball); ✅ **a11y
  scaffolded + first sweep (2026-06-28)** (`Pa11y CI` axe-only; **3 real contrast fixes** made; authoritative check
  is **in-browser axe DevTools / Lighthouse** per docs/A11Y.md, since this machine's `ignore-scripts=true` blocks
  headless browser binaries and Pa11y's axe-4.2 emits false positives). Tier-2 build complete. Scotland config pack
  is deliberately out of v1.
- **Tier 3 — shipped-surface gaps:** ✅ **2FA enrolment UI DONE (2026-06-28)** (was: Fortify 2FA on, no screens),
  ✅ **PDF export DONE (2026-06-28)**, real-browser ApexCharts verification (only the tables/text are tested) +
  running the a11y sweep green (both part of the one remaining verification pass), assumption-set figures not
  numerically editable in Filament. ✅ **`User::canAccessPanel()` lockdown DONE 2026-06-27** — gated on `is_admin` (the `interpret`
  grant now sits behind admin; first admin via `php artisan user:make-admin {email}`).
- **Tier 4 — open data-model/import decisions:** line-item expense categories; re-verify IWT CSP vs the
  real 2023 export; Nischa stub; imported income lands on Person 1 with no start age (flagged).

## Blockers / open questions
- [x] **Re-review findings (2026-06-28) — ALL RESOLVED** (commits `fff3f07` + `86d5d82`; detail in docs/PLAN.md
  "Review findings"). Both screen + PDF fall back to the last successful run via `Scenario::latestCompletedRun()`
  and the PDF stamps run provenance; `freezeEndYear` threshold un-freezing is implemented (homogeneity, no hot-loop
  config rebuild); the GIA basis split derives one part from the other (no drift); `medianDepletionYear` reaches the
  comparison table + PDF; cash-interest conservation test added; and `DisplayedFigureProvenanceTest` now covers the
  PDF (the gap that let the divergence slip through). Suite 353 → 359 green.
- [x] **Data-layer integrity guardrails — COMPLETE: importer reconciliation tests + forecast-boundary invariants + displayed-figure provenance + the user-facing import reconciliation panel all BUILT.**
  Rob's hard requirement (a past project was burned not by hallucinated numbers but by the data layer
  *inconsistently aggregating the same information*). **Built:** `tests/Fixtures/Import/GoldenWorkbooks.php`
  (sanitised real-file fixtures — layout-faithful, fake figures) + `tests/Unit/Import/ImportReconciliationTest.php`,
  reconciling each importer's output to the sheet's own stated totals. On its first run the guardrail
  **caught and we fixed two live wrong-aggregation bugs** in the IWT `ConsciousSpendingPlan` importer
  (a per-bucket "… TOTAL" double-counted on top of its line items → essential ~2×; the `NET WORTH`
  Investments/Savings rows miscounted as monthly contributions) — exactly the class of bug a synthetic
  happy-path test misses. The fix makes a bucket's own TOTAL authoritative; `PayAndExpenditures` +
  `RetireForecastTemplate` reconcile cleanly, locking in their real shapes. **Forecast-boundary invariants now
  BUILT (2026-06-28):** `HousingProceedsReconciliationTest` (sale price == net proceeds + mortgage + selling costs
  + CGT; CGT £0 under PRR; negative-equity floor) — backed by a new single-source `HousingProceeds` value object +
  public `HousingComparison::saleProceeds()` — and `WealthReconciliationTest` (every year + terminal: total wealth
  == liquid + pension + property). The wealth invariant **caught a real 1-pence drift** (`PathProjector` rounded the
  raw sum independently of its rounded parts); fixed by deriving `totalWealth` from the rounded legs, which also makes
  the pre-existing `terminalTotal − terminalUsable == property` test hold by construction rather than by luck.
  **Displayed-figure provenance now BUILT (2026-06-28):** `DisplayedFigureProvenanceTest` proves the fan CSV and the
  ladder CSV reproduce the panel's own tables figure-for-figure (parsed back + compared), and that the walled-off
  advice-style interpretation quotes only percentages the neutral panel shows. To make that hold by construction the
  duplicated percentage formatter was consolidated into one `ResultPresenter::formatPercent()` that the panel,
  comparison table, CSV exports and `Interpretation` all share (one figure, one home). **User-facing import
  reconciliation panel now BUILT (2026-06-28):** `ReconciliationLine` (pairs the figure that went into the form
  with the sheet's own independent figure for the same quantity, compared in exact pence so formatting can't mask
  or invent a mismatch) + `ImportResult::reconciliation`; the three calibrated profiles emit lines —
  `PayAndExpenditures` reconciles its summed line items against the sheet's own Total row (previously discarded);
  `ConsciousSpendingPlan` reconciles each bucket's stated `… TOTAL` against the sum of its line items;
  `RetireForecastTemplate` surfaces each category for eyeball review (`stated = null`, no false mismatch). A Blade
  panel turns red + switches to `role=alert` on any divergence (banned-phrasing partition lint still passes). A
  deliberately-inconsistent golden fixture (`csp-inconsistent-bucket-total`: a £9,999/mo TOTAL vs £3,000/mo of
  line items) + its Livewire twin prove the divergence is surfaced, not silent. **One latent correctness fix
  fell out:** the CSP parser now skips the `NET WORTH`/`INCOME` sections so the line-item sum is a faithful
  cross-check (the asset rows shared the bucket keywords); no imported figure changed because the stated TOTAL is
  still authoritative, but a CSP file lacking bucket TOTAL rows would no longer import assets as contributions.
  With this, **all four data-integrity layers are built and Tier-1 is complete.** **Caveat:** the IWT fixture was built from a *masked* dump of the real
  export, so it still warrants Rob's own eyeball against the real 2023 file. See DECISIONS 2026-06-25 "Data-layer integrity" + CLAUDE.md.
- [ ] **Spreadsheet import — Nischa deprioritised; line-item expenses still a decision (2026-06-25).** Built + tested + (where noted) **verified against Rob's real files**: the import infrastructure, **`.xlsx` reading** (`phpoffice/phpspreadsheet`), the **tab picker**, the **RetireForecast CSV** profile, the **IWT CSP** profile (calibrated from the published structure), and the bespoke **`PayAndExpenditures`** profile (expenditure + salary + State Pension/DLA/partner-pension income; verified on the real workbook). **Nischa** is deprioritised by Rob (layout captured: a 50/30/20 dashboard) — still `isAvailable()=false`. **Open:** re-verify IWT CSP against the real 2023 export; the **line-item expense categories** data-model decision (imports/wizard still roll up to essential/discretionary totals); imported income lands on **Person 1 with no start age** (by design — the sheet has neither ages nor a person split — flagged for the user). Real sample `.xlsx` live in gitignored `docs/*.xlsx` (never commit).
- [x] **Code-review refinements (2026-06-25) — all five DONE.** Logged in **docs/PLAN.md → "Refinements found in code review (2026-06-25)"**. (1) ✅ **compliance** — banned-phrasing partition test + disclaimer layer + acknowledgement gate + interpretation toggle; (2) ✅ the **lump-sum tax-shock panel** (headline output #1, now rendered); (3) ✅ **"no silent failure" hardening** — `GdprService::export()` includes runs+results, `RunScenarioSimulation::failed()` lands a dead worker's run in Failed, `ScenarioResults::currentRun()` is owner-scoped; (4) ✅ **a11y/form UX** — the wizard added `aria-describedby`/`aria-invalid` on validated fields, focus-to-first-error/step, a Save double-submit guard and the `endAge ≥ startAge` check; (5) ✅ the **compare-assumptions overlay** — `App\Forecast\AssumptionComparison` renders an accessible sensitivity table per shipped set. The remaining a11y work is only a *full* per-field sweep + axe/Pa11y CI (step 5). None break the green suite.
- [ ] **External-review enhancement backlog (2026-06-25) — post-v1, not blocking.** A second-opinion review was triaged into **docs/PLAN.md → "External review triage"**: adopt (when their phase arrives) a cashflow timeline table, longevity distribution visual, stress-test panel + what-if sliders (all reuse engine outputs we already compute), plus v2 annuitisation + care-cost stochasticity, CSP + tamper-evident run hash, and a source-freshness CI check. Adviser-style metrics (withdrawal rate, critical yield, replacement rate, narrative) only behind the `OutputPhrasing` lint. **Declined** (DECISIONS 2026-06-25): per-row/envelope encryption, a native MC accelerator, gov.uk scraping. Note: login rate-limiting the review flagged is already implemented.
- [x] **DECISIONS — mortality, assumptions, forecast mechanics:** all made. ONS cohort tables; FCA+DMS assumptions (signed off); dual drawdown strategy + cautious-40/60 default. See DECISIONS.md.
- [x] **Build-time gov.uk verification pass — DONE 2026-06-27** for every figure marked with a warning sign in the `TaxYearRegistry` helpers and parameter docblocks (income/NI bands, pension allowances, CGT/SDLT, PC/HB rates, IHT bands + April-2027 pensions-in-estate, care thresholds, SPA boundary dates) **plus the PLSA RLS figures**. All re-confirmed against gov.uk, stamped `verified_on: 2026-06-27`, no value changed; pensions-in-IHT now enacted (Finance Act 2026). See DECISIONS 2026-06-27. **Still owed (separate, not gov.uk-statutory):** re-confirm the **ONS mortality and FCA/DMS assumption sources** (docs/ASSUMPTIONS.md, docs/MORTALITY.md) — these sit at their 2026-06-24 sign-off; and the `investmentIncomeYield` 2% is a modelling assumption (reviewed, kept).
- [ ] **v1 modelling refinements** (deferred, listed under Current state → Known bugs): ~~GIA/cash income tax + CGT-on-disposal~~ (**DONE — A5, 2026-06-27**), post-2031 threshold reindexing, per-scheme DB escalation, stochastic house/salary growth, SDLT surcharge timing in buy-vs-rent. Revisit when the app surfaces them.
- [ ] **Demo data:** Rob supplies the anonymised couple's figures later, entered via the UI, not hardcoded (field list in docs/PLAN.md "Data Rob supplies").
- [ ] **Results page — still open:** the **lump-sum tax-shock panel** ✅, **compare-assumptions overlay** ✅ and **2FA enrolment UI** ✅ (DONE 2026-06-28 — `App\Livewire\AccountSecurity`) are now built; remaining: **real-browser verification** of the ApexCharts canvases (the accessible tables/text are tested, the rendered chart is not) and the 2FA QR scan. Run `npm run build` before viewing the app (`public/build` is gitignored).
- [ ] **Queued-run "waiting for a worker" hint (go-live UX, 2026-06-28).** A full 10k run is dispatched to the database queue and needs `php artisan queue:work`; with no worker it shows **"Queued — 0%" indefinitely with no reason** (a silent-failure gap, hit during the verification pass). Planned fix in docs/PLAN.md "Go-live UX backlog": in `ScenarioResults`, show a neutral "start a worker" note when a run sits `queued` with zero progress for ~15s. Small (component timestamp check + Blade line + test); not blocking.
- [x] **`User::canAccessPanel()` lockdown — DONE 2026-06-27.** Gated on a new `is_admin` boolean (was "any authenticated user"), closing the escalation whereby any user could reach `/admin` and grant themselves the advice-style `interpret` capability. First admin via `php artisan user:make-admin {email}`; admins toggle others on the Users resource. Non-admin → 403 (tested).
- [ ] **Deferred earlier, still open:** numeric editing of assumption-set figures in Filament (currently curate-metadata-only, figures seeded from the engine library).

## How to pick up
Run from the **project root** (the test runner shells out to a relative phpunit path, so it fails from `C:\Users\r`):
```powershell
Set-Location "C:\Dev\RetireForecast"
# NB: php / artisan / composer / npm are NOT on the Git Bash PATH on this machine — run them via the
# PowerShell tool (PHP 8.4 is provided by Laravel Herd). Bash is fine for git / grep / file ops. See CLAUDE.md.
php artisan test                            # everything: expect 362 passed (2917 assertions)
php artisan test --testsuite=Engine        # engine only: expect 143 passed (1772 assertions)
npm run a11y                                 # Pa11y CI a11y sweep (needs the served app — see docs/A11Y.md)
vendor/bin/pint --dirty                      # house style on changed files
npm run build                                # build assets (public/build is gitignored); `npm run dev` to watch
```
**Queue worker — run it with JIT for the 10k Monte Carlo (≈2× faster, byte-identical).** OPcache JIT is off by
default on this machine (`opcache.enable_cli=0`, `opcache.jit=disable`), so the full simulation job runs fully
interpreted (~8.9 s for 10k paths) unless you start the worker with JIT (~4.75 s):
```powershell
php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 artisan queue:work
```
The synchronous preview (deterministic, 1 path) does not need it. Re-measure perf with a 10k `Simulator::run` over
the `comfortable` fixture (see DECISIONS 2026-06-28 "10k-path perf"); there is no timed test (wall-clock is
environment-dependent), the `IncomeTaxTotalPenceTest` grid is what guards the refactor's correctness.
If `vendor/` is missing: `composer install`. If engine classes are not found, re-register the path package: `composer update retireforecast/finance-engine`. To use the app locally: `npm run build`, then `php artisan serve` (this build+serve flow works fine under the new CSP). **CSP note:** the `web` group now ships a Content-Security-Policy (`config/security.php`); the policy does **not** include the Vite dev-server/websocket origins, so if you use HMR (`npm run dev`) set `SECURITY_HEADERS_ENABLED=false` in `.env` while developing, or set `SECURITY_CSP_REPORT_ONLY=true` to stage it. Tier-2 residual: open the built app in a real browser and confirm the ApexCharts fan/ladder canvases + Livewire interactions still work under the CSP, then tighten `script-src` to nonces (Alpine CSP build). **DB setup — IMPORTANT:** a local `database.sqlite` that predates the Phase B rebuild has a stale schema (the `create_scenarios` migration was **rewritten in place**, so `php artisan migrate` alone will NOT add `builder_state` — it silently stays on the old `payload`/`household_id` columns and the app breaks). On any pre-rebuild DB, run **`php artisan migrate:fresh --seed`** (drops + rebuilds from the current migrations; `--seed` runs `DatabaseSeeder` → assumption sets + a `test@example.com` / `password` user). For a ready-made, obviously-fictional sample to sign in to, then seed the **demo preset**: `php artisan db:seed --class=Database\Seeders\DemoScenarioSeeder` (idempotent; outside production it creates **`demo@example.com` / `password`** with a base plan + one what-if child — the documented quick-login now the prototype's `td@test.com` is gone; in production set `DEMO_USER_EMAIL` to an existing user instead). Register your own at `/register` and **accept the one-time guidance-only disclaimer at `/welcome`**. **Two-factor authentication** can be enrolled at **`/account/security`** ("Security" in the nav; behind a fresh password confirmation) — turn it on, scan the QR with an authenticator app (or type the setup key), confirm a code, and keep the recovery codes; thereafter login asks for a code at `/two-factor-challenge`. Build a forecast at `/scenarios/create`, run it on its results page; download a **PDF summary** from the results page ("Download PDF summary" link). The **accessibility sweep** is `npm run a11y` against the served app, but this machine's `ignore-scripts=true` blocks the headless-browser download — use **in-browser axe DevTools / Lighthouse** as the authoritative check (`docs/A11Y.md`; a 2026-06-28 local sweep fixed 3 contrast issues). The full queued run needs a worker (`php artisan queue:listen`); the synchronous preview does not. **Admin panel at `/admin` is gated on `is_admin`** (default false) — a non-admin gets 403; grant yourself access once with `php artisan user:make-admin {email}` (e.g. `demo@example.com`), after which the Users resource there toggles **Admin access** + `can_interpret`. Tests neutralise Vite, so they pass without a build.

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
is the only snapshot). This session's commits (newest first): **queued-run "waiting for a worker" hint backlogged** (docs only;
`722b39b`); **a11y WCAG AA contrast fixes + a11y toolchain settled** (3 contrast fixes; axe-only; in-browser axe
DevTools authoritative; `05f4154`); **Phase D Tier-2 — PDF export + a11y CI scaffold**
(`ScenarioPdfController` + `pdf.results` via `barryvdh/laravel-dompdf` + results-page link + `ScenarioPdfTest`;
`Pa11y CI` `.pa11yci.json` + `npm run a11y` + `.github/workflows/a11y.yml` + `docs/A11Y.md`; completes the Tier-2 build;
`adb2bda`); **Phase D Tier-2 — 2FA enrolment UI**
(`App\Livewire\AccountSecurity` + `TwoFactorAuthenticatable` trait + challenge/confirm-password screens + `/account/security`
route behind `password.confirm` + nav link + `TwoFactorAuthenticationTest`; `1c73607`); **Phase D Tier-2 — security headers / CSP**
(`App\Http\Middleware\SecurityHeaders` + `config/security.php` appended to the `web` group + `SecurityHeadersTest`;
`667ce6f`); **Phase D Tier-2 — 10k-path Monte Carlo perf**
(`IncomeTaxCalculator::totalPence()` lean integer twin sharing `bandedTax()` with `compute()` + projector routing +
`IncomeTaxTotalPenceTest` 1,120-cell equivalence grid + worker-JIT docs; `61e808f`); **Phase D Tier-2 — demo preset/seeder**
(`App\Demo\DemoScenario` + `DemoScenarioSeeder` + `DemoScenarioTest`/`DemoScenarioSeederTest`; `432e605`);
**Phase D Tier-1 — user-facing import
reconciliation panel** (`ReconciliationLine` + `ImportResult::reconciliation` + the three profiles emit it +
Blade panel + CSP `NET WORTH`/`INCOME` parser fix; completes Tier-1; `378e4e3`); **Phase D Tier-1 —
displayed-figure provenance** (one shared `%` formatter + fan/ladder CSV == panel + interpretation provenance test; `e6884ec`);
**Phase D Tier-1 — forecast-boundary
reconciliation invariants** (`HousingProceeds` + net-proceeds reconciliation, total-wealth-from-parts, penny-drift
fix; `d1ffa5a`, hash-record `bd9efa7`); **Phase D — admin-panel lockdown (`is_admin`)**
(`b98caba`); **Phase D — gov.uk figure-verification pass** (`52f3679`, hash-record `bf5d1a0`); **A5 part 2 — CGT
on GIA disposal** (`b78c1a4`); **A5 part 1 — GIA/cash income tax** (`937413b`); **C4 — PLSA benchmark +
engine-isolation guard** (`df9fce0`).
Rebuild commits (newest first): **C1 fast-follow commit-hash record** (`0915215`); **Phase C1 fast-follow** (`c967426`); doc reconciliation `47436c3`; **Phase C1 core (3-tier line-item budget)**
(`2d553d4`); **C2 delta-child what-ifs + Compare** (`5530896`); **Phase B storage inversion** (the
`builder_state`-source-of-truth rewrite + edit-in-place; `60cc2e2`);
`9a70d0a`/`2587324` doc refresh + post-rebuild checkpoint; `49637e4` results usable-vs-total +
cashflow ladder (C3); `b50f2a5` income-by-source on YearResult (A4); `12bd216` per-person longevity (A2);
`9316e7c` ongoing contributions + usable-vs-total terminal wealth (A1+A3). Built across a series of small committed milestones — engine (docs scaffold, NI+savings/dividends, pension suite, State Pension, SDLT+CGT, benefits, IHT+care, forecast+MonteCarlo+housing); app layer (persistence; Fortify+GDPR; Filament admin; forecast services; run persistence; queued runs + engine progress hook; UI foundation + auth; scenario builder; results page + ApexCharts; this session's builder UX + sector planning). **Everything described above is committed; the working tree is clean.** Recent commits (newest first): `6551219` results-card label clarity ("Total wealth left (incl. home)"); `84292c5` planning close-out (delta what-ifs / 3-tier budget / longevity / usable-vs-total); `2b5abc8` scenario drafts + person names + State Pension shortcut + sector research; `7219f72` import reconciliation guardrails + IWT CSP double-count fix. No remote; commit directly to `master`.

## Session log
_2026-06-28 (parked: statement-driven onboarding + document import — the local-AI question, reframed)_ — Rob
asked whether a local **Ollama** AI could run the forecasting/modelling. Investigated (web research): **no** —
chat LLMs are unreliable at arithmetic, non-deterministic, non-auditable and would break HMRC-to-the-penny +
reproducibility + sourcing + no-silent-failure; the real "AI forecasters" (time-series foundation models —
Chronos/TimesFM/Moirai) aren't Ollama models and still can't model UK tax law or a specific household; the
engine's deterministic-rules + Monte-Carlo design is already the right tool. Rob then reframed to the genuinely
useful idea: **upload documents** (bank/credit-card statements, payslips, benefit statements), extract +
pre-fill the wizard, ask only the remainder, and build the budget from **actual** spend rather than "average
user" figures. Captured as a **parked, post-v1** feature across three docs: new
**[docs/RESEARCH-document-import.md](docs/RESEARCH-document-import.md)** (sector evidence; the document→builder-field
map; 3-tier categorisation presentation; gotchas DI-1…DI-9), a **PARKED** section in docs/PLAN.md, and a
DECISIONS.md entry. Load-bearing calls: **transfer-matching is deterministic-only** (the "£1,258 card payment
looks like £2,516 of spend" internal-transfer double-count is the inconsistent-aggregation bug class the project
was burned by — DECISIONS 2026-06-25 — not an LLM job; rules + user-confirmation + reconciliation + a real-file
golden fixture); **categorisation is rules-first** (60–80% at perfect accuracy) with an **optional, walled-off,
LOCAL-only LLM assist** for the long tail (bank data never leaves the machine; a mis-tier never changes the grand
total); benefit statements must classify **taxable vs tax-free** (the DLA completeness rule); **actuals = the
input baseline, PLSA stays the benchmark, not the input**; Open Banking out of scope (file import — CSV/OFX/QIF,
PDF+OCR a sub-phase — is the local path); architecture extends `app/Import/`, app-layer only. **Docs-only — no
code changed; suite unchanged (359 green).**
_2026-06-28 (run-out verdict — keep the punch, fix the contradiction)_ — Rob wanted to keep the visceral
"you'll run out of money" framing while fixing the "55% runs out vs £659k wealth left" contradiction. Added
`ResultPresenter::runOutVerdict()` — a blunt plain-English verdict per option on the results cards, scaling from
"the money lasts in every simulated future" to "you'd very likely run out of money before the end", colour-coded
(role=alert at high risk). It is a **factual** statement about the simulated futures (anchored "on these figures"),
never a recommendation, so it stays guidance-side and **clears the banned-phrasing partition lint** (confirmed by
the suite). Kept the "Chance of running out" label (the punch) and rewrote the footnote to reconcile the two
figures: "running out" = a future with ≥1 year essentials weren't fully covered (may later recover); "wealth left"
= the median end-of-life amount; "total" includes any home still owned. Suite **361 → 362 green**; pint clean;
Blade/PHP only (no rebuild). Closes the label-clarity item offered earlier.
_2026-06-28 (results-page fan chart fix + a wealth-over-time burndown overlay on Compare)_ — From live use of the
full 10k run. **(1) Fan chart was blank** while the bar chart rendered: the fan's `yaxis.labels.formatter` was set
to `null`, which ApexCharts calls as a function and throws, failing that chart's render (the bar chart had no
formatter). Removed it (ApexCharts defaults), and **hardened the Alpine chart wrapper** (`resources/js/charts.js`):
render is wrapped in try/catch — on failure it logs to the console and shows a visible "chart could not be drawn,
the figures are in the table below" fallback rather than a silent blank (charts are a progressive enhancement; the
table is the source of truth). **(2) Burndown overlay (requested):** the Compare page now shows "Usable wealth over
time" — the base + each delta-child what-if as one overlaid line. New `ResultPresenter::burndown()` plots usable
wealth (excl. home = `liquidWealth + pensionWealth`, the SAME definition the cashflow ladder uses, so no drift),
reusing the one deterministic projection per plan the summary table already computes; backed by an accessible
year × plan table. Tests: the overlay renders; the burndown figures reconcile to the ladder's usable wealth
year-for-year. Suite **359 → 361 green**; pint clean; `npm run build` run. **Verified during the same live run:** the
full 10k run completes end-to-end and the comparison **bar chart renders under the CSP** (so the CSP eyeball passes
for that chart type). **Still open (offered, not yet actioned):** a wording clarifier on the results cards — "chance
of running out" means "at least one year essentials weren't fully met" (can be transient + recover), which reads as
contradictory beside the positive "wealth left" for the rent option; see docs/PLAN.md "Go-live UX backlog".
_2026-06-28 (re-review findings RESOLVED — all five fixed)_ — Implemented every finding from the re-review.
**Finding 1 (PDF/screen MC divergence + provenance):** added `Scenario::latestCompletedRun()` as the one source for
the presented run; `ScenarioResults` now presents that (a newer failed/cancelled run no longer hides the last good
result) and the PDF reads the same method, so they can't diverge; the PDF MC section stamps mode/paths/seed/date;
`DisplayedFigureProvenanceTest` extended to the PDF (the surface that wasn't covered — why the divergence slipped
through), plus a screen fallback test. **Finding 4:** `medianDepletionYear` now in `ResultPresenter::comparison()`
rows → reaches the comparison table + PDF. **Finding 3:** `PathProjector::disposeGiaSlice()` extracted — rounds the
realised gain and derives the consumed basis as the remainder (gain + basis == take exactly, no drift), pinned by a
multi-disposal conservation test. **Finding 5:** cash-interest conservation test added (the "cash is taxed" case is
situational — the savings starting-rate band shelters it at low income — so not asserted). **Finding 2 (freezeEndYear):**
implemented threshold un-freezing — the income-tax function is homogeneous degree 1 in (income, thresholds), so
`indexedTotalPence()` taxes income deflated to the freeze-end price level against the frozen thresholds and re-inflates,
equal to taxing under inflated thresholds without rebuilding the band config in the hot loop (factor 1.0 = identity
during the freeze + for the HMRC unit tests); threaded through the main pass + drawdown grossing-up; `ThresholdFreezeTest`
pins it (identical within the freeze, lower after, lower cumulative drag); the MC golden master is a determinism check
(no snapshot to regenerate); contradictory docblock corrected. Commits `fff3f07` (findings 1/3/4/5) + `86d5d82`
(finding 2). Suite **353 → 359 green** (engine 139 → 143, app 214 → 216); pint clean. **Next:** the browser-only
go-live eyeballs remain (2FA QR, PDF layout) + the backlogged queued-run "waiting for a worker" hint.
_2026-06-28 (re-review — project + docs on-track check; data-presentation findings folded in)_ — Re-reviewed the
project and docs (3 parallel agents over the data-presentation surfaces, the engine analysis, and doc/code drift;
findings then verified directly in code). **Verdict: on track** — 353 tests green, tree clean, every claimed feature
exists, test counts exact, and the data-integrity discipline holds (one shared `%` formatter, usable-vs-total one
definition, no stored aggregates, per-source completeness incl. tax-free income, reconciliation tests, no
recommendation leak — all confirmed clean). **Verified findings folded into the docs** (Known bugs / Blockers here +
docs/PLAN.md "Review findings (2026-06-28)" + the Verification + Risks sections): **[MED]** the PDF Monte-Carlo
summary can diverge from the screen (PDF picks latest *Done*, screen shows latest-if-Done) and carries no run
provenance — *and the PDF surface, added after `DisplayedFigureProvenanceTest`, is not covered by that test, which is
why it slipped through*; **[MED]** `ForecastSettings::$freezeEndYear` is documented but never implemented (thresholds
frozen for the whole projection → overstates post-2031 drag); **[LOW]** GIA disposal basis float-split rounds parts
independently (`PathProjector:533-534`); **[LOW]** `medianDepletionYear` reaches only the screen headline; **[LOW]**
no cash-interest conservation test. **Also fixed the doc drift the audit found** (committed `32b074c`): the stale a11y
"not yet run green" lead, the `DATA-MODEL.md` date, and the branch-status commit hashes. No code changed this
review; the findings carry recommendations + the two decisions (PDF run-fallback behaviour; implement-vs-document
`freezeEndYear`) for Rob. **Next:** action the re-review findings (PDF fix first, recommended), then the remaining
browser eyeballs (2FA QR, PDF layout).
_2026-06-28 (Phase D Tier-2 — verification pass: build + serve + a11y sweep; 3 contrast fixes)_ — Rob ran
`npm install && npm run build && php artisan serve` (build OK, app serves) and `npm run a11y`, which surfaced
real toolchain issues. Diagnosed + acted: (1) **3 genuine WCAG AA contrast failures fixed** — `text-gray-400` on
the builder *Discard* button and the dashboard *what-if* label (≈2.9:1) → `text-gray-600`, and a `text-gray-300`
Compare separator (≈1.6:1) → `text-gray-500` + `aria-hidden` (found by static review of the colour classes, so the
fix is tool-independent). (2) **Two environment facts:** npm here has **`ignore-scripts=true`**, so no headless
browser binary (puppeteer Chromium, chromedriver) ever downloads — `pa11y-ci` and `@axe-core/cli` can't fetch a
browser locally (CI on Linux is fine); and Pa11y CI's bundled **axe-core is 4.2 (2021)**, which emitted a **false
positive** (contextless "color-contrast" on the public pages, which have no sub-AA text). (3) **Tooling settled:**
runners → axe-only (HTMLCS `checkControlGroups` crashes under current Chrome), removed the diagnostic `@axe-core/cli`
dep, trimmed `.pa11yci.json` to the verified-working URLs (public + `/welcome`, reached via a scripted demo login),
and rewrote `docs/A11Y.md` to make **in-browser axe DevTools / Lighthouse the authoritative check** (covers authed
pages, current axe, no downloads) with Pa11y CI demoted to a coarse CI smoke. Suite still **353 green** (the contrast
fixes are Blade-class only; no test asserts the old classes); pint clean. **Still owed (needs a human at the
browser):** the visual eyeball — ApexCharts render under the CSP, the 2FA QR scans with an authenticator app, the PDF
layout looks right — and a current-axe DevTools pass over the authed forecast pages.
_2026-06-28 (Phase D Tier-2 — PDF export + a11y CI scaffold; completes the Tier-2 build)_ — Continued straight on
(Rob chose "both remaining items"). **PDF export:** added `barryvdh/laravel-dompdf` (pure-PHP, no browser/binary,
app-layer only so the engine stays dependency-free) and `App\Http\Controllers\ScenarioPdfController` (`GET
/scenarios/{scenario}/results/pdf`, owner-scoped, in the disclaimer-acknowledged group; a draft 404s) rendering a
print-friendly `resources/views/pdf/results.blade.php`. The report is built from the **same `ResultPresenter`** the
on-screen page uses (tax shock, income floor, 3-tier budget, PLSA benchmark, the cashflow ladder, + the Monte Carlo
headline summary when a completed run exists), so the print can't drift from the screen (provenance rule); the data
assembly is a public `data()` method the test renders directly. The PDF carries the guidance-only disclaimer +
signposting (passes the banned-phrasing lint); a "Download PDF summary" link sits on the results page. The full
per-year income-by-source split stays in the CSV; the PDF shows the wealth trajectory (tax/spend/usable/total) so
the table is portrait-friendly and every column is a presenter string (no in-view derivation). `ScenarioPdfTest` (5):
the route streams a real `%PDF`, owner-scopes (403), 404s a draft, renders the figures + disclaimer, and adds the MC
summary once a run exists. **a11y CI:** scaffolded **Pa11y CI** (axe-core + HTML CodeSniffer, WCAG2AA) — `.pa11yci.json`
(public pages run with no setup; the authed shell pages `/welcome`, `/dashboard`, `/scenarios/create` reached by a
scripted login + disclaimer-acknowledge with the seeded demo account), `pa11y-ci` devDependency + `npm run a11y`,
`.github/workflows/a11y.yml` (build → migrate:fresh --seed → demo seed → serve → sweep; dormant until a remote
exists), and `docs/A11Y.md`. **Honest caveat:** the a11y sweep is **not yet run green** here — it needs a headless
Chrome + the served app, which is the real-browser verification pass this item always required; the config/workflow
are correct-by-construction and documented as unrun. Suite **348 → 353 green / 2858 assertions** (app 209 → 214;
engine 139 untouched); pint clean; banned-phrasing lint still green (the PDF view is neutral). **This completes the
Tier-2 BUILD** — demo, perf, CSP, 2FA, PDF, a11y scaffold all done. **Next: one real-browser verification pass** —
run `npm run a11y` against the served app, eyeball that ApexCharts/Livewire render under the CSP, the 2FA QR scans,
and the PDF layout looks right; then optionally tighten the CSP `script-src` to nonces (Alpine CSP build). With that,
Phase D / go-live is essentially complete.
_2026-06-28 (Phase D Tier-2 — 2FA enrolment UI)_ — Continued the same session straight on from the CSP work
(at Rob's "go"). Picked the next Tier-2 item most self-contained and headlessly verifiable: the **two-factor
enrolment UI**. Fortify's 2FA feature was already enabled (`config/fortify.php`, `confirm` + `confirmPassword`)
and the columns long migrated, but the `User` model lacked the `TwoFactorAuthenticatable` trait and **no screens
existed**, so no user could enrol — a real shipped-surface security gap. Built `App\Livewire\AccountSecurity`
(full-page Livewire at `/account/security`) that drives Fortify's **own actions** directly (`EnableTwoFactorAuthentication`,
`ConfirmTwoFactorAuthentication`, `GenerateNewRecoveryCodes`, `DisableTwoFactorAuthentication`) for a single-page
flow: turn on → show QR + setup key → confirm a code → reveal recovery codes; regenerate; turn off. Added the
trait to `User` (secret/codes are stored encrypted and read raw by the trait, so **no** `encrypted` cast — that
would double-encrypt — and added to `$hidden`; cast `two_factor_confirmed_at` to datetime). Wired the two
previously-missing Fortify views in `FortifyServiceProvider` — the login **two-factor-challenge** and the
**password-confirmation** screen (both new Blade screens matching the existing auth-screen style; the challenge
uses a native `<details>` disclosure for the recovery-code field, no JS). Because the component bypasses Fortify's
endpoints (and their `confirmPassword` middleware), the page **route** is placed behind `password.confirm` (a
"sudo" gate); it sits outside the disclaimer gate like the GDPR controls. Added a "Security" nav link. Tested by
`TwoFactorAuthenticationTest` (8 cases): enable+confirm with a computed current TOTP, wrong-code rejected,
recovery-code regeneration, disable, the **full login challenge driven to completion**, and the password-confirm
gate (redirect when unconfirmed, loads when confirmed) + the confirm-password screen renders. **Diagnosed a test
gotcha:** Fortify rejects reuse of a TOTP within its verification window (a current code verifies once per
process), so a test that both confirms enrolment and later completes a login challenge must not spend the same
code twice — the enrol helper now stamps `two_factor_confirmed_at` directly rather than burning a code. **Residual
(documented):** a real-browser eyeball that the QR renders + an authenticator app round-trips (the SVG + flow are
headless-tested, the visual scan is not). Suite **340 → 348 green / 2845 assertions** (app 201 → 209; engine 139
untouched); pint clean; banned-phrasing partition lint still green (the new screens/component carry no directive
phrasing). **Next: the rest of Tier-2** — a11y CI (axe/Pa11y) + PDF export (both best paired with one real-browser
verification pass that also covers the CSP eyeball + the 2FA QR scan).
_2026-06-28 (Phase D Tier-2 — security headers / CSP)_ — Resumed via `/handover resume`; restated the grounding,
sanity-checked the suite green (332/2780, tree clean on `master`), and per Rob's standing "execute the agreed plan"
preference picked the next Tier-2 item that is **fully verifiable headlessly**: the **CSP / security-headers**
work (a11y CI, PDF fidelity and 2FA screens all need a real-browser eyeball). First mapped the runtime so the policy
would be compatible-by-construction: scripts/styles load via `@vite` (external self-hosted bundle), fonts are
**self-hosted** by the Bunny Vite plugin (downloaded at build, served from `/build`), there is **no runtime CDN**,
and Filament `/admin` runs its **own** middleware stack (not the `web` group) with its own Bunny-hosted font — so a
`web`-group CSP with `font-src 'self'` correctly never touches admin. Built `App\Http\Middleware\SecurityHeaders`
(appended to the `web` group in `bootstrap/app.php`) reading `config/security.php` (one home for the directives +
toggles, so the test asserts the same definition the middleware emits). The CSP is **enforcing but
compatible**: `default-src 'self'`; `script-src`/`style-src` keep `'unsafe-inline'`/`'unsafe-eval'` (Livewire injects
an inline init script, Alpine evaluates via the Function constructor, ApexCharts injects inline styles);
`img-src 'self' data:`, `font-src`/`connect-src 'self'`; and the high-value structural lockdowns
`object-src 'none'` / `base-uri 'self'` / `form-action 'self'` / `frame-ancestors 'none'`. Added the static hardening
headers (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`,
a restrictive `Permissions-Policy`) and two env toggles: `SECURITY_HEADERS_ENABLED` (master switch) +
`SECURITY_CSP_REPORT_ONLY` (stage a rollout via `Content-Security-Policy-Report-Only`). Tested by
`SecurityHeadersTest` (8 cases): header present on `/` and the Fortify login screen, the structural + stack
directives, an **exact match to the configured policy**, the static headers, the report-only swap and the
disabled-switch. Shipped enforcing (not report-only) because the policy is permissive exactly where this self-hosted
stack needs it, so breakage risk is low while the structural protections are real. **Residual (documented, not a
regression):** open the built app in a real browser to confirm the ApexCharts canvases + Livewire interactions still
render under the CSP, then tighten `script-src` to nonce-based (drop `'unsafe-inline'`/`'unsafe-eval'`) via Alpine's
CSP build — that step genuinely needs the browser. Suite **332 → 340 green / 2808 assertions** (app 193 → 201;
engine 139 untouched); pint clean. **Next: the rest of Tier-2** — a11y CI (axe/Pa11y), PDF export, 2FA enrolment UI
(the browser-dependent items, best paired with one real-browser verification pass that also covers the CSP eyeball
above).
_2026-06-28 (Phase D Tier-2 — 10k-path Monte Carlo perf)_ — Resumed via `/handover resume`; restated the grounding,
sanity-checked the engine suite green (137/635), and per Rob's standing "execute the agreed plan" preference picked
the next Tier-2 item that is **fully verifiable headlessly**: 10k-path Monte Carlo perf (a11y CI, CSP-under-Filament,
PDF fidelity and 2FA screens all need a real-browser eyeball). Built a faithful 10k benchmark over the `comfortable`
MC fixture: baseline **13.9 s**, ~1.39 ms/path, CPU-bound (mem fine). Component profiling put **93%** of per-path
time in `PathProjector::project()` (sampling + path-gen negligible), and within it the income-tax calculator
dominates — yet every projector tax call reads only `->total->pence` while `compute()` builds a full `Money`/`lines`
breakdown the hot loop discards. So added `IncomeTaxCalculator::totalPence(): int`, a lean integer total sharing the
**same private band core** (`bandedTax()`) as `compute()` (one computation, two presentations; allowance taper moved
to an integer `grantedAllowancePence()` the Money `personalAllowance()` delegates to), and routed the projector's
main per-person pass + `marginalTax()` through it. **No behaviour change** — the per-slice integer rounding mirrors
`Money::applyRate` exactly; the rich `compute()` result is consumed only by the composite test (production reads only
the total). Guarded by a new `IncomeTaxTotalPenceTest` pinning `totalPence($i) === compute($i)->total->pence` across a
**1,120-cell grid** (every band crossing × taper window × PSA tier × dividend allowance × both tax years) — the
one-definition-one-home rule applied to the perf split. **Measured (byte-identical results throughout, successEss
0.959 / p50 £454,828):** the refactor alone took 10k **13.9 s → 8.9 s** (1.57×) interpreted; OPcache JIT (off by
default here) takes it **→ 4.75 s** (2.9× vs original), the leaner allocation profile compounding with JIT. JIT is a
PHP *startup* setting, so it is surfaced as a documented worker invocation (How to pick up) rather than silently
written into Rob's global Herd `php.ini`; no timed test added (wall-clock is environment-dependent, the equivalence
grid is the real guard). Suite **330 → 332 green / 2780 assertions** (engine 137 → 139; app 193 untouched); pint
clean. **Next: the rest of Tier-2** — CSP header, a11y CI (axe/Pa11y), PDF export, 2FA enrolment UI (the
browser-dependent items, best paired with a real-browser verification pass).
_2026-06-28 (Phase D Tier-2 — demo preset/seeder)_ — Resumed via `/handover resume`; restated the grounding,
sanity-checked the suite green (320/1626), and (per Rob's standing preference to execute the agreed plan rather
than re-ask) determined the next step from the plan: Tier-1 (trust) is complete, so the next item is Tier-2
go-live polish. Picked the **demo preset/seeder** first as the highest-value Tier-2 item that is fully verifiable
in this environment (CSP + a11y-CI both need real-browser eyeballing) and is sanctioned by a locked decision
("any first-run sample must be obviously fictional"). Built `App\Demo\DemoScenario` — the single home for an
obviously-fictional sample plan in the canonical `builder_state` shape (so it assembles to the engine DTOs and
runs exactly like a user-built scenario, no parallel representation that could drift) — plus
`Database\Seeders\DemoScenarioSeeder`, which persists it as a **base plan + one delta-child what-if** ("retire two
years earlier", derived via `BuilderStateDelta::diff` so the child stores only the override, base stays the single
source). Idempotent (matched by owner+name, drops stale runs on re-seed); no-silent-failure + release-safe
(provisions a fictional `demo@example.com`/`password` account outside production; in production refuses to mint
default credentials unless `DEMO_USER_EMAIL` names an existing user). Opt-in (not wired into `DatabaseSeeder`):
`php artisan db:seed --class=Database\Seeders\DemoScenarioSeeder`. Tests: `DemoScenarioTest` (assembly to a
runnable household; spend totals reconcile to the line items; the what-if is a value-only delta on
`people.p1.plannedRetirementAge`; retiring earlier demonstrably lowers central wealth) + `DemoScenarioSeederTest`
(runnable base+child through the forecaster — a living integration smoke; the what-if changes the forecast;
idempotent; attaches to an explicit `DEMO_USER_EMAIL` user; refuses in production). Suite **320 → 330 green /
1658 assertions** (app 183 → 193; engine 137 untouched); pint clean (it resolved the `{@see}` docblock to an
app-layer import — no engine-isolation concern). **Verified live against the real local DB:** seeding it first
surfaced that the local `database.sqlite` was still on the **pre-rebuild prototype schema** (`scenarios.payload`/
`household_id`, no `builder_state`; the dropped `households`/`scenario_drafts` tables still present) — because the
rebuild **rewrote the create-scenarios migration in place**, so a DB already migrated under the old definition never
gained the Phase B columns (`php artisan migrate` is insufficient; `migrate:fresh` is required). Backed up the file,
then with Rob's go-ahead ran `migrate:fresh --seed` + the demo seed; the seeded base runs to 2049 with £1,124,230
terminal wealth and the what-if (retire 2 yrs earlier) to £1,028,348 — ~£96k lower, proving the override reaches the
engine. The prototype's manually-created `td@test.com` user was dropped by the fresh; `demo@example.com` / `password`
is now the documented quick-login. (How-to-pick-up updated with the `migrate:fresh` requirement.) **Next: the rest
of Tier-2** — CSP header, a11y CI (axe/Pa11y), PDF export, 10k-path perf, 2FA enrolment UI.
_2026-06-28 (Phase D Tier-1 COMPLETE — user-facing import reconciliation panel + a latent CSP parser fix)_ —
Resumed via `/handover resume`, sanity-checked the suite green (309/1589), then built the last Tier-1
data-integrity item: the user-facing import reconciliation panel. Added `App\Import\ReconciliationLine` (pairs the
figure that went into the form with the sheet's own independent figure for the same quantity, compared in exact
pence — `reconciles()`/`mismatch()`/`toArray()`), carried it on `ImportResult::reconciliation`, and had the three
calibrated profiles emit it: `PayAndExpenditures` now captures the sheet's own Total row (previously discarded) and
reconciles it against the summed lines; `ConsciousSpendingPlan` reconciles each bucket's stated `… TOTAL` against
its line-item sum; `RetireForecastTemplate` surfaces each category for eyeball review (no independent total →
`stated = null`). The Blade import panel renders each pair and turns red + `role=alert` on a divergence (the
banned-phrasing partition lint still passes). Tests: `ReconciliationLineTest` (unit), extended
`ImportReconciliationTest`, and two Livewire panel tests — including a deliberately-inconsistent golden fixture
(`csp-inconsistent-bucket-total`) proving a mismatch is surfaced, not silent. **A latent correctness fix fell
out:** the CSP `lineSum` cross-check was polluted by the `NET WORTH` balance-sheet rows (Investments/Savings share
the bucket keywords), so the parser now skips the `NET WORTH`/`INCOME` sections — no imported figure changes (the
stated TOTAL stays authoritative). Suite **309 → 320 green / 1626 assertions** (app 172 → 183; engine 137); pint
clean. **This completes Tier-1 (trust).** Also, per Rob's request this session, updated the `handover` skill so
**save mode auto-checkpoints** (commits without stopping to ask), with a guard added to the `checkpoint` skill so
the two can't recurse (these live in `~/.claude/skills/`, outside the repo). **Next: Phase D Tier-2 go-live
polish** — CSP header, a11y CI (axe/Pa11y), demo preset/seeder, PDF export, 10k-path perf, 2FA enrolment UI.
_2026-06-28 (Phase D Tier-1 — displayed-figure provenance: panel == CSV == interpretation)_ — Continued the same
session straight on from the forecast-boundary invariants. Closed the second Tier-1 data-integrity item: the
displayed-figure provenance discipline ("every figure traces to one computed value"). Traced the three output
surfaces — `ResultPresenter` (the panel), the two CSV exporters (`downloadFanCsv`/`downloadLadderCsv`), and the
walled-off `Interpretation` — and found they already route through one source EXCEPT a duplicated percentage
formatter (`ResultPresenter::pct` and an identical inline closure in `Interpretation`, a silent-drift risk if either
changed). Consolidated to one public **`ResultPresenter::formatPercent()`** shared by the panel, comparison table,
CSV exports and `Interpretation`. Added **`DisplayedFigureProvenanceTest`** (Feature): parses the fan CSV back and
asserts it equals the panel's fan rows exactly; parses the ladder CSV and asserts every income-by-source / tax /
spend / usable / total figure equals the panel ladder; and asserts every percentage the interpretation quotes is one
the neutral panel shows (so a reformat would fail). The CSV exporters needed no change — they already read the
presenter; the test locks that single-source design so a future re-derivation can't drift. Suite **306 → 309 green /
1589 assertions** (app 169 → 172; engine 137); pint clean. **Next (last Tier-1 item):** the user-facing import
reconciliation panel (surface each imported/aggregated total beside the sheet's own stated total for review), then
Tier-2 go-live polish (CSP header, a11y CI, demo preset, PDF, perf).
_2026-06-28 (Phase D Tier-1 — forecast-boundary reconciliation invariants + a real penny-drift fix)_ — Resumed
via `/handover resume`; on Rob's instruction to **stop asking what to do next and execute the agreed plan**
(saved as a global preference in `About-Me/Preferences.md` → "Executing against an agreed plan"). Determined the
workflow from the plan: Phase D, and within it the Readiness tiers put **Tier 1 (trust) before Tier 2 (go-live
polish)**, so the next step was the one unfinished Tier-1 item — the **data-layer integrity guardrails**, starting
with the forecast-boundary reconciliation invariants the plan's Verification section names. Sanity-checked green
(298/1164). Built a single-source **`HousingProceeds`** value object (sale → net proceeds + mortgage + selling costs
+ CGT) and made **`HousingComparison::saleProceeds()`** public (the buy/rent variants now read it; it's also the
source for the future reconciliation panel). Added **`HousingProceedsReconciliationTest`** (sale price reconciles to
net proceeds + its deductions; CGT £0 under PRR; default 2% + custom selling-cost rate; negative-equity floors at
£0) and **`WealthReconciliationTest`** (every year + the two terminal headlines: total wealth == liquid + pension +
property). The wealth invariant **immediately caught a real bug**: `PathProjector` computed `totalWealth` by rounding
the raw sum independently of the separately-rounded legs, so total drifted from liquid+pension+property by a penny in
2028 (round-of-sum != sum-of-rounds — exactly the "a total that can drift from its parts" failure Rob's hard rule
forbids). Fixed by rounding each leg once and deriving the total from those parts; this also turns the pre-existing
`terminalTotal − terminalUsable == property` assertion from passing-by-luck into holding by construction. No other
test shifted. Suite **298 → 306 green / 1201 assertions** (engine 129 → 137, app 169); pint clean. **Next (still
Tier-1):** the displayed-figure provenance test (panel == CSV == interpretation), then the import reconciliation
panel; then Tier-2 go-live polish (CSP header, a11y CI, demo preset, PDF, perf).
_2026-06-27 (Phase D go-live polish — admin-panel lockdown)_ — Continued straight from the figure-pass
checkpoint. Closed the **`User::canAccessPanel()`** privilege-escalation gap (it returned `true` for every
authenticated user, so anyone could reach `/admin` and toggle themselves the advice-style `interpret` grant).
Added a new **`is_admin`** boolean (migration `2026_06_27_120000`, default false, cast on the model); `canAccessPanel()`
now returns `$this->is_admin === true`. Bootstrap the first admin from the CLI — new **`user:make-admin {email}`**
command (`--revoke` to undo; no-silent-failure: unknown email fails loudly, already-in-state is a reported no-op).
Surfaced an **Admin access** `ToggleColumn` on the Filament Users resource so an existing admin can manage others,
beside the `can_interpret` toggle. Factory gained an `admin()` state + an `is_admin => false` default. Tests:
non-admin → **403** from the panel, admins pass (the three existing panel tests moved to `->admin()`), and a new
`MakeUserAdminTest` (grant / revoke / unknown-email-fails / no-op). Suite **293 → 298 green / 1164 assertions**
(app 164 → 169); pint clean. **Local note:** run `php artisan migrate` then `php artisan user:make-admin td@test.com`
to regain admin access locally. **Next:** the remaining go-live polish — a11y CI (axe/Pa11y), CSP header, 10k-path
perf, PDF export, 2FA enrolment UI.
_2026-06-27 (Phase D — gov.uk figure-verification pass, Tier-1 trust gate)_ — Resumed via `/handover resume`;
sanity-checked the suite green (**293 / 1153**), then ran the **figure-verification pass** the plan made the
Tier-1 go-live gate. Inventoried every ⚠️ marker in `packages/finance-engine/src` (12 files) and the plan's
checklist, then re-confirmed each statutory figure against gov.uk via WebFetch/WebSearch (income tax, NI 26/27,
dividends 26/27, pensions incl. LSA £268,275 / LSDBA £1,073,100 / MPAA £10k / tapered-AA £200k-£260k-£10k / NMPA
57 from 6 Apr 2028, State Pension £241.30/wk + SPA Pensions-Act-2014 dates, CGT 18/24 + £3k AEA + HS283 final-9-
months/lettings, SDLT bands + 5% surcharge, Pension Credit capital tariff + £16k HB cut-off, IHT £325k/£175k/£2m/
40%, care £23,250/£14,250 + £86k-cap-cancelled, and the PLSA RLS table). **Every figure was already correct — no
value changed.** Material finding: the **April-2027 unused-pensions-in-IHT change is now ENACTED** (Finance Act
2026, Royal Assent 18 Mar 2026), so its docblock was upgraded from "proposed". Rewrote all ⚠️ docblocks to record
the source + confirmation, moved every `verified_on`/`VERIFIED_ON` to **2026-06-27** (the two `TaxYearConfig`
stamps + the PLSA const), reclassified `investmentIncomeYield` 2% as a **modelling assumption, not a statutory
figure** (reviewed + kept), and left Scottish bands + LBTT/LTT explicitly unverified (out of v1 scope, resolver
throws). Updated the 4 coupled date-assertions (PLSA `VERIFIED_ON`, benchmark `verifiedOn`, Filament "Verified …",
`taxyear_config_version` fixtures). Suite **293 green** (unchanged — provenance only); pint clean (no
`fully_qualified_strict_types` regression). Updated PLAN.md (checklist + inline ✅), DECISIONS.md (new entry),
HANDOVER (Status/Still-to-build/Tier-1/blockers/this log). **Next: Phase D go-live polish** — a11y CI (axe/Pa11y),
CSP header, `User::canAccessPanel()` lockdown, 10k-path perf, PDF export, 2FA enrolment UI.
_2026-06-27 (Phase D — A5 complete: CGT on GIA disposal)_ — Continued from the A5 income-side checkpoint
(`937413b`). Added **CGT-on-disposal**, closing A5. The projector now tracks a per-person **GIA cost basis**
(initialised from `Account::$unrealisedGain`, raised by GIA contributions); when a GIA is sold in `fundShortfall`
the **pro-rata gain is realised** and the matching basis slice consumed. After the spend draw, `capitalGainsTax()`
charges the year's realised gains: shared **£3k AEA** per person, then the basic-rate band left after income at
**18%** and the rest at **24%** (reusing `CgtParameters`, whose residential rates equal the share-gain rates
since the Oct-2024 Budget). The CGT is added to the year's tax and funded by drawing a little more (not counted
as spend). **v1 simplifications (flagged):** capital losses are not relieved; the CGT band is judged on
non-savings income; the small extra gain from funding the CGT itself is not re-taxed. Proven by
`GiaCapitalGainsTaxTest`: a £300k GIA with a £200k embedded gain pays material CGT on the year-0 disposal where
a no-gain GIA pays none, and the gainful holding (CGT drag) ends with no more wealth than the no-gain one. Suite
**291 → 293 green** (engine 127 → 129, app 164); pint clean. **A5 fully closed — GIA/CGT no longer deferred.**
**Next:** the rest of Phase D — the gov.uk ⚠️ figure-verification pass (now also covering the CGT rates + £3k
AEA this relies on, plus the PLSA + `investmentIncomeYield` figures), then go-live polish.
_2026-06-27 (Phase D — A5 income side: GIA/cash income tax)_ — Continued the same session after the C4
checkpoint (`df9fce0`). Started Phase D with **A5** (the GIA/cash income tax + CGT modelling deferred from the
rebuild). Rob set the **order to minimise total risk** (do the significant engine change early, before the
no-engine-change polish is layered on top) and chose the **full** A5 scope (annual income tax + CGT). Built the
**income side**: a new sourced `AssumptionSet::$investmentIncomeYield` (nominal **2.0%**, uniform across the
three sets; anchored to the global-equity dividend yield ~1.3-2%; ⚠️ flagged for the figure pass) threaded
through the mapper/seeder + `PathDraws` (+ both draw implementations). The projector now splits a GIA's/cash's
**total return into income + capital growth**: cash interest (savings) and GIA dividends (dividend income) are
**paid out to net cash and taxed each year** via the existing combined pass, and the asset then **grows at
capital only** (total − yield) — so income + capital growth == total return, **never double-counted** (the
exact failure mode that deferred this). ISA stays tax-free. New `investment_income` income source on
`YearResult`. Proven by `InvestmentIncomeTaxTest`: a £200k GIA throws off £4k taxable dividends (completeness),
an equal ISA throws off none, the GIA is taxed where the ISA is not, and the taxed/capital-only GIA can never
out-grow the tax-free ISA (the conservation guard). Suite **287 → 291 green** (engine 123 → 127, app 164);
pint clean. **Caught:** Pint's `fully_qualified_strict_types` again added a docblock `use` — this time an
engine→engine `Dto\AssumptionSet` import (allowed; not App\/Illuminate\, so the isolation guard stays green).
**Next:** A5.3 — **CGT on GIA disposal** (realise the pro-rata gain when GIA is drawn; shared £3k AEA, 18/24%
by band, reusing `CgtParameters`; track basis through contributions/disposals), then A5.4 surface it on the
results page + finish the known-limits docs.
_2026-06-26 (REBUILD Phase C4 — PLSA Retirement Living Standards benchmark + engine-isolation guard)_
— Resumed via `/handover resume`; fixed a stale Status one-liner (it still said the C1 fast-follow was
uncommitted; it is committed at `c967426`), sanity-checked the suite green (**274 / 1077**), then built
**Phase C4**, the one remaining C1-list item. The PLSA **Retirement Living Standards** figures (Minimum /
Moderate / Comfortable × single/couple × outside-London/London) were **fetched live from
retirementlivingstandards.org.uk** rather than recalled, and placed in the engine as sourced reference data
(`src/Benchmark/RetirementLivingStandards` + `RetirementLivingStandardsResult`, framework-free, golden-master
tested) carrying `SOURCE`/`EDITION`/`VERIFIED_ON` per the no-magic-numbers rule (**⚠️ flagged for the Phase D
figure-verification pass** — read via automated fetch, not yet eyeballed against the published table).
`ResultPresenter::plsaBenchmark()` compares the household's spending on the **PLSA basis** (gotcha J: excludes
rent/mortgage — PLSA assumes outright ownership — but *includes* home running costs): comparable spend =
`ExpenseProfile::targetAnnualSpend()` (essential + discretionary, excluding *saved* self-investment) + owned-home
running costs, rent excluded by construction. Crucially it reuses the **same `ExpenseProfile` the forecast runs
on**, so the benchmark can't drift from the projection — reconciliation + composition + tier-boundary +
saved-SI-not-counted cases in `PlsaBenchmarkTest`. Rendered as a neutral results section (outside-London figures
with a London caveat; "reaches the Moderate standard … a general yardstick, not a recommendation"; passes the
`OutputPhrasing` partition lint). **Near-miss caught:** Pint's `fully_qualified_strict_types` fixer turned a
`{@see ResultPresenter::…}` docblock reference into a real `use App\Forecast\ResultPresenter;` import **inside
the engine** — a silent breach of the framework-free boundary. Removed it and added **`EngineIsolationTest`**
(engine suite) scanning `src/` for any `use App\…`/`use Illuminate\…`, so any future breach fails loudly. Suite
**274 → 287 green / 1144 assertions** (engine 115 → 123, app 159 → 164); pint clean. **Next:** Phase D — the
gov.uk ⚠️ figure-verification pass (incl. the PLSA figures), the deferred GIA/CGT modelling (A5), and go-live
polish (a11y CI, CSP, panel lockdown, perf, PDF, 2FA UI).
_2026-06-26 (REBUILD Phase C1 fast-follow — results display + income floor + importer lines + longevity lever)_
— Continued the same session after the doc reconciliation (`47436c3`). Built the C1 fast-follow in three
parts. **(A) Results 3-tier display + income-floor readout:** the engine exposes a new
**`YearResult::essentialSpend`** (real terms, the essential floor incl. rent/property running costs and the
survivor factor — the projector already computed `essentialNominal`, now surfaced) so the ladder and the
readout read one definition, not a re-derivation; golden-master tested (incl. rent lifting the floor).
`ResultPresenter::expenseBreakdown()` echoes the budget back in 3 tiers (per-line + spent/saved split,
**reconciling to the assembled spend** — display can't drift from the forecast), and `incomeFloor()` shows
essential spending vs **secure income** (DB + State Pension + annuity + **tax-free**, deliberately incl.
DLA-type streams to avoid the old completeness drop) at the last all-alive year, with coverage % and
surplus/gap. Both render on the results page before any Monte Carlo run. **(B) Importer line-population:**
`ImportResult` gained `expenseLines`; the three calibrated profiles emit line items (RetireForecast per-row,
PayAndExpenditures per-outgoing with labels preserved, CSP per-bucket — keeping the authoritative-TOTAL guard,
no re-expansion that would re-risk the double-count); the builder applies them as the source of truth; the
gotcha-A reconciliation guardrail extended to assert the line sums reconcile to the sheet-verified totals
(labels carried, contributions never become spend lines). **(C) Per-person longevity lever:** the assembler
maps a `longevityMode`(`peer`/`fixed_age`/`offset_years`)+`longevityValue` person field to the engine
`LongevityAdjustment`; the builder's people step has the control + validation (`required_if` when not peer);
an end-to-end completeness test proves the form lever reaches and shortens the forecast (fixed age 80 →
final year 2038). Suite **262→274 green / 1077 assertions** (engine 113→115, app →159); pint clean.
**Deferred to C4:** the **PLSA benchmark** (the one remaining C1-list item). **Next:** C4 income-floor/PLSA,
then D (trust + go-live).
_2026-06-26 (handover hygiene — no code)_ — Resumed via `/handover resume`; sanity-checked the suite green
(**262 / 1008**, engine 113). Found the C1-core handover note stale: the doc's repeated "NOT yet committed —
run `/checkpoint`" lines (Status, Branch status, the C1 session-log entry) were written inside commit
`2d553d4` itself, so they describe the commit that already landed. Reconciled all three to "committed at
`2d553d4`"; tree is clean. No code or test changes. **Next:** the C1 fast-follow — results 3-tier breakdown
display + income-floor readout, then importer line-population, then the per-person longevity builder lever.
_2026-06-26 (REBUILD Phase C1 core — 3-tier line-item budget)_ — Continued straight from the C2 checkpoint
(`5530896`). Built the **3-tier line-item budget** as the source of truth for spend. The engine needed **no
change** — `Account.ongoingContributions` + `applyContributions()` (from surplus) and `ExpenseProfile`
already existed — so this is entirely app-layer. **`builder_state.expenseLines`** (`{id, label, amount,
category ∈ essential|discretionary|self_investment, savedAsAsset}`) is now the source; the
`HouseholdAssembler` **derives** essential (Σ essential lines) and discretionary (Σ discretionary + *spent*
self-investment), and a **saved** self-investment line becomes a balance-zero **contributing ISA**
(`ongoingContributions`), counted once (one home per pound, gotcha O). Flat `expense.essential/discretionary`
are dropped when lines exist (no drifting total); legacy/imported scenarios **seed lines from their flat
totals** on load (gotcha G). Builder Spending step rewritten as a 3-tier editable list with live subtotals
(computed in the component — an `@php` arrow-function block in Blade broke compilation, so the maths moved to
`expenseTotals()`); validation requires ≥1 line; list rows carry stable ids. Updated the coupled tests: the
C2 override examples now target an expense line by id (`expenseLines.ess1.amount`), and the decimal-rejection
test targets a line. New tests: `ExpenseLineReconciliationTest` (totals == Σ lines exact-pence; a saved line
builds strictly more wealth than spending it — completeness) + two assembler cases. Suite **258→262 green /
1008 assertions** (engine 113 untouched, app →149); pint clean. **Deferred to the C1 fast-follow:** the
results **3-tier breakdown display**, the **income-floor readout**, **importer line-population** (importers
still emit flat totals, seeded into 2 generic lines), the **PLSA benchmark**, and the **per-person longevity**
builder field. Committed at **`2d553d4`**. **Next:** the C1 fast-follow, then C4.
_2026-06-26 (REBUILD Phase C2 — delta-child what-ifs + Compare)_ — Resumed via `/handover resume`,
sanity-checked the baseline green (239/946), oriented on the C2 plan (docs/PLAN.md "Sector-informed build
plan" item 1 clone/compare + gotcha N, DECISIONS scenario-model entry, DATA-MODEL "Planned shape changes"),
then built **Phase C2** end to end. Added the one merge function **`App\Forecast\BuilderStateDelta`**
(`diff`/`merge`/`orphans`/`structurallyDiffers`) — overrides are a flat map of id-aware dot-paths, round-trip
+ id-stability + orphan + structural-guard unit-tested. Gave **stable ids** to every list row
(pensions/accounts/income/one-offs/withdrawals; people keep p1/p2), backfilled on load so old rows get one.
Migration added **`parent_scenario_id`** (self-FK, cascade) + encrypted **`overrides`**; the `Scenario` model
gained `parent()`/`children()`/`isChild()`/`baseScenario()`/`effectiveBuilderState()` (base ⊕ overrides) +
`projectFrom()` (split out of `fillFromBuilderState`) + `orphanedOverrides()`, and `toHousehold()`/
`toHousingAction()` now derive from the effective state. Builder runs a **child mode** (`/scenarios/{base}/child`,
owner-scoped, full builder pre-filled from the base, save diffs to the delta with `step` stripped); a
**structural add/remove is refused** with a clear message (a delta can't fork the base); a **base edit
propagates** to children (refresh projection + drop their stale runs); deleting a base **cascades**. New
**Compare** page (`/scenarios/{base}/compare`, `ScenarioCompare`) lays base + children on their **deterministic**
projection in one accessible table, neutral framing, orphans surfaced. Dashboard nests children under their
base with Create-what-if / Compare links; results header + builder banner wired; banned-phrasing partition
lint stays green. New tests: `BuilderStateDeltaTest`, `ScenarioDeltaTest`, `ScenarioChildTest`,
`ScenarioCompareTest`. Suite **239→258 green / 994 assertions** (engine 113 untouched, app →145); pint clean.
**v1 boundary:** children override values only (the canonical levers); structural row changes go to the base.
**Not yet committed** — run `/checkpoint`. **Next:** Phase C1 — 3-tier line-item budget (totals = sum of
lines + reconciliation invariants; wire the *saved* self-investment to ongoing account contributions), then
the per-person longevity lever as a builder what-if field (the merge already supports it).
_2026-06-25 (REBUILD Phase B — storage inversion + edit-in-place)_ — Resumed via `/handover resume`;
sanity-checked the baseline green (235/929), oriented on the Phase B plan (docs/PLAN.md "Sector-informed
build plan" item 1, DATA-MODEL "Planned shape changes", DECISIONS scenario-model + rebuild entries), then
built **Phase B** end to end. Inverted storage so **`scenarios.builder_state`** (encrypted) is the **single
source of truth**: the engine `Household` + `HousingAction` DTOs are now **derived** from it via the
`HouseholdAssembler` (`Scenario::toHousehold()`/`toHousingAction()` — no reverse-mapper), and the clear
structural columns are a **projection** refreshed on save (`Scenario::fillFromBuilderState()`). Added
**edit-in-place** (`/scenarios/{scenario}/edit`, owner-scoped; `save()` is update-or-create) which
**invalidates stale runs/results** on edit (gotcha B, FK-cascade). **Dropped** the `households` +
`scenario_drafts` tables, the `Household`/`ScenarioDraft` models and the `HouseholdMapper`/`HousingActionMapper`
(now obsolete); the in-progress build folds into a **`draft`-status scenario** (one per user, promoted to
`ready` on save). Updated the forecaster, `LumpSumTaxShock`, GDPR export, the dashboard (Ready list + Edit
links + resume-draft banner) and results header (Edit link); guarded a draft's results route to redirect to
the builder. Reworked every scenario-building test onto a shared **`Tests\Support\ScenarioFixture`** (form-state
in, persisted scenario out) and added **`ScenarioEditTest`** (prefill / in-place update / run-invalidation /
owner-403 / draft→edit redirect); rewrote the persistence / draft / GDPR tests and trimmed `MappingRoundTripTest`
to the surviving AssumptionSet round-trip. Suite **235→239 green / 946 assertions** (engine 113, app →126);
pint clean. No data migration (rebuild authorised). **Next:** Phase C2 — delta-child what-ifs (`parent_scenario_id`
+ one merge fn + stable list-item IDs) + Compare.
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
