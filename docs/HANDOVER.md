# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue refining the calculation engine and the app around it. Read [docs/build/PLAN.md](build/PLAN.md) first (the full approved plan + scope). The detailed per-feature build record is archived in [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md).

**Stage:** active
**Status:** **Feature-complete for personal use.** The engine, the app, the whole post-v1 enhancement backlog, decision-support (Phases 0–6), the local assistant (3 phases), IHT and the care means-test are all built. What remains is Rob's **browser sign-off**, the **public-release blockers**, and **optional refinements**.
_Last updated: 2026-07-29 (adviser-parity plan: three OPEN correctness gaps found — fees, pension tax relief, ISA caps)_

## Goal & success criteria
Full plan: [docs/build/PLAN.md](build/PLAN.md); PRD: [PRD.md](PRD.md). Summary:
- **Goal:** let an older couple (one working, one retired) model whether to sell their home and either buy somewhere cheaper outright (invest the surplus) or sell and rent (invest all proceeds), plus the consequences of pension lump-sum withdrawals and whether their money lasts for life.
- **Headline outputs:** (1) the pension lump-sum tax shock (25% tax-free, marginal tax on the rest, the Month-1 emergency-tax overpayment + reclaim); (2) running-out-of-money / longevity risk via Monte Carlo.
- **Success for Rob's own use:** a working **local** site where he enters a real couple, runs buy-vs-rent, and reads a trustworthy forecast. **No hardcoded client data in the repo.** Possible free public release later.
- **Correctness bar:** the engine reproduces known HMRC worked examples to the penny (A, B, C in docs/build/PLAN.md). **Met** for the deterministic engine.

## Canonical data shape
Single source of truth: the engine's readonly DTOs under `packages/finance-engine/src/Dto/` (Eloquent models + Livewire forms map to/from these). Full field lists: [DATA-MODEL.md](DATA-MODEL.md) + docs/build/PLAN.md. Conventions:
- **Money = integer pence**, never a float (held by `Money`, GBP only). Rates = `Percent` (integer basis points). Dates = ISO `Y-m-d`. **Ages derive from DOB + a reference date, never stored.**
- **All reported wealth is NET of the mortgage** (2026-07-08): `YearResult::totalWealth` = liquid + pension + home equity (NNEG-floored); every surface (Compare / results / PDF / CSV / Monte Carlo / assistant) inherits from that one definition.
- **Storage inversion (Phase B):** a base scenario stores raw builder **form-state** (`builder_state`, one `encrypted:array`) as the single source of truth; the engine `Household` + `HousingAction` DTOs are **derived** (`Scenario::toHousehold()`/`toHousingAction()` via `HouseholdAssembler`, no reverse-mapper). A what-if **child** holds no `builder_state` — only `parent_scenario_id` + a sparse encrypted `overrides` delta (value overrides, added rows stored whole, removed rows a `REMOVED` sentinel); `effectiveBuilderState()` = base ⊕ overrides.

## Architecture / stack
- **Laravel 13.17** app at the repo root, **on local Postgres 18** (moved off SQLite 2026-07-09 — see Decisions). **Fortify** auth + **Filament 5** admin (which pulled **Livewire 4**). Front end = hand-rolled Livewire 4 full-page components (`app/Livewire/`) + **ApexCharts** (progressive enhancement — every figure is also text + an accessible `<table>` + CSV).
- **`packages/finance-engine`**: a framework-free Composer **path package** (`retireforecast/finance-engine`, symlinked). Zero Laravel deps, no I/O, no clock — this is the product; the app is a shell. Must never `use App\...`/`Illuminate\...` (guarded by `EngineIsolationTest`).
- Money is hand-rolled integer pence. PHPUnit 12. `phpspreadsheet` is an app-layer dependency (`.xlsx` import only). A CSP + hardening headers ship on the `web` group (`config/security.php`); Filament `/admin` is out of scope.

## Key files / structure
The engine is the product; the app is a shell around it. Browse the tree (it drifts if mirrored here).
- `packages/finance-engine/src/` — `Money/`, `TaxYear/`, `Tax/`, `Pension/`, `StatePension/`, `Property/`, `Benefits/`, `Iht/`, `Care/`, `Dto/`, `Assumptions/`, `Benchmark/`, `Mortality/`, `Forecast/` (`PathProjector` + `DeterministicForecaster` + `YearResult`), `MonteCarlo/`, `Housing/`, `Sweep/` (decision-support spine + levers).
- `app/` — `Forecast/` (`HouseholdAssembler`, `BuilderStateDelta`, `ScenarioForecaster`, `SimulationRunner`, `ResultPresenter`, `LumpSumTaxShock`), `DecisionSupport/` (`LeverThresholdService`, `ThresholdRunner`, `ThresholdPresenter`, `CombinationComparison`), `Assistant/` (`OllamaChatClient`, `ScenarioContext`, `FigureGrounding`, `AssistantTurnRunner`), `Import/`, `Livewire/`, `Compliance/`, `Models/`, `Http/`, `Jobs/`, `Filament/`.
- Root: `composer.json` (path repo), `phpunit.xml` (Engine testsuite), `config/security.php`, `database/migrations/`, `database/seeders/DemoScenarioSeeder.php`.
- House style is Pint: `vendor/bin/pint --dirty`.

