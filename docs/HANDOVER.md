# HANDOVER: RetireForecast — UK retirement / downsizing forecast tool

> A local-first UK financial-forecasting decision-support tool. A fresh agent picks this up to continue refining the calculation engine and the app around it. **This doc holds what is true; [docs/board/](board/) holds what is moving.** Read [docs/build/PLAN.md](build/PLAN.md) for the full approved plan and scope.

**Stage:** active
**Category:** site
**Status:** **Feature-complete for personal use, and now carrying a large reviewed defect backlog.** The engine, the app, the post-v1 enhancement backlog, decision-support (Phases 0 to 6), the local assistant, IHT and the care means-test are all built. A five-discipline expert review on 2026-08-19 found defects across all of them, several of which change which plan the comparison ranks first. What remains is that backlog, Rob's **browser sign-off**, and the **public-release blockers**.
_Last updated: 2026-09-05. The exceptions a fresh session needs, newest first:_

- **An overridden home is no longer a certainty, and one home is now modelled over double the index
  volatility.** Card 0029: `PathDraws::propertyGrowthReal($yearIndex, $meanReal)` replaces
  `houseGrowthReal()`. A property growth override used to REPLACE the sampled house path, so a park
  home or a hand-priced flat carried no house risk at all; it now sets the MEAN the year's shock is
  re-centred on. The shock is also widened by `AssumptionSet::SINGLE_PROPERTY_VOLATILITY_MULTIPLE`
  (2.0, so 18% real on the default set) because the sampled figure is an INDEX one, disclosed as an
  `assumed_figure` note and editable as the `propertyVolatility` assumption. `ReturnModel` is
  untouched, so the RNG stream is byte-identical and only what the home does with each draw changed.
  **The deterministic projection is unchanged; every Monte Carlo band, success probability and
  capacity-for-loss reading on a plan that keeps or buys a home is now WIDER.** `ENGINE_VERSION` is
  `finance-engine/single-property-house-risk` and the **stored-scenario re-run is owed**, including
  the park-home scenarios, whose range card 0029 asked for and which a worktree session should not
  write to the live database. The 2.0 is the 2026-08-19 property reviewer's judgement, not a
  published series; that is the second sourcing gap in
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§13) and is raised as card 0086.
- **A service charge with no rate entered now rises at CPI + 3%, and a major-works bill can die with
  the home.** Card 0028: `ExpenseProfile::propertyCostsRealGrowth()` supplies
  `DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS` when the rate is null and the `while_owning_home` bucket
  is positive (an explicit rate, including zero, still wins), disclosed as an `assumed_figure` note
  reading that constant. A one-off cost row can carry `condition: while_owning_home`, so a Section 20
  demand is dropped on the buy/rent variants and after a mid-projection sale. **Every stay-put plan
  carrying a service charge and no explicit rate now spends more**, so its stored wealth, depletion
  year and success odds are too favourable; `ENGINE_VERSION` is
  `finance-engine/property-costs-default-growth` and the **stored-scenario re-run is owed** (built in
  a worktree, so it could not touch the live database). **The 3% is the 2026-08-19 property
  reviewer's judgement, not a published series**, and the only figure in
  [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) (§12) without a primary citation, because an
  unattended session has no web access. Raised as card 0085.
- **A mortgage payment is now fixed nominal and not survivor-scaled, whatever shape the mortgage is.**
  Card 0024: the "Mortgage" expense line comes out of the CPI-and-survivor multiply always and is
  added back after it, so interest-only / RIO / buy-to-let / a serviced lifetime mortgage get the
  treatment the amortisation schedule already had. The Section 24 finance cost is nominal interest
  too. **Every borrowing plan's spend was overstated before this**, so its wealth, depletion year and
  success odds were too pessimistic against selling; the ranked comparison moves.
  `ScenarioForecaster::ENGINE_VERSION` is `finance-engine/nominal-mortgage-payment` and stored runs
  are not comparable across the bump. **The stored-scenario re-run is still owed** (it was built in a
  worktree, so it could not touch the live database). Adjacent fault raised as card 0082: the
  Section 24 credit is still granted after the mortgage is redeemed or the home sold.
