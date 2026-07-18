# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue refining the calculation engine and the app around it. Read [docs/PLAN.md](docs/PLAN.md) first (the full approved plan + scope). The detailed per-feature build record is archived in [docs/HANDOVER-ARCHIVE.md](docs/HANDOVER-ARCHIVE.md).

**Stage:** active
**Status:** **Feature-complete for personal use.** The engine, the app, the whole post-v1 enhancement backlog, decision-support (Phases 0–6), the local assistant (3 phases), IHT and the care means-test are all built. What remains is Rob's **browser sign-off**, the **public-release blockers**, and **optional refinements**.
_Last updated: 2026-07-18 (PDF now renders the sale-funding waterfall — the last "Done" open item closed)_

## Goal & success criteria
Full plan: [docs/PLAN.md](docs/PLAN.md); PRD: [PRD.md](PRD.md). Summary:
- **Goal:** let an older couple (one working, one retired) model whether to sell their home and either buy somewhere cheaper outright (invest the surplus) or sell and rent (invest all proceeds), plus the consequences of pension lump-sum withdrawals and whether their money lasts for life.
- **Headline outputs:** (1) the pension lump-sum tax shock (25% tax-free, marginal tax on the rest, the Month-1 emergency-tax overpayment + reclaim); (2) running-out-of-money / longevity risk via Monte Carlo.
- **Success for Rob's own use:** a working **local** site where he enters a real couple, runs buy-vs-rent, and reads a trustworthy forecast. **No hardcoded client data in the repo.** Possible free public release later.
- **Correctness bar:** the engine reproduces known HMRC worked examples to the penny (A, B, C in docs/PLAN.md). **Met** for the deterministic engine.