## Decisions locked
Full log + rationale: [DECISIONS.md](DECISIONS.md). The load-bearing "don't relitigate" anchors:
- **Local-first, personal use, no hardcoded client data.** Rob enters the couple via the UI; any first-run sample must be obviously fictional.
- **App DB is Postgres 18** (moved off SQLite 2026-07-09 to fix the queued-Monte-Carlo reproducibility bug — SQLite could not handle the `database` queue driver's concurrent access). **Tests still run on in-memory SQLite** (phpunit.xml).
- **Regulatory posture: education/guidance-only** is the **public** stance. **Currently relaxed for personal use — `config('compliance.personal_use')` (default true) is the flagged "regulatory line"**, turning the walled-off advice `interpret` capability ON for everyone. The suite runs with it **true** (advice mode); `BannedPhrasingTest` is posture-aware (skips in advice mode, fully enforces when false). **Set `COMPLIANCE_PERSONAL_USE=false` before any public release**; `php artisan compliance:advice-audit` lists advice spots.
- **Engine is framework-free** in a path package; **money = integer pence**; savings + dividends in one combined income-tax pass; **tax figures versioned per tax year with source + verified-on** (frozen to April 2031).
- **All wealth reported NET of the mortgage** (2026-07-08).
- **UI = hand-rolled Livewire 4** (Filament admin-only); form input → engine DTOs via the unit-tested `HouseholdAssembler`.

## Current state
The full per-feature build record is in **[docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md)** (each item also has a dated DECISIONS entry + git history). High level:
- **Done — everything through the post-v1 backlog is built:** the HMRC-accurate deterministic engine (income tax + NI; the pension lump-sum suite incl. Month-1 emergency tax + reclaim; State Pension; SDLT/CGT/PRR; means-tested benefits; IHT; care) + Monte Carlo with stochastic joint-life mortality; the full app (encrypted DTO persistence, Fortify auth, GDPR, Filament, queued runs with progress/cancel, Livewire UI + charts, spreadsheet import, PDF export, 2FA, CSP); the rebuild (Phases A–D); the adviser-legibility presentation layer; **decision-support (Phases 0–6)** — lever thresholds, the "How far can we go?" panel, combination comparison, the survivor-cliff story + 5 levers, the 2-D trade-off map, the hash-gated assistant tie-in; the **local-model assistant** (grounded explainer + methodology doc-RAG + idea capture); **IHT wired into the forecast** (+ relationship status); the **care means-test tail**; the **age-varying spending smile**; the **equity-release lifetime mortgage**; the **BTL finance-cost tax reducer**. Nearly all of the post-2026-06-29 cluster **awaits Rob's browser sign-off** (What's next #1).
- **Done 2026-07-16 — no-magic-money purchase funding (DECISIONS 2026-07-16):** a buy above the sale proceeds is
  funded savings-first (cash → GIA → ISA, never pensions; the RIO borrows only the remainder); anything unfunded is
  charged as a year-0 cost so the plan **visibly fails** instead of being handed the home for free (the old
  floor-the-surplus behaviour is gone); a year-0 GIA draw pays real CGT. New **`CapitalReceipt`** builder input
  (step 3) models documented one-off money from outside the plan (family gift / outside-asset sale) — the ladder
  shows it as "One-off receipt". Awaits browser sign-off with the rest (What's next #1).
- **Done 2026-07-17 — "What you can afford" screen (DECISIONS 2026-07-17):** a plain-English `/scenarios/{base}/afford`
  surface for the elder couple who can't read the ladders/fans — one yes/no per plan (do the essentials last for
  life?), working plans first, failing ones collapsed with the year each runs short, a factual "bottom line" naming
  the strongest plan (+ a gated "lean towards" line). Verdict is the fast deterministic projection; stored Monte
  Carlo "how sure" shown beside it, with a one-click **Check how sure** that queues the full runs and hands off to
  Compare's progress UI. Pure presentation (no shape change). Linked from the dashboard, Compare and Results.
  Awaits browser sign-off with the rest (What's next #1). **Finding:** sell-and-rent at £2,000/mo fails at any
  realistic sale price (out 2037 on a £290k sale); affordable rent ceiling on a £290k sale ~£1,000/mo; sell-and-buy
  cheaper is the strongest plan. Five limit-test what-ifs added to the app (DB scenarios 33–37, not repo data).
- **Done 2026-07-18 — stochastic house-price growth in the Monte Carlo (DECISIONS 2026-07-18):** house growth was a
  deterministic straight line in the MC, so a home (the biggest, most variable slice of wealth) carried no risk and
  home-heavy plans looked artificially certain. Now the MC draws a per-year house shock (sourced 9%/11% real vol,
  low ~0.2 house–equity correlation). Opt-in via a nullable `AssumptionSet::houseGrowthVolatility`, so the
  deterministic projection, every existing set and every stored run are byte-identical (no DB migration). The
  low correlation is the point: it's why sell-and-invest diversifies concentrated housing risk. Salary growth in the
  MC stays deterministic (remaining refinement). Completeness-tested; no browser sign-off needed (engine + fan width).
- **Done 2026-07-18 — stochastic salary growth in the Monte Carlo (DECISIONS 2026-07-18):** the last deterministic
  straight line in the MC. A still-working household's future pay rises (and the savings/pension the surplus funds)
  now carry earnings risk. Same opt-in/null-safe contract as the house change: nullable `AssumptionSet::salaryGrowthVolatility`
  (+ a deliberately LOW `salaryEquityCorrelation` 0.1, weaker than housing's 0.2 — aggregate real wage growth is
  near-acyclical), so the deterministic projection, every existing set and every stored run stay byte-identical
  (no DB migration). Sourced 2.0%/2.5% real vol (SF Fed: real wage growth ~half GDP-growth volatility; ONS sanity
  check). Completeness-tested (`StochasticSalaryGrowthTest`); sanity magnitudes: a salary-driven working couple's
  p10–p90 terminal spread widens £104k → £115k at the shipped 2% (median ~unchanged). No browser sign-off needed
  (engine + fan width). **No MC-growth-determinism divergence remains.**
- **Done 2026-07-19 — the three hero time-series charts (C1/C2/C3, DECISIONS 2026-07-19):** a new "Money over
  time" section before the cashflow ladder draws the deterministic projection as three stacked-area pictures —
  **C1** income staircase (every source over time), **C2** wealth composition (pensions / savings / home
  equity, summing to net worth), **C3** costs (essential vs discretionary, the spending smile). Presenter +
  Blade only (`ResultPresenter::timeSeriesCharts`), no engine change — all data was already on `YearResult`.
  Built from the SAME `ForecastResult->years` the ladder reads, each with a `<details>` table twin reconciling
  to the ladder cell-for-cell (`TimeSeriesChartsTest`). Real-terms only; the **nominal-pounds toggle is
  deferred** (needs the engine's pre-deflation figures exposed, not a presenter re-inflation). Third build-order
  item of docs/build/PLAN-output-inflation-and-charts.md; **awaits browser sign-off** with the rest (visible UI).
- **Done 2026-07-19 — voluntary overpayments on a rolled-up lifetime mortgage (DECISIONS 2026-07-19):** the
  equity-release roll-up could only model "no payments"; now `Property::mortgageOverpaymentAnnual` (`?Money`,
  null = pure roll-up) subtracts a fixed-nominal overpayment from the balance each year after it compounds
  (NNEG-capped, floored at 0), so overpaying a lifetime mortgage slows the roll-up and preserves the estate.
  The cash to fund it rides on the "Mortgage" expense line, so the model shows the honest trade-off (lower
  balance vs the cashflow that pays for it). Builder-wired as an optional field; `LifetimeMortgageRollUpTest`
  (penny-exact + strictly-lower-than-pure-roll-up). Built to evaluate a real equity-release proposal.
- **Done 2026-07-18 — care in the deterministic path as an "if care is needed" stress (A2, DECISIONS 2026-07-18):**
  care was Monte-Carlo-only, so the plain-English Affordability verdict ("lasts for life? Yes") was computed on a
  care-free path — falsely reassuring for the least-numerate reader. Now `DeterministicPathDraws` accepts injected
  `CareEpisode`s (empty = byte-identical care-free path) and `DeterministicForecaster::forecastWithCareStress`
  places one adverse ~4-year nursing spell (£1,800/wk, `CareStressScenario`) on the last-surviving partner,
  means-tested + CPI+2%-escalated. The Affordability screen shows the care-stress verdict beside each plan's
  care-free verdict (and a bottom-line care caveat), so "for life" is never shown unqualified; the ordering stays
  the care-free expected path. Not averaging (per FCA / pro cashflow tools). `DeterministicCareStressTest` +
  `AffordabilityTest`. **Awaits browser sign-off** with the rest (What's next #1 — it's a visible UI change).
  **Still open:** a care-stress params editor, a probability-weighted "typical" option, the stress line on the
  main results ladder.
- **Done 2026-07-18 — care fees escalate above CPI (A1, DECISIONS 2026-07-18):** the engine drew one CPI series
  and modelled care as a flat-real cost, so care — the fastest-inflating major UK retirement category — rode flat
  CPI and understated the tool's headline late-life risk. New `AssumptionSet::careCostRealGrowth` (`?Percent`;
  null = flat-real, back-compat; shipped presets CPI+2% real, sourced/adverse-default, user-editable as the 7th
  economic assumption) compounds the sampled self-funder fee above CPI to the year the spell falls, mirroring
  `propertyCostsRealGrowth`. Null-safe: every stored care run reproduces byte-identically (`CareCostInflationTest`,
  `MappingRoundTripTest`). First slice of docs/build/PLAN-output-inflation-and-charts.md; **A2 (care in the deterministic
  path) is the next item.** No browser sign-off needed (engine + a panel row).
- **Done 2026-07-18 — sex-differentiated late-life care probability (DECISIONS 2026-07-18):** the stochastic care
  risk drew one flat 0.25 lifetime probability for everyone though `Person::sex` was already threaded to the
  `Simulator` before being dropped at the sampler. Now `CareAssumptions` carries male 0.20 / female 0.30 (~1.5:1,
  mean anchored to the Dilnot/PSSRU ~1 in 4), threaded into `CareCostSampler`'s per-person Bernoulli. No data-shape
  change; a threshold swap, not an extra draw, so seeded runs reproduce byte-identically and only fresh care-modelled
  runs shift. Age-conditioning of the rate + a sex split of the *duration* remain flagged. Completeness-tested
  (`CareCostSamplerTest`: the split reaches incidence). No browser sign-off needed (engine + fan tail).
- **Done 2026-07-18 — "Hide non-viable plans" toggle on Compare (DECISIONS 2026-07-18):** a checkbox that drops any
  plan whose usable-wealth line falls below £0 (runs out of money on the deterministic path) from the Compare table,
  burndown chart and Monte-Carlo cards, so the reader can focus on the plans that last. Shown only when there is a
  non-viable plan to hide; "Re-run all" still queues every plan, not just the visible ones; the burndown is re-keyed
  so the `wire:ignore`d chart re-renders the filtered series. Pure presentation, no shape change. Awaits browser
  sign-off with the rest (What's next #1).
- **Done 2026-07-18 — PDF sale-funding waterfall:** the downloadable/print report now renders the "If you sell"
  block (net-proceeds waterfall → sell-&-rent → sell-&-buy funding: savings drawn, mortgage, unfunded-gap failure),
  built from the SAME `ResultPresenter::saleExplainer` + engine decomposition the results page uses, so print cannot
  drift from screen. Closes the last PDF open item; guarded by a `ScenarioPdfTest` assertion. Awaits browser sign-off
  with the rest (What's next #1).
- **In progress:** nothing mid-edit. Live carry-over: the real **V2 couple's data** is captured privately in the gitignored `docs/SCENARIO-V2.local.md` (never commit) — the durable source to rebuild after a DB wipe; **the £118k stay-put mortgage is a DELIBERATE paydown design — read that doc before touching any V2 figure.** The base's
  "~£90k found from outside" convention can now be modelled honestly: **Rob re-enters it as a capital receipt**
  (year 2026, the real source as the label) — see the V2 doc's note.
- **Operational note (found 2026-07-10):** a `queue:work` daemon started **before** the 2026-07-09 Postgres migration keeps polling the old SQLite `jobs` table and processes **no** Postgres jobs — an in-app "Re-run all" hangs against it. **Restart every queue worker after the DB change** (`queue:work` caches its DB connection at boot). See How to pick up.
- **Known bugs:** none open. The queued-Monte-Carlo reproducibility bug is **RESOLVED** (Postgres) and **independently re-verified 2026-07-10** (Session log). Documented v1 scope limits (all flagged in code) live in [DATA-MODEL.md](DATA-MODEL.md) "Known divergences" — e.g. Scotland income tax throws; emergency tax models the over-deduction magnitude, not PAYE-table pennies; a repayment mortgage's balance is modelled static (set `mortgageRedemptionYear` + repay-from-capital to clear it). **House AND salary growth are now both stochastic in the Monte Carlo** (DECISIONS 2026-07-18) — no growth factor is a deterministic straight line any more.

## What's next (in order)
The whole post-v1 backlog is built. What remains:
1. **Rob's browser verification + sign-off** (testing deferred by Rob). The whole post-2026-06-29 cluster is built but unreviewed in the browser: re-run the browser a11y pass over the post-06-29 panels (`npm run a11y`; docs/spec/A11Y.md); check the mobile results nav; the 2FA QR scan; eyeball the new panels (annuitisation / stress-test / care-risk / withdrawal-sequencing / IHT / the spending-smile ladder / the decision-support finishers + the assistant + the new **"What you can afford"** screen and its **Check how sure** hand-off). **Thresholds, the trade-off map, assistant answers and the "Check how sure" MC runs all need the queue worker running.**
2. **Public-release blockers** (harmless while private, mandatory before any public launch; each flagged in code): set `config('compliance.personal_use')` false + confirm the guidance-only partition re-applies; swap the stress-test dataset off the CC BY-NC-SA JST source for an OGL/licensed one; tighten the CSP `script-src` to nonces; complete the a11y pass to a public bar.
3. **Optional refinements to built features** (all flagged v1 limits; pick by value) — remaining care flags (age-conditioning of the onset rate + a sex split of the care *duration*; the means-test v1 flags: Pension Credit not counted into the contribution, LA-vs-self-funder fee gap); CGT deemed-occupation absences; an annuitisation retirement-month override. (Done this cluster: both house and salary growth in the Monte Carlo are now stochastic, and the care *probability* is now sex-differentiated — DECISIONS 2026-07-18.) See DATA-MODEL "Known divergences" + docs/build/PLAN.md.
4. **CI / data hygiene (remainder).** The freshness guardrails run monthly in CI (the `data-freshness` workflow; takes effect on GitHub once pushed). Low-value hardening: a tamper-evident run hash, forecast caching.

**Specced-but-partly-built** (pick up when chosen): **output legibility + category (care) inflation + the
missing time-series charts** ([docs/build/PLAN-output-inflation-and-charts.md](build/PLAN-output-inflation-and-charts.md);
open questions resolved by the 2026-07-18 research pass, most-adverse defaults each user-editable, bar one
residual State-Pension judgement flag for Rob). **A1 (care escalates above CPI), A2 (care-stress in the
deterministic path) and C1+C2+C3 (the three hero time-series charts) are now built (above).** Still open from
slice #3: the **nominal-pounds toggle** (deferred — needs the engine's internal pre-deflation figures exposed,
not a presenter re-inflation, to avoid drift) and the wealth chart's terminal p25/p75. Next by the plan's build
order is **B1 — verdict-first, probability-led landing** (reuse the `/afford` screen, lead with the Monte-Carlo
probability + the word-bands), then the B2–B4 results-page restructure (tabs, tables into `<details>`, banners
demoted), then A3 fat tails / A4 State-Pension uprating. Build order in the plan's "Build order" section.
Other unbuilt specs: **adviser parity** ([docs/build/PLAN-adviser-parity.md](build/PLAN-adviser-parity.md),
DRAFT, scope questions all resolved — **contains three OPEN correctness gaps, now in DATA-MODEL "Known
divergences": no fee/charge model at all, no pension tax relief, no ISA subscription cap. These are
accuracy defects, not refinements** — build order starts A1 fees → A2 relief → B2 protection gap);
withdrawal-sequencing #5/#6 (docs/build/PLAN-withdrawal-sequencing.md, gated on two modelling
calls from Rob); multi-property (docs/build/PLAN-multi-property.md, DRAFT); assistant scenario-editing
(docs/build/PLAN-assistant-scenario-editing.md, approved scope, not built).

## Blockers / open questions
- [ ] **Rob's browser sign-off** on the built cluster (What's next #1) — the gating item.
- [ ] **The stale queue worker** — restart it (then in-app "Re-run all" works; see How to pick up).
- [ ] **Spreadsheet import** — the line-item expense-category data-model decision; re-verify IWT CSP vs a real export.
- [ ] **Demo couple's anonymised figures** — Rob supplies later, entered via the UI, not hardcoded.
- [ ] **Re-model the V2 base's ~£90k paydown as a capital receipt** (Rob, in the UI): builder step 3 → One-off
  capital receipts → year 2026, £90,000, label = the real source, owner = the receiving partner — then re-run.
- [ ] **Not blocking** — the Delta-research backlog (docs/research/RESEARCH-delta-2026-07-02.md); the under-spending case (docs/build/PLAN.md); the third-adult-contributing-to-upkeep scope item; a /methodology enhancement + an adviser/Pension-Wise output pack; WCAG 2.2 AA + mobile to a public bar.

## How to pick up
Run from the **project root** (the test runner shells out to a relative phpunit path). **Run php / artisan / composer / npm via PowerShell** (PHP 8.4 = Laravel Herd; not on the Git Bash PATH). Bash is fine for git / grep / file ops. See CLAUDE.md.
```powershell
Set-Location "C:\Dev\RetireForecast"
php artisan test                     # full suite — must be all green (red = stop and fix)
php artisan test --testsuite=Engine  # engine only
vendor/bin/pint --dirty              # house style on changed files
npm run build                        # build assets (public/build is gitignored)
```
- **App DB is Postgres 18** (`.env` `DB_CONNECTION=pgsql`, db `retireforecast`, `127.0.0.1:5432`, `postgres`/`postgres`; the old sqlite line is commented for revert). Fresh machine: create the db, then `php artisan migrate --seed`. Tests run on in-memory SQLite regardless.
- **Herd serves the app at `https://retireforecast.test`** (no `php artisan serve` needed). Run `npm run build` after asset changes. Using HMR (`npm run dev`) → set `SECURITY_HEADERS_ENABLED=false` (the CSP omits the Vite dev origin).
- **Queue worker (needed for full runs, thresholds, the trade-off map and assistant answers).** Start it **fresh from the project root** with JIT (≈2× faster, byte-identical):
  ```powershell
  php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 artisan queue:work
  ```
  **A worker started before the 2026-07-09 Postgres move polls the old SQLite jobs table and never processes Postgres jobs — kill and restart it.** The synchronous preview (1 path) needs no worker.
- **Admin `/admin`** gated on `is_admin` (`php artisan user:make-admin {email}`). **Assistant** inert unless `ASSISTANT_ENABLED=true` (needs Ollama with `qwen3:14b` + the worker; build the doc index once with `php artisan assistant:index-docs`, re-run after editing a curated methodology doc). Register at `/register` + accept the `/welcome` disclaimer; 2FA at `/account/security`. Demo preset: `php artisan db:seed --class=Database\Seeders\DemoScenarioSeeder`.
- **Machine config (not in repo):** a per-site Herd nginx conf raises this site's gateway timeout to 300s (`~/.config/herd/config/valet/Nginx/retireforecast.test.conf`).
- **Share with family (private, as-is) — Tailscale Serve, not a public deploy (DECISIONS 2026-07-12 + 07-16).**
  Set `APP_EXTERNAL_URL` (in `.env`) to this machine's `https://<name>.ts.net`, run `php artisan serve --port=8000`
  plus a queue worker, then `tailscale serve --bg 8000` (tailnet-only, auto HTTPS). The URL pin is
  host-conditional (2026-07-16): family traffic under the `*.ts.net` host gets pinned https URLs while local
  browsing at `retireforecast.test` keeps its own — both work at once, no env toggling needed.
  The `tailscale serve` config survives reboot; `artisan serve`/`queue:work` do not — relaunch them. Stop
  sharing: `tailscale serve --https=443 off`. Family log in with Rob's credentials (scenarios are per-user).

## Sibling docs
| Doc | Purpose |
|-----|---------|
| [docs/build/PLAN.md](build/PLAN.md) | The full approved plan. Source of truth for scope, data model, tax rules, Monte Carlo design, phasing. |
| [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) | The detailed per-feature build record ("Done" bullets) + older session log, trimmed out of this doc 2026-07-10. |
| [DATA-MODEL.md](DATA-MODEL.md) | Canonical data shape; materialised-vs-planned; "Known divergences" (the full v1-limit list). |
| [DECISIONS.md](DECISIONS.md) | Append-only decision log with rationale. |
| [PRD.md](PRD.md) | Goal, success criteria, scope, non-goals, open questions. |
| [CLAUDE.md](../CLAUDE.md) | Root orient tripwire + build/test conventions + doc-hygiene rules. |
| docs/SCENARIO-V2.local.md | **GITIGNORED / PRIVATE:** the real couple's data + core scenario, to re-model after a DB wipe. **Read before touching any V2 figure.** |
| [docs/spec/METHODOLOGY.md](spec/METHODOLOGY.md) | User-facing engine-computation methodology + "what we don't model" (also the `/methodology` page + the assistant corpus). |
| [docs/build/PLAN-output-inflation-and-charts.md](build/PLAN-output-inflation-and-charts.md) | **DRAFT** spec from the 2026-07-18 adversarial review: output legibility, per-category (care) inflation + fat tails, and the six missing time-series charts. Reasoning + research links per decision. |
| docs/build/PLAN-*.md, docs/research/RESEARCH-*.md | Per-feature specs / build records + research (decision-support, IHT, forced sale, sequencing, multi-property, assistant, stress-test, competitive gap, delta). |

## Branch status
On `master`. GitHub remote `origin` → github.com/RobertLCraig/RetireForecast. **Pushing to `master` is gated — needs Rob's explicit go-ahead.** Otherwise commit directly to `master` (personal local-first project, no PR flow). **Re-check `git status` / `git log` before any commit or push.** The pre-rebuild prototype is tagged `prototype-v1` (a8f1f68). Use `git log` for history (not restated here — it drifts).

## Session log
_Newest first. Only the recent live window; older sessions are folded into [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) + git log + DECISIONS._

_2026-07-29 (adviser-parity plan — three open correctness gaps found; no code changed)_ —
Rob asked what could be learned from a Damien Talks Money Q&A video (28 Jul 2026), then widened it to "what
else does a financial adviser provide that we should model, to obviate needing one". The video itself was
unreadable (YouTube serves a JS shell; no transcript), so Rob pasted the page metadata and **the chapter list
was used as a topic checklist only** — every claim was then verified against gov.uk / primary sources, never
against the video. Mapped ~30 retirement-relevant chapters against the code. **Most already covered** (MPAA,
emergency tax, care, sequencing risk, fiscal drag, CGT, IHT, DB-as-bonds; the triple-lock earnings leg is a
*decided* adverse divergence, not an oversight). **Three genuine gaps found — all now in DATA-MODEL "Known
divergences" as OPEN:** (1) **no investment-cost model at all** — returns are gross of platform/fund charges,
the largest silent optimism in the model and a bias in the reassuring direction; this was an open *confirm*
in the June competitive scan (Cluster E) and is now confirmed absent; (2) **no pension contribution tax
relief** — the code's own docblock flags it; (3) **no ISA subscription cap** — and the bias is largest for the
highest-surplus (sell-and-invest) plans, so it is not neutral across the plans being compared. The
adviser-services sweep found the remaining reasons to hire one are **coverage and cadence, not
sophistication** — RF already beats the adviser sector on modelling and leads on means-tested benefits.
Wrote **docs/build/PLAN-adviser-parity.md** (Part A engine correctness, Part B adviser-service parity, with
three explicit non-goals: fund/product selection, DB-transfer advice, attitude-to-risk psychometrics —
capacity for loss is kept because it is objective and already computable). **Rob resolved all four scope
questions**, which reordered the build: A3 ISA drops to generality (both partners are 65+ before the April-2027
cash-ISA cut, so it misses this household); A2 implements `net_pay` first (their actual scheme — full marginal
relief, no NI saving); A4 sacrifice drops to a "would it be worth asking your employer?" what-if; **B2
protection gap promoted to #3** (death-in-service confirmed in force — and it *ceases at retirement*, a real
cliff-edge RF is well placed to surface). Personal detail (DOB, scheme, cover) deliberately kept **out** of the
tracked plan per [[pii-leaks-into-tracked-files]] — it lives in the gitignored SCENARIO-V2 doc. **No code
changed**; no DECISIONS entry per the established convention (a DRAFT plan earns its entry when built).

_2026-07-19 (doc-structure migration to the canonical layout)_ —
Ran the Project-Doc-Standard migration (triggered by `/handover save`). The four anchors moved from the repo
root into `docs/`; `METHODOLOGY`/`ASSUMPTIONS`/`MORTALITY`/`A11Y` → `docs/spec/`; `PLAN` + `PLAN-*` →
`docs/build/`; `RESEARCH-*` → `docs/research/` (all `git mv`, history preserved). `HANDOVER-ARCHIVE.md` and the
gitignored `*.local.md` / `*.xlsx` stayed at `docs/` root. Rewrote every relative cross-reference (doc-to-doc
and links to source files, at both new depths) plus the code/config that names a doc path — root `CLAUDE.md`,
`HandoverHygieneTest`, `MethodologyController` + `MethodologyPageTest`, and `config/assistant.php`
`methodology_docs`. Verified with a link checker over all relative links in every tracked `*.md` (0 broken) and
the full suite green. Committed on its own (`docs(structure): …`), separate from the charts work. **Note:** stale
path *prose* in the historical docs (DECISIONS/PLAN/RESEARCH bodies) was left as-is — the clickable links all
resolve; only this living HANDOVER's prose was updated (rewriting append-only logs is worse than the minor
staleness). The SessionStart orient hook already discovers anchors at `docs/` root, so it is unaffected.