- **A full-spend measure is now recurring-spend only, and every stored figure moved with it.**
  Card 0025: a one-off capital lump the plan cannot fund (an unfunded purchase, a mortgage redeemed
  from capital) is charged the year's shortfall FIRST and judged on its own, so it no longer fails
  the all-or-nothing full-spend test on every path. **Any Monte Carlo full-spend probability stored
  before this is not comparable with one stored after** — re-run before reading one beside the
  other. `ForecastResult::fullSpendYearsMetFraction()` and `SimulationResult::$successProbability`
  `FullSpendMostYears` (95%+ of years) are the honest companions; the latter is `null` on an older
  stored run and must show as a dash, never 0%. Card 0023 confirmed it against the stored set and
  needed no code: #51's completion gap is **£1.37**, not £46,412, it is the only stored scenario
  carrying an unfunded lump at all, and its full-spend probability now sits within a point of its
  essentials one. Its stale SCENARIO-V2.local.md warning is rewritten; only a clean audit exit
  remains open there.
- **`scenarios:audit` cannot be used as a gate until every stored scenario is re-run.** It exits 1
  on 120 lines, all of them "run N carries no integrity stamp (it predates the column)", with no
  other problem class anywhere. Applying the pending `add_hashes_to_simulation_runs_table` migration
  does **not** clear them — the audit's integrity check asks whether `integrity_hash === null`, and
  every already-completed run stays null. Card 0025's `ENGINE_VERSION` bump means those runs are stale on figures too, so the
  re-run settles both at once.
- **The assistant can now propose a what-if, behind a flag that is off.** Card 0020 built Phase 1 of
  [PLAN-assistant-scenario-editing.md](build/PLAN-assistant-scenario-editing.md): a "Change plan" tab
  that turns what the reader says into a reviewable diff, written only on confirm and only as an
  ordinary delta-child. It is invisible until `ASSISTANT_CAN_EDIT_SCENARIOS=true`. This narrows the
  "the model never builds" doctrine; DECISIONS 2026-08-29 records the guardrails. Not yet exercised
  against a real `qwen3:14b`, and not yet seen in a browser.
- **An override cannot create a map the base does not have.** `BuilderStateDelta::merge` drops
  `assumptionOverrides.inflation` when the base overrides no assumption at all, so a what-if that is
  the first in its family to touch an assumption does not get it. It is not silent (the path reports
  as an orphan, which the results, compare, PDF and `scenarios:audit` surfaces all show), but the
  what-if does not model what was asked. Found during card 0020, which works around it by offering
  only assumptions the base already carries; the underlying fix is uncarded.
- **Card 0019 settled the multi-property plan and wrote no code.**
  [PLAN-multi-property.md](build/PLAN-multi-property.md) is out of DRAFT with its five scope questions
  answered, and card 0019 now carries the Phase-1 acceptance behind `needs: 0029, 0030`. One question
  is Rob's and sits on that card: whether a second property exists to model at all. DECISIONS 2026-08-29.
- **Card 0018's migration has not been applied to the app database.** Run `php artisan migrate` after
  this branch merges, or a completed run carries no integrity stamp and no cache key.
- **Cards 0011 to 0016** are summarised in [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md). What still
  stands from them: the stress-test data licence and the human WCAG 2.2 pass are open, and the
  comparison should not be read off until the ranking-movers at the head of the queue are fixed.

## Goal & success criteria
Full plan: [docs/build/PLAN.md](build/PLAN.md); PRD: [PRD.md](PRD.md). Summary:
- **Goal:** let an older couple (one working, one retired) model whether to sell their home and either buy somewhere cheaper outright (invest the surplus) or sell and rent (invest all proceeds), plus the consequences of pension lump-sum withdrawals and whether their money lasts for life.
- **Headline outputs:** (1) the pension lump-sum tax shock (25% tax-free, marginal tax on the rest, the Month-1 emergency-tax overpayment and reclaim); (2) running-out-of-money / longevity risk via Monte Carlo.
- **Success for Rob's own use:** a working **local** site where he enters a real couple, runs buy-vs-rent, and reads a trustworthy forecast. **No hardcoded client data in the repo.** Possible free public release later.
- **Correctness bar:** the engine reproduces known HMRC worked examples to the penny (A, B, C in docs/build/PLAN.md). **Met** for the deterministic engine.

