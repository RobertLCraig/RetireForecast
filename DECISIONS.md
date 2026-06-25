# Decisions: RetireForecast

Append-only log of decisions and their rationale, newest first. Do not rewrite history;
supersede an old entry with a new one that links back to it.

## 2026-06-25 — Compliance layer built: partition lint, acknowledgement gate, walled-off interpretation
**Decision:** Implemented the regulatory layer (Phase 2 step 4) with these concrete choices.
(1) The banned-phrasing guard is `App\Compliance\OutputPhrasing` holding **directive-only** regex
patterns ("you should", "the best option", "is better for you", …) — never the bare nouns — so neutral
disclaimers that use "recommend"/"advice" in negated form ("not a recommendation", "does not tell you
what to do") pass. (2) The build test is a **path/namespace partition**: it scans every Blade view plus
all app PHP and asserts zero violations, with exactly two exemptions — the `App\Compliance` namespace
(the lint patterns + the `Interpretation` service) and any view whose filename contains
`interpretation`. A separate assertion proves the partition is load-bearing (the `Interpretation`
service *does* contain directive phrasing and is the only thing exempt). (3) The first-run
acknowledgement is a **middleware gate** (`EnsureDisclaimerAcknowledged`) redirecting unacknowledged
users to a dedicated screen and storing `users.disclaimer_acknowledged_at` — **not** a JS modal (a
server-side gate is testable and cannot be skipped); GDPR/account routes sit **outside** the gate
(data-subject rights are not withheld pending acceptance). (4) Per-result disclaimer + signpost are
reusable Blade components (`<x-disclaimer.result>`, `<x-signpost>`); every CSV export is prefixed with
the guidance-only disclaimer. (5) The interpretation capability is a `users.can_interpret` boolean
behind an `interpret` Gate, set via a Filament `UserResource` `ToggleColumn`; the gated partial and the
`Interpretation` service are the sole homes of directive wording. (6) Deleted the stock Laravel
`welcome.blade.php` (unused — the landing is `home.blade.php`; it tripped the lint with marketing copy).
**Why:** Directive-only patterns + a path partition keep the lint precise (no false positives on
disclaimers, no escape hatch for real recommendations) and make the walled-off advice mode auditable
rather than ad hoc. A middleware gate honours "no silent failure" and is provable in tests. Implements
[[2026-06-25 — Optional per-user "interpretation" (advice-style) output, admin-granted, off by default]]
and [[2026-06-24 — Regulatory posture: guidance only]]. Also folded in the tagged "no silent failure"
hardening: GDPR `export()` now includes the user's runs+results, `RunScenarioSimulation::failed()`
lands a dead worker's run in a terminal Failed state, and `ScenarioResults::currentRun()` is
owner-scoped against a forged `$runId`.
**Status:** active

## 2026-06-25 — Optional per-user "interpretation" (advice-style) output, admin-granted, off by default
**Decision:** Add an optional capability ("interpretation mode" / "what this suggests") that, when
enabled for a specific user, renders directive plain-language readouts ("under these assumptions,
buying lasts longer; renting runs out in N% of paths") alongside the neutral figures. It is **off by
default, the public default stays neutral guidance**, and it is **granted only by an admin** (a per-user
boolean on `users` behind a Gate ability, set from Filament) — never self-serve. The directive
sentences are produced by a single walled-off `Interpretation` service **from the computed numbers,
not hard-coded into the result Blade templates**. The banned-phrasing build test is therefore reframed
from "no banned phrasing anywhere" to a **partition check**: the neutral result/warning templates,
default formatter and exports must stay clean, and directive phrasing may exist **only** inside the
gated interpretation layer. Every output/export is labelled with the mode that produced it.
**Why:** For Rob's own and family use the directive framing is genuinely clearer, and giving it
privately is outside the FCA perimeter (not by way of business). Walling it off + admin-gating +
neutral-by-default keeps a live public deployment on the guidance side of the line, so the planned
public release survives the feature rather than being blocked by it. The toggle must **not** be
grantable to arbitrary public users on a live deployment (self/family only); doing so would be a
deliberate, separate regulated-perimeter decision. Refines, does not supersede,
[[2026-06-24 — Regulatory posture: guidance only]]; raises the priority of tightening
`User::canAccessPanel()` and the run-ownership scoping before public release.
**Status:** active

## 2026-06-25 — External-review triage: what we adopt, and three declines
**Decision:** A second-opinion review (MS Copilot, from the doc set) was triaged into the post-v1
backlog in [docs/PLAN.md](docs/PLAN.md) ("External review triage"). We **decline** three of its
suggestions as over-engineering or misaligned for a local-first single-user tool: (1) **per-row /
envelope encryption** — the blast-radius case assumes a multi-tenant server, but the whole SQLite DB
*and* the Laravel app key live on one personal machine, so app-key `encrypted:array` is right-sized;
revisit only on a public multi-user release; (2) a **native Monte Carlo accelerator** (Rust/WASM/SIMD)
— premature, and it breaks the framework-free pure-PHP ethos that makes the golden-master trustworthy;
10k PHP paths are already responsive; (3) **automated gov.uk scraping** of tax tables — fragile, and the
figure set is small, so manual sourcing with a `verified_on` date is *more* trustworthy, not less.
We also flag that the review's adviser-style metrics (implied withdrawal rate, critical yield,
replacement rate, narrative report, capacity-for-loss) may only be adopted **behind the
`OutputPhrasing` banned-phrase lint** and stated as neutral facts/definitions — never as targets or
benchmarks (e.g. no "safe 3–4% withdrawal range"), to stay on the guidance side of the line.
**Why:** Keeps the security/perf posture proportionate to an on-machine personal tool, protects the
engine's isolation, and holds the education-only constraint that is a hard project rule.
[[2026-06-24 — Regulatory posture: guidance only]] [[2026-06-24 — Engine is framework-free, in a path package]]
**Status:** active

## 2026-06-24 — UI: hand-rolled Livewire + a separate assembler, charts as enhancement
**Decision:** The scenario builder and result views are hand-rolled Livewire 4 components (Filament
stays admin-only). Form input becomes engine DTOs in a standalone `HouseholdAssembler` (not inside
the Livewire component), so the string→DTO conversion is unit-testable and reusable (the demo preset
later). Money the user types is parsed to exact integer pence by a string parser (split on `.`, pad
to 2dp), never `(float) * 100`, keeping "no float in money" true at the UI boundary. Every figure a
chart plots is also rendered as headline text and inside an accessible `<table>` (in a `<details>`)
with a CSV download; the ApexCharts canvas (bundled via npm, mounted by a small reduced-motion-aware
Alpine `chart` wrapper) is a progressive enhancement, never the source of truth.
**Why:** The plan mandates a hand-rolled Livewire builder and WCAG 2.1 AA charts where headline
numbers are text first. Separating the assembler keeps the lossless shape conversion provable in
isolation (it rebuilds the rich `HouseholdFixture` exactly). [[2026-06-24 — Persistence: one encrypted payload per row, mappers in the app]]
**Status:** active

## 2026-06-24 — Full-page Livewire uses the Blade layout component, not `layouts::app`
**Decision:** Full-page Livewire components render into the app's Blade layout component
(`components.layouts.app`) via `#[Layout(...)]`, not Livewire 4's default `layouts::app`. The base
`TestCase` calls `withoutVite()` so view tests do not depend on the gitignored `public/build`. Region
selection is guarded by actually asking `TaxYearRegistry::for()` to build the config, so Scotland is
refused with a clear error until its band pack lands (and auto-enables when it does), mirroring the
engine's own refusal rather than duplicating the rule.
**Why:** Livewire 4 registers `layouts` only as a component namespace, not a view namespace, so its
default page layout has no hint path here; reusing the one Blade layout the auth/marketing pages use
keeps a single source of truth. Tying the region guard to the engine avoids a second place to update.
**Status:** active

## 2026-06-24 — Forecast services: run = 3-variant comparison, deterministic on demand
**Decision:** A `SimulationRun` executes the engine's `HousingComparison` for the scenario's
household + housing action, producing **three `Result` rows** (stay_put, buy_outright, rent) on
one seed — the buy-vs-rent headline. The central deterministic "best estimate" forecast is
computed on demand by `ScenarioForecaster::deterministic()` and not (yet) persisted. The app
assembles all engine inputs in `ScenarioForecaster`; the base year is derived from the
scenario's tax year so runs stay clock-free.
**Why:** Buy-vs-rent is the point of the tool, and the engine already runs the three variants on
identical seeds, so one run → three comparable results is the natural unit. Keeping deterministic
output unpersisted avoids storing a figure the UI can recompute instantly. [[2026-06-24 — Persistence: one encrypted payload per row, mappers in the app]]
**Status:** active

## 2026-06-24 — Engine gains an optional progress hook (non-breaking)
**Decision:** Add an optional `?callable $onProgress` to `Simulator::run` and
`HousingComparison::compare` (default null = unchanged behaviour). The hook carries no I/O, so
the engine stays clock- and I/O-free; the app updates `progress_pct` from it and **cancels a run
by throwing from the hook** (`RunCancelled`), which the engine lets propagate. Progress is
per-path within a variant, with each variant weighted into a third of the overall bar. Chosen
over the plan's "chunk 10×1000 with incremental aggregation", which would need the engine to
expose mergeable partial percentiles (a bigger change for little gain at these run times).
**Why:** "Nothing long-running may run silently" needs a live progress signal, but the engine
must stay framework-free. An optional callback is the minimal faithful touch; throwing for
cancellation keeps cancellation entirely an app concern the engine need not know about.
**Status:** active

## 2026-06-24 — Runs: preview synchronous, full queued; queue driver deferred
**Decision:** Preview runs (~1,000 paths) execute synchronously for responsiveness; the full run
(10,000 paths) is queued via the standard Laravel queue abstraction (`RunScenarioSimulation`
job, holding only the run id). The concrete queue driver is left to infra — database/sync
locally, Redis + Horizon if/when needed — since the mechanism (job + status + progress + cancel)
is driver-agnostic. The seed is generated and recorded when not supplied; the assumption set is
snapshotted (frozen) on the run so results survive later edits to the live set.
**Why:** Matches the plan's preview-vs-full split without committing the local-first app to a
Redis dependency it does not need yet. Recorded seed + frozen snapshot make every stored run
reproducible and auditable. [[2026-06-24 — Engine gains an optional progress hook (non-breaking)]]
**Status:** active

## 2026-06-24 — Persistence: one encrypted payload per row, mappers in the app
**Decision:** Store each Household and Scenario as clear structural columns (name, region,
variant, base tax year, status, owner, timestamps) plus **one `encrypted:array` payload**
holding all the sensitive detail, rather than ~30 encrypted columns. The DTO↔array mapping
lives in the **app** (`app/Finance/Mapping/`), not the engine, so the engine stays
framework- and serialization-agnostic; the readonly DTOs under `packages/finance-engine/src/Dto`
remain the single source of truth and Eloquent maps to/from them.
**Why:** Encrypted columns are unindexable anyway, so a single payload is simpler and keeps
listing/filtering on the clear columns. Keeping the mapper app-side preserves the engine's
isolation. A round-trip test asserts a saved row decrypts to an identical DTO. Follows the
plan's persistence section. [[2026-06-24 — Engine is framework-free, in a path package]]
**Status:** active

## 2026-06-24 — Withdrawals on the pension; SimulationRun/Result deferred
**Decision:** Planned pension withdrawals live on the DC pension inside the household payload
(faithful to `DcPension::$withdrawalPlan`), **not** duplicated as a scenario-level field, so
there is one source of truth for them. The `SimulationRun` and `Result` tables are deferred
to the forecast-services step (there are no results to persist until the engine is wired into
the app).
**Why:** The data-model sketch listed withdrawal_decisions on Scenario, but the canonical DTO
already carries them on the pension; honouring the DTO avoids a second, divergent home for the
same data. Deferring run/result storage keeps phase-2 step-1 focused on input persistence.
**Status:** active

## 2026-06-24 — Auth: Fortify headless, web-route guests redirect to login
**Decision:** Install Laravel Fortify but run it **headless** (`config/fortify.php` views
disabled) until the Livewire UI phase builds the login/register screens. GDPR/account routes
sit behind the `auth` middleware; an unauthenticated visitor is **redirected to login** (302),
which is the correct behaviour for a web app (not a 401 API response). A placeholder named
`login` route exists so the redirect target resolves until the real screen ships.
**Why:** The auth backend is needed now (ownership scoping, GDPR), but the screens belong with
the rest of the UI. Anonymous use writes nothing because every write path is auth-gated.
**Status:** active

## 2026-06-24 — Admin: Filament 5 (Livewire 4); assumption-set figures stay sourced
**Decision:** Use Filament 5 for the admin panel at `/admin` (it pulls **Livewire 4**, a bump
from the plan's stated Livewire 3). The AssumptionSet resource curates metadata (name, source
note, default) and a model hook keeps at most one default; the **sourced numeric figures are
seeded from the engine's signed-off `AssumptionSetLibrary` and are not casually editable in
the admin** (numeric editing is a deliberate, flagged follow-up). The tax-year audit page is
read-only over the registry. `User::canAccessPanel()` returns true for this local single-user
app (tighten before any public release).
**Why:** The figures are sourced and signed off; editing one means re-sourcing it with a new
verified-on date, which should be a deliberate act, not a stray form save. Keeps the
"no magic numbers, every figure sourced" posture intact. [[2026-06-24 — Tax figures versioned per tax year, sourced and dated]]
**Status:** active

## 2026-06-24 — Mortality model: embed ONS cohort life tables
**Decision:** Drive stochastic joint-life mortality from embedded ONS cohort life tables
(by single year of age and sex), sampling each partner's age of death per path and running
the household to the last survivor.
**Why:** Cohort tables account for future mortality improvements, so lifespans (and the
"will the money last" answer) are realistic. Rob chose this over a parametric fit (compact
but less precise at extreme ages) and over period tables (simpler but understate longevity).
Larger data ingest, but a one-off, and it carries a clear ONS source.
**Status:** active

## 2026-06-24 — Forecast mechanics: dual drawdown strategy + cautious default allocation
**Decision:** (1) Ship TWO drawdown strategies and compare them side by side rather than
picking one: "tax-efficient" (cash → GIA → ISA → DC pension last) and "pension-aware" (draw
DC pension income earlier, up to a sensible band, to reduce the post-April-2027 IHT estate).
Default display = tax-efficient. (2) Default invested-pot allocation (DC, ISA, GIA) =
cautious **40% equities / 60% bonds**, no cash within pots; cash accounts use the cash
assumption. Both are runtime-configurable per scenario.
**Why:** The drawdown order trades off income tax now vs sheltered growth and IHT later;
showing both keeps the tool neutral (illustrate consequences, not recommend) and surfaces the
April-2027 tension. A cautious 40/60 suits a retired household relying on the pot for income
(lower sequence-of-returns risk). [[2026-06-24 — Forecast mechanics: ... ]]
**Status:** active

## 2026-06-24 — Assumption figures signed off (adopted as proposed)
**Decision:** Rob signed off the researched figures in [docs/ASSUMPTIONS.md](docs/ASSUMPTIONS.md)
as proposed. Set A (FCA real returns + DMS vols) is the engine default; Sets B (DMS
historical) and C (OBR/BoE) ship as compare overlays. Includes the flagged judgement calls:
cash real vol overridden to 2% (inflation modelled separately), Eq–Cash/Bond–Cash
correlations as reasoned estimates, house growth +1% real. Figures stay runtime-overridable
and are re-verified at build time.
**Why:** Unblocks the forecast year-stepper and Monte Carlo with defensible, cited defaults.
**Status:** active

## 2026-06-24 — Default assumptions: FCA expected returns + DMS volatilities
**Decision:** The default AssumptionSet uses FCA-derived expected returns combined with
Barclays Equity Gilt Study / Dimson-Marsh-Staunton (DMS) historical volatilities and
correlations. DMS and OBR/BoE-inflation sets ship as compare overlays. Claude researches and
proposes the actual cited figures; **Rob signs off before any forecast is shown as real.**
**Why:** FCA projection rates are the familiar, defensible default but publish only nominal
growth brackets, not the volatility/correlation a Monte Carlo needs; DMS supplies those from
a coherent historical source. Pairing them gives a defensible default that the stochastic
engine can actually run. [[2026-06-24 — Modelling depth and scope (from approved plan)]]
**Status:** active

## 2026-06-24 — Savings + dividends computed in one combined income-tax pass
**Decision:** The plan listed separate `SavingsTax` and `DividendTax` calculators. Instead,
savings and dividend tax are computed inside `IncomeTaxCalculator::compute(TaxableIncome)`,
a single combined pass over the rate bands, rather than as three independent calculators.
`onNonSavingsIncome` is retained for the simple case (and the existing tests).
**Why:** UK income tax stacks the three categories in a fixed order (non-savings, savings,
dividends), and the savings starting-rate band, Personal Savings Allowance and dividend
allowance all consume rate-band space even though charged at 0%. Computing them separately
cannot get the band interactions right; a shared band cursor is the only faithful model.
**Status:** active

## 2026-06-24 — Scaffold the standard doc set
**Decision:** Added PRD.md, DATA-MODEL.md, DECISIONS.md and the root CLAUDE.md orient
tripwire, porting goal / data model / decisions out of docs/PLAN.md so they have a standard
home. docs/PLAN.md remains the exhaustive scope source of truth.
**Why:** The project adopted the documentation standard; the orient hook flagged these as
missing. Keeps a fresh session from re-reading the whole plan to find the shape.
**Status:** active

## 2026-06-24 — Local-first, personal use, no hardcoded client data
**Decision:** Build a local single-user site. Rob enters the couple via the UI himself; any
first-run sample must be obviously fictional. Possible free public release later, so do not
design accounts out — just defer them.
**Why:** The immediate need is Rob's own decision support for a known real couple. Hardcoding
their data would leak PII into the repo and bake in one scenario.
**Status:** active

## 2026-06-24 — Money is hand-rolled integer pence (brick/money dropped)
**Decision:** Use a hand-rolled `Money` value object over integer pence. Do not re-add
brick/money without re-checking the clash.
**Why:** `brick/money` could not resolve against `brick/math` 0.18 in the Laravel 13 lock.
The plan already listed integer pence as the primary option; zero dependencies strengthens
the engine's framework isolation.
**Status:** active

## 2026-06-24 — Engine is framework-free, in a path package
**Decision:** The calculation engine lives in `packages/finance-engine`
(`retireforecast/finance-engine`, path repo, required `"*"`), with zero Laravel
dependencies, no I/O and no clock. Tests run as pure PHPUnit, no Laravel bootstrap.
**Why:** Isolation is what makes the HMRC worked-example tests and the Monte Carlo
golden-master trustworthy. The Laravel app is a shell around the product.
**Status:** active

## 2026-06-24 — Tax figures versioned per tax year, sourced and dated
**Decision:** Every tax figure lives in a per-tax-year config carrying a `source` URL and a
`verified_on` date. Two stale-brief corrections baked in: income-tax threshold freeze runs
to **April 2031** (not 2028); **dividend rates rise in 2026/27** (ordinary 8.75→10.75,
upper 33.75→35.75).
**Why:** No magic numbers; figures must be defensible against gov.uk. 2025/26 and 2026/27
genuinely differ, so they are distinct config years.
**Status:** active

## 2026-06-24 — Modelling depth and scope (from approved plan)
**Decision:** HMRC-accurate deterministic engine PLUS Monte Carlo with stochastic joint-life
mortality. Pensions: DC, DB, State Pension. Housing: buy-cheaper-outright vs rent on
identical seeds. IHT/legacy in as a toggle (incl. pensions entering the estate from Apr
2027). Assumptions are a runtime/display choice across several sourced sets (FCA default),
not baked in. England/Wales/NI first; Scotland income tax + LBTT/LTT out of v1 (region
resolver throws rather than guessing).
**Why:** Matches the decision-support goal: the consequences only become visible if tax,
longevity and sequence risk are all modelled properly. Captured here from docs/PLAN.md.
**Status:** active

## 2026-06-24 — Regulatory posture: guidance only
**Decision:** Education/guidance only, never a personal recommendation. A build-time test
fails if any result template contains banned recommendation phrasing. Signpost Pension Wise
/ MoneyHelper / FCA-regulated advisers.
**Why:** Personal recommendations on pensions/drawdown are FCA-regulated activity. Staying on
the guidance side of the line is a hard design constraint, not a wording afterthought.
**Status:** active