## Canonical data shape
Single source of truth: the engine's readonly DTOs under `packages/finance-engine/src/Dto/` (Eloquent models + Livewire forms map to/from these). Full field lists: [DATA-MODEL.md](DATA-MODEL.md) + docs/PLAN.md. Conventions:
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
The full per-feature build record is in **[docs/HANDOVER-ARCHIVE.md](docs/HANDOVER-ARCHIVE.md)** (each item also has a dated DECISIONS entry + git history). High level:
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
- **Done 2026-07-18 — PDF sale-funding waterfall:** the downloadable/print report now renders the "If you sell"
  block (net-proceeds waterfall → sell-&-rent → sell-&-buy funding: savings drawn, mortgage, unfunded-gap failure),
  built from the SAME `ResultPresenter::saleExplainer` + engine decomposition the results page uses, so print cannot
  drift from screen. Closes the last PDF open item; guarded by a `ScenarioPdfTest` assertion. Awaits browser sign-off
  with the rest (What's next #1).
- **In progress:** nothing mid-edit. Live carry-over: the real **V2 couple's data** is captured privately in the gitignored `docs/SCENARIO-V2.local.md` (never commit) — the durable source to rebuild after a DB wipe; **the £118k stay-put mortgage is a DELIBERATE paydown design — read that doc before touching any V2 figure.** The base's
  "~£90k found from outside" convention can now be modelled honestly: **Rob re-enters it as a capital receipt**
  (year 2026, the real source as the label) — see the V2 doc's note.
- **Operational note (found 2026-07-10):** a `queue:work` daemon started **before** the 2026-07-09 Postgres migration keeps polling the old SQLite `jobs` table and processes **no** Postgres jobs — an in-app "Re-run all" hangs against it. **Restart every queue worker after the DB change** (`queue:work` caches its DB connection at boot). See How to pick up.
- **Known bugs:** none open. The queued-Monte-Carlo reproducibility bug is **RESOLVED** (Postgres) and **independently re-verified 2026-07-10** (Session log). Documented v1 scope limits (all flagged in code) live in [DATA-MODEL.md](DATA-MODEL.md) "Known divergences" — e.g. Scotland income tax throws; emergency tax models the over-deduction magnitude, not PAYE-table pennies; a repayment mortgage's balance is modelled static (set `mortgageRedemptionYear` + repay-from-capital to clear it); house/salary growth deterministic inside the Monte Carlo.

## What's next (in order)
The whole post-v1 backlog is built. What remains:
1. **Rob's browser verification + sign-off** (testing deferred by Rob). The whole post-2026-06-29 cluster is built but unreviewed in the browser: re-run the browser a11y pass over the post-06-29 panels (`npm run a11y`; docs/A11Y.md); check the mobile results nav; the 2FA QR scan; eyeball the new panels (annuitisation / stress-test / care-risk / withdrawal-sequencing / IHT / the spending-smile ladder / the decision-support finishers + the assistant + the new **"What you can afford"** screen and its **Check how sure** hand-off). **Thresholds, the trade-off map, assistant answers and the "Check how sure" MC runs all need the queue worker running.**
2. **Public-release blockers** (harmless while private, mandatory before any public launch; each flagged in code): set `config('compliance.personal_use')` false + confirm the guidance-only partition re-applies; swap the stress-test dataset off the CC BY-NC-SA JST source for an OGL/licensed one; tighten the CSP `script-src` to nonces; complete the a11y pass to a public bar.
3. **Optional refinements to built features** (all flagged v1 limits; pick by value) — care sex/age-split + the means-test v1 flags; CGT deemed-occupation absences; stochastic house/salary growth in the Monte Carlo; an annuitisation retirement-month override. See DATA-MODEL "Known divergences" + docs/PLAN.md.
4. **CI / data hygiene (remainder).** The freshness guardrails run monthly in CI (the `data-freshness` workflow; takes effect on GitHub once pushed). Low-value hardening: a tamper-evident run hash, forecast caching.

**Specced-but-unbuilt** (pick up when chosen): withdrawal-sequencing #5/#6 (docs/PLAN-withdrawal-sequencing.md, gated on two modelling calls from Rob); multi-property (docs/PLAN-multi-property.md, DRAFT); assistant scenario-editing (docs/PLAN-assistant-scenario-editing.md, approved scope, not built).

## Blockers / open questions
- [ ] **Rob's browser sign-off** on the built cluster (What's next #1) — the gating item.
- [ ] **The stale queue worker** — restart it (then in-app "Re-run all" works; see How to pick up).
- [ ] **Spreadsheet import** — the line-item expense-category data-model decision; re-verify IWT CSP vs a real export.
- [ ] **Demo couple's anonymised figures** — Rob supplies later, entered via the UI, not hardcoded.
- [ ] **Re-model the V2 base's ~£90k paydown as a capital receipt** (Rob, in the UI): builder step 3 → One-off
  capital receipts → year 2026, £90,000, label = the real source, owner = the receiving partner — then re-run.
- [ ] **Not blocking** — the Delta-research backlog (docs/RESEARCH-delta-2026-07-02.md); the under-spending case (docs/PLAN.md); the third-adult-contributing-to-upkeep scope item; a /methodology enhancement + an adviser/Pension-Wise output pack; WCAG 2.2 AA + mobile to a public bar.

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
| [docs/PLAN.md](docs/PLAN.md) | The full approved plan. Source of truth for scope, data model, tax rules, Monte Carlo design, phasing. |
| [docs/HANDOVER-ARCHIVE.md](docs/HANDOVER-ARCHIVE.md) | The detailed per-feature build record ("Done" bullets) + older session log, trimmed out of this doc 2026-07-10. |
| [DATA-MODEL.md](DATA-MODEL.md) | Canonical data shape; materialised-vs-planned; "Known divergences" (the full v1-limit list). |
| [DECISIONS.md](DECISIONS.md) | Append-only decision log with rationale. |
| [PRD.md](PRD.md) | Goal, success criteria, scope, non-goals, open questions. |
| [CLAUDE.md](CLAUDE.md) | Root orient tripwire + build/test conventions + doc-hygiene rules. |
| docs/SCENARIO-V2.local.md | **GITIGNORED / PRIVATE:** the real couple's data + core scenario, to re-model after a DB wipe. **Read before touching any V2 figure.** |
| [docs/METHODOLOGY.md](docs/METHODOLOGY.md) | User-facing engine-computation methodology + "what we don't model" (also the `/methodology` page + the assistant corpus). |
| docs/PLAN-*.md, docs/RESEARCH-*.md | Per-feature specs / build records + research (decision-support, IHT, forced sale, sequencing, multi-property, assistant, stress-test, competitive gap, delta). |

## Branch status
On `master`. GitHub remote `origin` → github.com/RobertLCraig/RetireForecast. **Pushing to `master` is gated — needs Rob's explicit go-ahead.** Otherwise commit directly to `master` (personal local-first project, no PR flow). **Re-check `git status` / `git log` before any commit or push.** The pre-rebuild prototype is tagged `prototype-v1` (a8f1f68). Use `git log` for history (not restated here — it drifts).

## Session log
_Newest first. Only the recent live window; older sessions are folded into [docs/HANDOVER-ARCHIVE.md](docs/HANDOVER-ARCHIVE.md) + git log + DECISIONS._

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

_Older sessions folded into [docs/HANDOVER-ARCHIVE.md](docs/HANDOVER-ARCHIVE.md)._