## Canonical data shape
Single source of truth: the engine's readonly DTOs under `packages/finance-engine/src/Dto/` (Eloquent models and Livewire forms map to and from these). Full field lists: [DATA-MODEL.md](DATA-MODEL.md) + docs/build/PLAN.md. Conventions:
- **Money = integer pence**, never a float (held by `Money`, GBP only). Rates = `Percent` (integer basis points). Dates = ISO `Y-m-d`. **Ages derive from DOB + a reference date, never stored.**
- **All reported wealth is NET of the mortgage** (2026-07-08): `YearResult::totalWealth` = liquid + pension + home equity (NNEG-floored); every surface (Compare / results / PDF / CSV / Monte Carlo / assistant) inherits from that one definition.
- **Storage inversion (Phase B):** a base scenario stores raw builder **form-state** (`builder_state`, one `encrypted:array`) as the single source of truth; the engine `Household` + `HousingAction` DTOs are **derived** (`Scenario::toHousehold()` / `toHousingAction()` via `HouseholdAssembler`, no reverse-mapper). A what-if **child** holds no `builder_state`, only `parent_scenario_id` + a sparse encrypted `overrides` delta (value overrides, added rows stored whole, removed rows a `REMOVED` sentinel); `effectiveBuilderState()` = base overlaid with overrides.
- **One rebuild site per DTO.** `Household` is only ever copied through its private `copy()` behind `withPersons()` / `withPensions()` / `withExpenseProfile()` / `withCapitalReceipts()`, because seven sweep levers used to rebuild it positionally and a field added to the DTO but forgotten in a lever was silently dropped from every swept forecast. Guarded by `HouseholdWitherTest`, which enumerates the DTO's own properties by reflection.

## Architecture / stack
- **Laravel 13.17** app at the repo root, on local **Postgres 18** (moved off SQLite 2026-07-09, see Decisions). **Fortify** auth + **Filament 5** admin (which pulled **Livewire 4**). Front end is hand-rolled Livewire 4 full-page components (`app/Livewire/`) + **ApexCharts** (progressive enhancement: every figure is also text, an accessible `<table>` and CSV).
- **`packages/finance-engine`**: a framework-free Composer **path package** (`retireforecast/finance-engine`, symlinked). Zero Laravel deps, no I/O, no clock. This is the product; the app is a shell. Must never `use App\...` or `Illuminate\...` (guarded by `EngineIsolationTest`).
- Money is hand-rolled integer pence. PHPUnit 12. `phpspreadsheet` is an app-layer dependency (`.xlsx` import only). A CSP and hardening headers ship on the `web` group (`config/security.php`); Filament `/admin` is out of scope. `script-src` is **nonce-based** (minted per request, handed to the Vite helper, which Livewire reads back); `'unsafe-eval'` is the one remaining relaxation, because Livewire 4's bundled Alpine evaluates through the Function constructor.

## Key files / structure
A map, not an inventory. Browse the tree for the rest; per-file rationale lives in each file's docblock.
- `packages/finance-engine/src/` — `Money/`, `TaxYear/`, `Tax/`, `Pension/`, `StatePension/`, `Property/`, `Benefits/`, `Iht/`, `Care/`, `Protection/`, `Dto/`, `Assumptions/`, `Benchmark/`, `Mortality/`, `Forecast/` (`PathProjector` + `DeterministicForecaster` + `YearResult`), `MonteCarlo/`, `Housing/`, `Sweep/` (decision-support spine and levers).
- `app/` — `Forecast/`, `DecisionSupport/`, `Assistant/`, `Import/`, `Livewire/`, `Compliance/`, `Models/`, `Http/`, `Jobs/`, `Filament/`, `Export/`.
- The seams a fresh session must not re-derive:
  - **`PathProjector`** is the hot loop and the cross-cutting contention point. Read its year-order before editing it.
  - **`ResultPresenter`** is the one place a figure becomes a screen string; the PDF calls the same methods, so print cannot drift from screen.
  - **`LadderContext`** resolves which housing strategy is on display. `deterministic()` ignores the stored variant, which has caused the same bug three times: read a sell plan through the variant path, never the raw household.
  - **`ScenarioForecaster::assumptions()`** is the only place assumption overrides are applied.
  - The **results page is tabbed** (`?tab=`, resolved server-side in `ScenarioResults::mount()`, defaulting to the verdict). `ScenarioResults::TABS` owns the tab set and the view's `$sections` array owns section → tab → nav label; a section outside the active tab is rendered and hidden, never skipped, so its table and CSV twin stay in the page. Add a section and its `$sections` entry together.
  - **`config/advice.php`** holds the advice fee deliberately outside `AssumptionSet`: the forecast never charges it, it only prices a comparison.
- House style is Pint: `vendor/bin/pint --dirty`.