_2026-07-19 (C1/C2/C3: the three hero time-series charts)_ —
Resumed and picked up build-order #3 of docs/build/PLAN-output-inflation-and-charts.md (A1/A2 done). The review found
nearly every chart a user wants was already computed per year on `YearResult` and thrown at a table — only the
Monte-Carlo wealth fan was drawn. Added a "Money over time" section before the cashflow ladder with three
stacked-area charts: **C1** income staircase (every income source over time), **C2** wealth composition
(pensions / savings & investments / home equity, summing to net worth), **C3** costs (essential vs
discretionary — the spending smile). Presenter + Blade only (`ResultPresenter::timeSeriesCharts` +
`partials/time-series-charts.blade.php`), no engine change. Built from the SAME `ForecastResult->years` the
ladder reads, so a chart can't drift from the table; each ships its `<details>` table twin reconciling to the
ladder cell-for-cell (`TimeSeriesChartsTest`: income cols → total, wealth legs → net worth, ess+disc → spend,
all cross-checked vs the ladder; non-negative stacked pounds; the >8-source fold keeps every source in the
table). **Caught in the test:** the income "total" is the sum of the *sources* (the stack height — includes
tax-free cash, savings drawn, one-off receipts), NOT `grossIncome` (taxable only), which reconciled to a
different, smaller figure. Categorical colour from the validated dataviz reference palette (CVD-checked on the
app's white surface); C1 caps at the 8 palette hues, folding any extra income sources into a neutral "Other"
band on the chart only (the table keeps them all — completeness). Milestone verticals overlaid on all three.
**Real-terms only; the nominal toggle is deferred** (needs the engine's pre-deflation figures exposed to avoid
a presenter re-inflation drifting from the projector). Full suite green, pint clean, assets build. **Awaits
browser sign-off** (visible UI). Next by build order: B1 verdict-first landing.

_2026-07-18 (A2: care in the deterministic path as an "if care is needed" stress)_ —
Continued the output/inflation plan (build-order #2). Care was Monte-Carlo-only, so the deterministic
Affordability verdict read a care-free path — "lasts for life? Yes" computed without the household's biggest
late-life expense, falsely reassuring for exactly the least-numerate reader the screen is built for. Per the
plan's resolved design (and the FCA / pro-cashflow-tool convention), modelled care as a **stress shown beside a
labelled care-free base, never expected-value-averaged** (averaging a right-skewed tail understates the person
in the tail). Engine: `DeterministicPathDraws` gained optional injected `CareEpisode`s (empty = byte-identical
care-free path — the care-free central estimate is untouched); new `CareStressScenario` (one 4-year nursing
spell @ £1,800/wk on the last-surviving partner — the adverse means-test position, and discriminating where a
both-partners worst case would sink every plan) + `DeterministicForecaster::forecastWithCareStress`; reuses the
A1-escalated, means-tested projector care leg. App: `ScenarioForecaster::deterministicCareStressVariants` mirrors
`deterministicVariants` on the same variant inputs; `AffordabilityAssessment` adds a care-stress verdict per card
+ a bottom-line care caveat (tier/ordering stay the care-free expected path — a strong plan still ranks strong);
the Affordability view renders a 🏥 care line on every card. Guarded (`DeterministicCareStressTest`: reaches the
result, tips a marginal single-person household, care-free carries no care; `AffordabilityTest`: every card
carries the verdict + the caveat). Design calls (single spell / last survivor / £1,800×4yr) are the adverse
defaults per [[adverse-default-user-editable]], flagged user-editable (a params UI is a later refinement).
**Awaits browser sign-off** (visible UI). Full suite green, pint clean. Next: C1–C3 hero charts.

_2026-07-18 (A1: care fees escalate above CPI — first slice of the output/inflation plan)_ —
Resumed and picked up docs/build/PLAN-output-inflation-and-charts.md build-order #1 (the highest-value item by the
accuracy-first rule now the plan's open questions are resolved). The engine drew one CPI series and modelled care
as a flat-real cost, so care — the fastest-inflating major UK retirement category (self-funder fees ran ~10%/yr to
Dec-2025) — rode flat CPI and understated the tool's headline late-life risk. Copied the proven, null-safe
`propertyCostsRealGrowth` mechanism exactly: new `AssumptionSet::careCostRealGrowth` (`?Percent`), threaded through
the `PathDraws` interface (all three drivers) and escalated in the `PathProjector` care leg by `(1+g)^yearIndex`
before the means test. Shipped default **CPI + 2% real** across all presets (sourced most-adverse standing value —
care is NLW-pinned staff cost, PSSRU/OBR escalate care unit costs on earnings ~2% real; the ~10%/yr run-rate is an
NLW+NI spike, not standing), exposed as the **7th user-editable economic assumption** (`careCostGrowth`) per the
adverse-default-user-editable rule. Null-safe throughout: null keeps care flat-real, the mapper hydrates a pre-A1
snapshot to null, so every stored care run reproduces byte-identically; new runs carry 2%. Guarded by
`CareCostInflationTest` (compounds by the expected factor; null/zero byte-identical) + `MappingRoundTripTest`
(reaches storage; pre-A1 → null). Fixed a stale METHODOLOGY caveat in passing (care probability is split by sex,
contradicting an adjacent "no split by sex or age" line). Full suite green (913 + 1 advice-mode skip; +2 tests),
pint clean. **A2 (care in the deterministic path) is the next build-order item** — care is still MC-only, so the
Affordability verdict still reads a care-free path.

_2026-07-18 (adversarial output/inflation/charts review → a DRAFT plan)_ —
Rob asked for an adversarial review of the project + docs (wrong assumptions; data gathered-but-unused; how
to make the information-heavy output more legible + how other forecasters do it; what charts to add; how
inflation changes costings). Ran two code-grounded mapping passes (the Blade/ApexCharts output layer; the
engine's `YearResult`/`SimulationResult` fields vs what any view reads) plus web research for every figure.
**Findings:** (1) correctness — the engine draws one CPI and models everything else as a real spread, so
**care fees ride flat CPI** (understating the tool's headline risk; real self-funder fees ran ~10%/yr to
Dec-2025), care is **absent from every deterministic surface** the Affordability screen reads, MC returns are
**Gaussian** (left tail optimistic ~10–17pts per the literature), and the triple lock **drops the earnings
leg**; (2) the results page is ~1,170 lines / 18 sections front-loading up to four banners before the first
number, every chart doubled by an inline table; (3) **only one time-series chart exists** though
`incomeBySource` (11 sources/yr), the spend split, wealth legs, tax and mortgage balance are all on
`YearResult` — six more charts are a presenter/Blade job, not an engine change. Wrote
**docs/build/PLAN-output-inflation-and-charts.md** (Part A correctness / Part B legibility / Part C charts; each
decision carries reasoning + a research link; six open questions flagged for Rob; build order + files-to-touch
map). Noted the concurrent session's `21e0efe feat(care): sex-differentiated care probability` had just landed
(distinct from this plan's care *inflation* items); re-checked git before editing per [[concurrent-session-split]].
**Then resolved all six of the plan's open modelling questions** via a three-way parallel research pass (care;
return distribution; triple lock + probability wording) — Rob delegated the calls ("not my field", saved as
[[adverse-default-user-editable]]): research the industry figure, default to the **most adverse** where several
are defensible, expose each as a user-editable UI control. Sourced defaults landed in the plan (care inflation
CPI+2%; care-stress variant ON not expected-value-averaged, adverse fees/duration from LaingBuisson/PSSRU;
Student-t d.o.f. 3 + negative skew + a stagflation inflation↔return coupling; State Pension CPI-only — the one
place adverse departs from current law, flagged; probability word-bands reserving "on track" for ≥80%). **No
code changed** — plan + handover/sibling-doc index only.

_2026-07-18 (sex-differentiated late-life care probability in the Monte Carlo)_ —
Picked up What's next #3. Chose the care sex-split over CGT deemed-occupation absences (near-moot for the V2 couple's
continuously occupied main home — PRR already relieves the whole gain) and the annuitisation month override (narrow).
The care sampler drew one flat 0.25 for everyone though `Person::sex` was already carried to the `Simulator` and
dropped at the sampler boundary — a collected-but-under-consumed use of sex, and a real understatement of a woman's
care tail (a headline "does the money last" driver). Researched sourced figures (women's lifetime care-home use
consistently ~1.5:1 vs men — NHS HSE 2021 28/24 ADL, US NEJM 38/21 nursing-home, HHS ASPE 55/38 paid LTSS); set male
0.20 / female 0.30 calibrated to preserve the Dilnot/PSSRU ~1 in 4 mean at an even split (so a mixed couple's
aggregate risk is essentially unchanged, no unexplained drift). Threaded `sex` into `CareCostSampler`; it's a
threshold swap, not an extra draw, so seeded runs reproduce byte-identically and care being opt-in means no
default/non-care run changes. Guarded (`CareCostSamplerTest`: asymmetry + the split reaches incidence over 2,000
same-seed draws). Fixed two adjacent stale honesty lines in docs/spec/METHODOLOGY.md while there: the care "no sex/age
split" caveat, and the "house/salary growth have no volatility" MC caveat (both were made stochastic earlier today).
Full suite green (911 + 1 expected advice-mode skip), pint clean.

_2026-07-18 ("hide non-viable plans" toggle on Compare)_ —
Resumed to find a complete, green, uncommitted feature in the tree (the handover said "nothing mid-edit"): a
Compare-screen checkbox that hides plans whose usable-wealth line falls below £0 (deterministic depletion) from the
table, burndown and MC cards. Confirmed it coherent + green (`ScenarioCompareTest` 16/16), Rob confirmed it was this
work to land, so committed it: pint clean, added the DECISIONS entry + handover Done bullet. "Non-viable" is defined
off the deterministic `depletionCalendarYear` (same as the "Money lasts: No" column), not an MC probability; pure
presentation, no shape change; "Re-run all" still queues every plan; the burndown is `wire:key`ed so the ignored
chart re-inits with the filtered series. Toggle shows only when a non-viable plan exists.

_2026-07-18 (stochastic salary growth in the Monte Carlo — the last deterministic growth line)_ —
Picked up from What's next #3 (Rob chose it over the public-release blockers and sign-off prep). With house growth
made stochastic earlier the same day, salary growth was the only remaining deterministic straight line in the MC, so
a still-working couple's accumulation looked artificially certain. Mirrored the house pattern exactly: `AssumptionSet`
gains nullable `salaryGrowthVolatility` + `salaryEquityCorrelation` (0.1, deliberately LOWER than housing's 0.2 —
researched: aggregate real wage growth is near-acyclical, ~0.51× GDP-growth volatility per SF Fed WP 2011-23, ~2% in
the UK ONS record); `ReturnModel` draws a per-year salary shock correlated to the equity shock, **drawn last** so a
null-salary set consumes no extra draw and every stored run reproduces byte-identically; `SampledPathDraws` reads the
sampled path; mapper round-trips the pair with null back-compat; the assumptions panel gains a salary-volatility
"show-your-working" row. **Corrected an over-claim mid-build:** "drawn last" does NOT keep later years' house/asset
streams identical when salary vol is on (each extra draw advances the shared RNG for subsequent years) — the real,
narrower guarantee is that a *null*-salary set draws nothing extra; fixed the docblock and the test to assert exactly
that. Sourced figures + judgement note in docs/spec/ASSUMPTIONS.md; DECISIONS 2026-07-18 supersedes the house entry's
"salary stays deterministic". Full suite green (908), pint clean; sanity magnitudes verified via a scratchpad script
(not committed): shipped-2% widens the p10–p90 spread £104k → £115k, median unchanged.

_2026-07-18 (stochastic house-price growth in the Monte Carlo)_ —
Highest-value accuracy refinement from What's next #3: house growth was a deterministic straight line in the MC, so
the home carried no risk and stay-put/buy plans looked artificially certain vs sell-and-rent (whose invested proceeds
were already stochastic) — wrong for a tool whose whole point is a housing decision under uncertainty. Researched
sourced figures (real house vol ~9%, low ~0.2 house–equity correlation, per JST "Rate of Return on Everything"
NBER w24112 — housing far less volatile than equities, low equity–housing covariance / diversification gains).
Implemented: `AssumptionSet` gains `houseGrowthVolatility` (`?Percent`) + `houseEquityCorrelation` (float);
`ReturnModel` draws a per-year house shock correlated to the equity shock (single scalar correlation, not a 4th
matrix row — keeps the asset-class matrix contract); `SampledPathDraws` reads the sampled house path; presets +
mapper + assumptions panel updated. **Key design for safety: nullable vol = opt-in** — a null-vol set draws no house
shock, so the deterministic projection, all existing sets/tests and every stored run are byte-identical (no DB
migration, MC reproducibility preserved). Completeness-tested (`StochasticHouseGrowthTest`): a £500k-home couple's
terminal spread widens p10–p90 £169k → £759k (median ~unchanged), collapses to the mean at zero vol, reproduces under
a seed. Salary growth in the MC left deterministic (narrower, lower value). Full suite green, pint clean. Sanity-run
magnitudes verified via a scratchpad script (not committed).

_2026-07-18 (PDF sale-funding waterfall — last PDF gap closed)_ —
The buy-funding waterfall (net proceeds → savings → mortgage → unfunded gap) rendered on results/Compare/assistant
but the PDF summary omitted the sale explainer entirely. Fixed by mirroring the screen, not re-deriving: added the
same `ResultPresenter::saleExplainer(...)` call to `ScenarioPdfController::data()` (fed by the scenario's own
`housingComparison`/`assumptions`/`allocation`, deterministic, null when no sale is configured) and an "If you sell"
section to `pdf/partials/report.blade.php`, placed just before the cashflow ladder to match the on-screen money-flow
order. Used only the inline `@php(...)` form (block/inline mixing is the known Blade raw-block gotcha); added an `h3`
rule to the PDF stylesheet. Guarded with a `ScenarioPdfTest` assertion (the rich fixture sells & buys). Full suite
green, pint clean; the `%PDF` download test confirms DomPDF renders the new markup. No shape change, no DECISIONS
entry (mechanical parity with the screen under the existing displayed-figure-provenance rule).

_2026-07-17 ("What you can afford" screen + affordability limit-tests)_ —
Rob: the current tool is good for him but hard to communicate to the elder couple — "they just want a this-is-what-
I-can/should-do". Built a plain-English `/scenarios/{base}/afford` screen (`Affordability` Livewire + pure
`AffordabilityAssessment` presenter + view; linked from dashboard/Compare/Results): base + every what-if reduced to
one yes/no (do the essentials last for life?), working plans first, failing ones collapsed with the year each runs
short, a factual bottom line naming the strongest plan (directive "lean towards" gated behind `interpret`). Verdict
is the fast deterministic projection (covers new what-ifs with no stored run); stored Monte-Carlo "how sure" shown
beside it — an important honesty gap here (several plans "work on the expected path" yet are only ~55–62% in MC; the
base stay-put is 28%). One-click **Check how sure** queues the full runs via `SimulationRunner` and hands off to
Compare's progress UI. Pure presentation, no shape change; suite green, pint clean. **Caught two bugs in build:**
`deterministic()` ignores the housing variant (must use `deterministicVariants()[$variant]` for a sell/rent plan —
my first probe mis-modelled the sells), and an inverted sort comparator briefly put the weakest plan as the bottom
line. **Answered Rob's limit test:** sell-and-rent at £2,000/mo fails at any realistic sale price (out 2037 on a
£290k sale, 2040 even on £350k); affordable rent ceiling on a £290k sale ~£1,000/mo (tight); sell-and-buy cheaper
£165k is the strongest (survives the £290k price with ~£82k left; £135k / 87% MC on the £350k base). Added 5
limit-test what-ifs to the app (DB scenarios 33–37, not committed); noted them in the gitignored SCENARIO-V2 doc.