## Decisions locked
Full log and rationale: [DECISIONS.md](DECISIONS.md). The load-bearing "do not relitigate" anchors:
- **Local-first, personal use, no hardcoded client data.** Rob enters the couple via the UI; any first-run sample must be obviously fictional.
- **App DB is Postgres 18** (moved off SQLite 2026-07-09 to fix the queued-Monte-Carlo reproducibility bug: SQLite could not handle the `database` queue driver's concurrent access). **Tests still run on in-memory SQLite** (phpunit.xml).
- **Regulatory posture: education/guidance-only** is the **public** stance, **currently relaxed for personal use.** `config('compliance.personal_use')` (default true) is the flagged "regulatory line", turning the walled-off advice `interpret` capability on for everyone. The suite runs with it **true**; `BannedPhrasingTest` is posture-aware (skips in advice mode, fully enforces when false). **Set `COMPLIANCE_PERSONAL_USE=false` before any public release**; `php artisan compliance:advice-audit` lists advice spots.
- **Engine is framework-free** in a path package; **money = integer pence**; savings and dividends in one combined income-tax pass; **tax figures versioned per tax year with source and verified-on** (frozen to April 2031).
- **All wealth reported NET of the mortgage** (2026-07-08).
- **UI = hand-rolled Livewire 4** (Filament admin-only); form input maps to engine DTOs via the unit-tested `HouseholdAssembler`.
- **No invisible figures.** Any engine-side default reaching a projection is disclosed with its value and why it applies, reading the constant that owns it rather than restating it. `php artisan scenarios:audit` sweeps every stored scenario for correctness and correct disclosure, and exits non-zero so it can gate a release.

## Current state
- **Done:** the tool is feature-complete for personal use. An HMRC-accurate deterministic engine (income tax and NI, the pension lump-sum suite including Month-1 emergency tax and reclaim, State Pension, SDLT/CGT/PRR, means-tested benefits, IHT, care) sits behind a Monte Carlo with stochastic joint-life mortality and stochastic house-price, salary and care-cost paths. Around it: encrypted DTO persistence, Fortify auth, GDPR, Filament, queued runs with progress and cancel, a Livewire UI with charts, spreadsheet import, a complete PDF export with server-drawn charts (an export of more than eight forecasts is queued and built one at a time, delivered as a zip), 2FA and a CSP. Decision support covers lever thresholds, a combination comparison, the survivor cliff, capacity for loss (how far wealth can fall before the essential floor breaks), a 2-D trade-off map and a local-model assistant. Housing covers stay-put, buy-cheaper, rent, park homes (a bought home that depreciates), let-to-let, equity release and real amortising repayment mortgages pinned to a lender illustration. The adviser-parity sweep is now closed bar A4 salary sacrifice, B3 the estate checklist, B4 the annual review and B5 capacity for loss (card 0011): investment charges, net-pay contribution relief, the protection gap (employer death-in-service cover and the life cover that would restore a survivor's plan), the cost-of-advice comparison and the ISA subscription cap shipped on 2026-07-31, and the annual-allowance / MPAA contribution cap, the £3,600 non-earner relief route and bed-and-ISA on 2026-08-22.
- **In progress:** nothing mid-edit.
- **Known bugs / broken:** a reviewed defect backlog, carded as **0024 to 0065** in [docs/board/todo/](board/todo/); do not restate it here, read the lane. The shape of it: five independent senior reviewers (software engineering, financial planning, welfare benefits, property, estate planning) read the docs, the engine and the stored scenarios on 2026-08-19. Findings four or more reviewers reached separately are the load-bearing ones. **Several change which plan the comparison ranks first**, so the ranked chart and card 0022 should not be read off until the head of the queue is cleared. The full report, with the private figures the cards deliberately omit, is the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.
Documented v1 scope limits remain flagged in code and listed in [DATA-MODEL.md](DATA-MODEL.md) "Known divergences" (for example Scotland income tax throws rather than guessing; emergency tax models the over-deduction magnitude, not PAYE-table pennies).
- **Data hygiene is currently breached** (card 0043): private detail about the couple is in eleven tracked files, including this doc's own Blockers section historically. Cards written from 2026-08-19 carry no private figures and point at the gitignored captures instead. Keep it that way.
- **Live carry-over:** the real couple's data is captured privately in the gitignored `docs/SCENARIO-V2.local.md`, which is the durable source to rebuild from after a DB wipe. **Read it before touching any V2 figure.** What each broker has actually offered, with dates and sources, is in the gitignored `docs/HOUSING-OFFERS.local.md`; the stored mortgage scenarios are priced off it.

## What's next (in order)
**The queue is [docs/board/todo/](board/todo/), one card per file.** Do not restate it here. At the head:

**The first two are the ranking-movers.** They change which plan wins, so they come before any
feature work and before card 0022 is answered. Re-run every stored scenario and `scenarios:audit`
after each.

1. **0034 a year-0 purchase cannot see a capital receipt arriving the same year**, so a plan borrows for life beside money it already has.
2. **0035 and 0036 dead and mis-timed income inputs**: the defined-benefit escalation dropdown is never read, and the retirement year pays a part-year salary with a full year of pension and no National Insurance.

Then the pre-review queue resumes at the head of [docs/board/todo/](board/todo/).

## Blockers / open questions
**The full set is [docs/board/human-review/](board/human-review/), each card carrying its own ask or
its own options and a recommendation.** Thirteen cards are waiting on Rob. Do not restate them here.
What a fresh session needs to know:

- **0022 keep the flat or sell, is now explicitly on hold.** The 2026-08-19 review added direction: three reviewers, separately, said the two leading options are inside noise of each other while several unpriced items are each larger than the gap. Four things must land first, all carded. Do not push for an answer.
- **Six new cards are real-world actions, not modelling questions** (0066 to 0071): powers of attorney before any deed is signed, the State Pension figure that decides the whole benefits picture, a lease valuation, a formal property valuation plus the lender's position, the managing agent's accounts, and a quote for the borrowing the winning plan assumes. Several engine cards are blocked on their answers.
- **0001 browser sign-off on the built cluster** still gates acceptance: everything built since 2026-06-29 is proven by tests and numeric audit but has never been looked at in a browser.
- The rest (0004, 0005, 0006, 0008, 0021) are unchanged and carry their own recommendations.

## How to pick up
Run from the **project root** (the test runner shells out to a relative phpunit path). **Run php / artisan / composer / npm via PowerShell** (PHP 8.4 is Laravel Herd, not on the Git Bash PATH). Bash is fine for git, grep and file ops. See CLAUDE.md.
```powershell
Set-Location "C:\Dev\RetireForecast"
php artisan test                     # full suite, must be all green (red = stop and fix)
php artisan test --testsuite=Engine  # engine only
php artisan scenarios:audit          # every stored scenario: figures AND their disclosure
vendor/bin/pint --dirty              # house style on changed files
npm run build                        # build assets (public/build is gitignored)
```
- **App DB is Postgres 18** (`.env` `DB_CONNECTION=pgsql`, db `retireforecast`, `127.0.0.1:5432`; the old sqlite line is commented for revert). Fresh machine: create the db, then `php artisan migrate --seed`. Tests run on in-memory SQLite regardless.
- **Herd serves the app at `https://retireforecast.test`** (no `php artisan serve` needed). Run `npm run build` after asset changes. Using HMR (`npm run dev`) needs `SECURITY_HEADERS_ENABLED=false`, because the CSP omits the Vite dev origin.
- **Queue worker** (needed for full runs, thresholds, the trade-off map and assistant answers). Start it **fresh from the project root** with JIT (about twice as fast, byte-identical):
  ```powershell
  php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 artisan queue:work
  ```
  **A worker started before the 2026-07-09 Postgres move polls the old SQLite jobs table and never processes Postgres jobs, so kill and restart it.** The synchronous preview (1 path) needs no worker.
- **Admin `/admin`** gated on `is_admin` (`php artisan user:make-admin {email}`). **Assistant** is inert unless `ASSISTANT_ENABLED=true` (needs Ollama with `qwen3:14b` plus the worker; build the doc index once with `php artisan assistant:index-docs`, re-run after editing a curated methodology doc). Register at `/register` and accept the `/welcome` disclaimer; 2FA at `/account/security`. Demo preset: `php artisan db:seed --class=Database\Seeders\DemoScenarioSeeder`.
- **Machine config (not in repo):** a per-site Herd nginx conf raises this site's gateway timeout to 300s (`~/.config/herd/config/valet/Nginx/retireforecast.test.conf`).
- **Share with family** (private, as-is): Tailscale Serve, not a public deploy (DECISIONS 2026-07-12 and 07-16). Set `APP_EXTERNAL_URL` in `.env` to this machine's `https://<name>.ts.net`, run `php artisan serve --port=8000` plus a queue worker, then `tailscale serve --bg 8000` (tailnet-only, auto HTTPS). The URL pin is host-conditional, so family traffic under the `*.ts.net` host gets pinned https URLs while local browsing at `retireforecast.test` keeps its own; both work at once with no env toggling. The `tailscale serve` config survives reboot; `artisan serve` and `queue:work` do not. Stop sharing with `tailscale serve --https=443 off`. Family log in with Rob's credentials (scenarios are per-user).

## Suggested skills / next tools
- **`/handover resume`** — the pick-up path. Reads this doc, then the board, then starts the head card.
- **`/handover save`** — wrapping up. Moves cards first, edits this doc second.
- **`/checkpoint`** — update the doc set and commit without a full handover pass.
- **`php artisan scenarios:audit`** — run before and after any engine change, and before looking at a screen. Eight checks over every stored scenario, non-zero exit so it can gate a release.
- **`php artisan compliance:advice-audit`** — the standing inventory of advice-mode spots, needed before any public release. `--strict` exits non-zero, which is the pre-release gate form.
- **`npm run a11y`** (public pages, also in CI), **`npm run a11y:auth`** (the signed-in pages, including `/account/security` in all three of its two-factor states, which it enrols into and back out of) and **`npm run a11y:focus`** (WCAG 2.2 2.4.11: nothing focusable hidden under the assistant overlay). The last two need the app served and a demo scenario. See [docs/spec/A11Y.md](spec/A11Y.md), whose table records where each unautomatable 2.2 criterion stands.
- **ProgressBoard** at `C:\Dev\ProgressBoard` — renders this board (and every other project's) ordered by what is waiting on Rob, and moves cards by `git mv` plus a commit. `php artisan serve --port=8737`, or Herd at `progressboard.test`.
- **`/code-review`** — for a working diff. `/security-review` before any public release.

## Sibling docs
| Doc | Purpose |
|-----|---------|
| [docs/board/](board/) | **What is moving.** One card per task; the folder it sits in is its state. |
| [docs/build/PLAN.md](build/PLAN.md) | The full approved plan. Source of truth for scope, data model, tax rules, Monte Carlo design, phasing. |
| [DATA-MODEL.md](DATA-MODEL.md) | Canonical data shape; materialised-vs-planned; "Known divergences" (the full v1-limit list). |
| [DECISIONS.md](DECISIONS.md) | Append-only decision log with rationale. |
| [PRD.md](PRD.md) | Goal, success criteria, scope, non-goals, open questions. |
| [CLAUDE.md](../CLAUDE.md) | Root orient tripwire, build/test conventions, doc-hygiene rules. |
| [docs/spec/METHODOLOGY.md](spec/METHODOLOGY.md) | User-facing methodology and "what we don't model" (also the `/methodology` page and the assistant corpus). |
| [docs/spec/ASSUMPTIONS.md](spec/ASSUMPTIONS.md) | Every economic assumption with its source and verified-on date. |
| [docs/HANDOVER-ARCHIVE.md](HANDOVER-ARCHIVE.md) | The per-feature build record, out of the load path. |
| [docs/build/SESSION-LOG-ARCHIVE.md](build/SESSION-LOG-ARCHIVE.md) | The dated prose session log, archived 2026-08-01. |
| docs/SCENARIO-V2.local.md | **GITIGNORED / PRIVATE:** the real couple's data and core scenario, to re-model after a DB wipe. **Read before touching any V2 figure.** |
| docs/HOUSING-OFFERS.local.md | **GITIGNORED / PRIVATE:** every mortgage / equity-release offer actually made, with lender, rate, date and the email it came from. The source the mortgage scenarios are priced off. |
| docs/BENEFITS-CHECK-V2.local.md | **GITIGNORED / PRIVATE:** full benefits check for the couple. |
| docs/REVIEW-PANEL-2026-08-19.local.md | **GITIGNORED / PRIVATE:** the five-discipline expert review in full, with the figures the tracked cards omit. The source for cards 0024 to 0071. |
| docs/build/PLAN-*.md, docs/research/RESEARCH-*.md | Per-feature specs, build records and research. |

## Branch status
On `master`. GitHub remote `origin` is github.com/RobertLCraig/RetireForecast. **Pushing to `master` is gated and needs Rob's explicit go-ahead.** Otherwise commit directly to `master` (personal local-first project, no PR flow). **Re-check `git status` and `git log` before any commit or push: this tree is sometimes shared by two concurrent sessions.** The pre-rebuild prototype is tagged `prototype-v1` (a8f1f68).

## Session log
Not kept here. The narrative is the commit history and the rationale is the decision log:

```bash
git log --format='%ad %s%n%b'      # what happened
```

Older prose sessions are archived at [docs/build/SESSION-LOG-ARCHIVE.md](build/SESSION-LOG-ARCHIVE.md), out of the load path.