_2026-07-16 (no magic money: purchase-funding waterfall + documented capital receipts)_ —
Rob: scenarios that buy a home "seem to magic up the money required — show it accurately, without money
appearing without a documented income source (e.g. sale residual, income from work/kids)". Root cause: a
cash-only buy above the proceeds floored the surplus at £0 and still granted the home at full price (phantom
equity, flagged in the UI but silently modelled); savings were never drawn; no input existed for money arriving
from outside the plan. Built (all per DECISIONS 2026-07-16, Rob's three design calls made explicitly): the
savings-first funding waterfall with the loud unfunded-gap year-0 failure; real year-0 CGT on the GIA slice a
purchase draw sells (one shared AEA, exact-pence tests via the engine's own primitives); the `CapitalReceipt`
input end-to-end (engine → projector → builder step 3 → ladder → assistant). Fixed in passing: `withHousing`
dropped `relationshipStatus` (cohabiting buy/rent variants silently reverted to married IHT). The Compare
burndown now shows an unfunded mansion plunging £-millions negative — verified as the honest net-position line
(usable minus cumulative unmet), not a bug. PDF surfaces untouched (uncommitted work from a concurrent session
in those files — stage selectively). Suite green throughout; pint clean.

_2026-07-12 (private family sharing via Tailscale Serve — Hostinger rejected)_ —
Rob wanted family to view the current real scenarios **as-is** (not a public launch). Rejected the offered
Hostinger box: its SSH is shared hosting (port 65002), which is MySQL-only (no Postgres), cannot run a
persistent `queue:work` daemon or the Ollama assistant, and would force a DB migration + re-entering the
encrypted data on a third party + crossing the public-compliance line — all cost, no fit. Chose **Tailscale
Serve**: the app stays exactly as-is on this machine, reachable only inside the private tailnet, data never
leaves the box. Wired it up and verified end-to-end (the tailnet URL returns 200 with a valid auto cert):
serve Host-agnostically on `php artisan serve --port=8000` (Herd/Valet routes by Host and will not match the
`*.ts.net` name) + `tailscale serve --bg 8000`; new `APP_EXTERNAL_URL` pins absolute URLs/redirects to the
https origin so logins do not bounce to an unreachable host; `bootstrap/app.php` trusts the loopback proxy
for the forwarded scheme (not Host); `APP_DEBUG=false` while shared. Family log in with Rob's credentials
(scenarios are per-user; no read-only share built). See DECISIONS 2026-07-12 + How to pick up.

_2026-07-10 (queued-Monte-Carlo reproducibility independently re-verified; a stale worker found)_ —
Re-ran and re-verified the run-to-run-variance trust concern on Postgres. Built an independent test: an
in-process reference for all 18 scenarios (3 variants, 10k paths — the proven-deterministic path), then
dispatched the whole family **twice** through the real `queue:work` daemon (batch 1 = single worker;
batch 2 = **two concurrent workers**, deliberately heavier `jobs`-table contention than the original bug),
comparing each stored result to the reference on both the success probabilities and a full-payload md5.
**108 queued variant-results across 36 runs, every one byte-identical (max gap 0.0000 points, 0 hash
mismatches).** Confirms the SQLite→Postgres fix — in-app "Re-run all" is trustworthy. **Found a stale
`queue:work` daemon (started 2026-07-07, before the migration)** still polling the old SQLite `jobs`
table — it processed none of my Postgres jobs; a fresh worker drained them. `queue:work` caches its DB
connection at boot, so restart every worker after the DB change (DECISIONS 2026-07-10). Residue: the 36
verification runs (ids 437–472, seed 424242) are now each family scenario's "latest completed run" and
polluted `result_snapshots`; deleting them was safety-blocked (Rob's pre-existing data), so Rob's next
in-app "Re-run all" (after restarting the worker) supersedes them at canonical seeds. Verification scripts
stayed in the session scratchpad, not the repo. **No code changed.**

_2026-07-09 (queued-Monte-Carlo reproducibility bug RESOLVED via Postgres; BTL finance-cost reducer)_ —
A family figure swung more than 10k-path sampling noise allows. A long investigation proved the engine is
deterministic (same seed → identical across 6 processes, JIT and no-JIT) and every non-queue path
reproduces exactly; only runs through the `queue:work` daemon were intermittently wrong (up to 14pts).
Root cause: SQLite could not handle the `database` queue driver's concurrent access. Rob's fix: moved the
app DB to local Postgres 18 (data copied across, encrypted V2 family intact); the family re-run through the
identical queued path now reproduces exactly. Also this session: the let plan (#17) given the April-2020
BTL finance-cost tax reducer with rental at £1,800/mo (depletion 2030 → 2035, still fails). See DECISIONS
2026-07-09.

_Older sessions folded into [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md)._
