# Decisions: RetireForecast

Append-only log of decisions and their rationale, newest first. Do not rewrite history;
supersede an old entry with a new one that links back to it.

## 2026-06-25 — Rebuild: keep the engine, rebuild storage to the new world; ratify LW4+SQLite; defer GIA/CGT
**Decision:** Rob authorised a clean rebuild treating the existing code as a prototype, with a key
liberation: **no existing user data, DB layout or data shape must be preserved** — build storage to match
the new world directly. Concretely: (1) **Keep the framework-free engine** (penny-accurate, now 113 tests)
and the sound app code; **rebuild the data/storage layer freely** (no migration, no backward-compat — this
removes gotcha G). (2) **Ratify the real stack** — Livewire 4 + Filament 5 + SQLite + db/sync queue — over
the plan's stale Livewire 3 + Redis/Horizon (reverting LW4→3 would fight Filament 5 for no gain). (3)
**Interleave trust fixes with features** rather than sequencing (Rob: "not too worried about first focus…
build to a natural slightly beyond MVP"). (4) **Defer the GIA/cash income-tax + CGT-on-disposal modelling
(A5) to the trust pass (Phase D):** the projector grows GIA/cash at the assumption set's *total* real
return, so taxing a yield on top would **double-count returns** — it must be done by decomposing total
return into a taxed income yield + deferred capital growth (CGT on disposal with AEA + rates), alongside the
gov.uk figure verification. Shipping it hastily would itself be a trust bug.
**Why:** The engine is the trustworthy, costly-to-recreate asset; the prototype builder/storage was always
disposable (it existed to get a usable app for feedback). Freeing the rebuild from data migration lets the
new shape (builder_state source of truth, delta children, line items, account contributions, longevity) be
built cleanly instead of bolted on. The prototype is preserved at tag **`prototype-v1`** (commit a8f1f68)
for recovery (no remote). [[2026-06-25 — Scenario model: base plan + delta what-if children + compare]]
**Status:** active

## 2026-06-25 — Engine enrichments for the new world (contributions, longevity, usable wealth, income-by-source)
**Decision:** Built the engine capabilities the sector-informed rebuild needs (Phase A), each golden-master /
reconciliation tested, all additive and backward-compatible: (1) **ongoing contributions** on `Account` (new
field) and DC pensions (the DTO already carried `ongoingContribution`/`employerContribution` but the projector
ignored them) — funded from surplus only, so saving stops once the household is in net drawdown; (2) a
per-person **`LongevityAdjustment`** (peer / fixed age / ±years / mortality multiplier) feeding both the
deterministic representative death age and the Monte Carlo sampler (q(x) multiplier on the cohort table); (3)
**terminal usable wealth** (excl. home) reported alongside total on `ForecastResult`/`SimulationResult` (fixes
the asset-rich / cash-poor "wealth left" paradox, gotcha P) at the engine boundary; (4)
**`YearResult::incomeBySource`** — every year's inflows split across the canonical sources (salary, DB, State
Pension, annuity/other, tax-free, pension lump sum, pension drawdown, savings drawn), powering the cashflow
ladder and the per-source completeness guard (gotcha Q).
**Why:** These are the prerequisites the new-world features (line items, drill-down, lifespan/contribution
what-ifs, honest wealth reporting) consume; building them first keeps the engine the single source of truth
and lets the app layer be rebuilt against a stable, tested surface. v1 simplification flagged: pension
contributions are funded from net surplus with no tax relief modelled (slightly understates the pre-retirement
pot), to revisit in the trust pass. [[2026-06-25 — Expenditure: 3-tier line items (essential / discretionary / self-investment) + spent-vs-saved]] [[2026-06-25 — Per-person longevity / health adjustment (new engine input)]]
**Status:** active

## 2026-06-25 — Forecast income completeness: count every source, no silent drop
**Decision:** The forecast must count **every** income source that should reach a household's spendable
cash, and a regression test guards each one. Found via live use: `PathProjector::incomeStreamsNominal`
summed only **taxable** streams and the tax-free branch was never added anywhere, so **DLA / any tax-free
income was silently dropped** — understating income and overstating the chance of running out. Fixed
(tax-free streams counted untaxed into net cash) + a regression test. The durable guard is a **per-source
completeness test** (salary, DB, State Pension, taxable + tax-free income streams, DC withdrawals, asset
drawdown each demonstrably contribute).
**Why:** This is the **completeness** sibling of the data-layer integrity discipline — reconciliation
catches double-counting (sum of parts == total); completeness catches the opposite (a part silently
dropped). Both are "no silent failure" applied to the maths, and both are exactly the class of bug Rob has
been burned by. The drill-down's **income-by-source** view is the visual guard that makes such gaps obvious
(docs/PLAN.md gotcha Q). [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active

## 2026-06-25 — Scenario model: base plan + delta what-if children + compare
**Decision:** Adopt the cashflow-modelling sector's standard shape (Voyant): a **base plan** that spawns
**named "what-if" child scenarios** created from a plain **"Create child" button**, each **overriding
anything the user changes** (often just 1–2 — rent, council tax, a healthcare savings amount, a person's
expected lifespan; sell-vs-stay, buy-vs-rent), with a **side-by-side Compare**. A child is
stored as a **delta — only its overridden parameters — on top of the base**; the effective inputs are
the base's persisted form-state (`builder_state`) **overlaid with the child's overrides**, resolved by
**one merge function** (with a round-trip test). It is **not** a full copy of the base. The child editor
is the **full builder pre-filled from the base** — whatever the user changes becomes an override (curated
levers like "retire 2 years later" are just shortcuts, **not** a limit), and **list items (expense lines,
pensions, accounts) gain stable IDs** so an override targets the right row across base edits (people
already have ids). Editing a saved scenario, spawning a child, and comparing all build on the persisted
form-state: edit reloads it, a child stores overrides against it, compare runs base + child.
**Why:** Confirmed with Rob — lightweight "tweak 1–2 parameters" children are exactly the what-if UX he
wants, and it is the market-leader pattern (Voyant's copy-on-write — "changing an item breaks the link
for that item only"; see [docs/RESEARCH-cashflow-modelling.md](docs/RESEARCH-cashflow-modelling.md) §1).
**Delta over full-copy specifically to avoid forking:** a full copy duplicates the whole base into every
child, so a later base fix leaves children stale — they **fork** — the exact "same quantity in two places
that drifts" the data-layer guardrails exist to prevent. A delta keeps the base as the single source; a
child holds only its tweaks and otherwise tracks the base. _(This **corrects an initial full-copy lean**
taken earlier the same day, recorded here so the plan does not fork — per Rob's "don't accidentally fork
ourselves".)_ The new bite to guard is **override resolution** (`effective = base ⊕ overrides`) and
**orphaned overrides** if the base shape changes — both covered by the merge function + tests. The
engine DTO stays a **derived** artifact regenerated from the resolved inputs, so inputs keep one source
of truth. The data-shape change this needs is **authorised** — Rob confirmed the clean rebuild even
though it reworks yesterday's prototype builder, which served to get a usable app for feedback; the UI
wins (person names, the State Pension shortcut) carry over and the draft mechanism folds into
`builder_state`.
Generalises [[2026-06-24 — Forecast services: run = 3-variant comparison, deterministic on demand]]; full
build order in docs/PLAN.md "Sector-informed build plan (2026-06-25)". [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active — **Phase B BUILT (2026-06-25):** `scenarios.builder_state` is the single source of
truth, the engine DTOs are derived from it (no reverse-mapper), structural columns are a projection, and a
saved forecast is editable in place (owner-scoped update-or-create that invalidates stale runs); the
`households`/`scenario_drafts` tables + their models/mappers are dropped (the draft is a `draft`-status
scenario). **Still pending: the delta-child what-ifs + Compare (Phase C2)** and the stable list-item IDs
they need.

## 2026-06-25 — Expenditure: 3-tier line items (essential / discretionary / self-investment) + spent-vs-saved
**Decision:** Replace the flat essential/discretionary totals with **line items as the source of truth**:
`{id, label, amount(annual), category, savedAsAsset}`, category ∈ **essential** (needs, the floor) /
**discretionary** (wants, can-drop) / **self-investment**. Essential/discretionary **totals become the sum
of the lines** (derived — reconciliation discipline). Self-investment is a **first-class tier** (learning,
tuition, books, savings plans, personal investments) — **not** derivable from contributions. Each
self-investment line carries a **`savedAsAsset` flag** (default false = *spent*): *spent* lines
(courses, books) are expenditure; *saved* lines (savings/investments) are a **contribution that builds net
worth**, which needs a small addition — **ongoing contributions on savings accounts** (as DC pensions
already have). **One home per pound:** a saved line **is** the contribution, never also entered as an
account balance.
**Why:** A budget that forces prioritisation (keep essentials / drop discretionary / invest in the future)
is the sector-standard income-&-expenditure model and feeds the income-floor ("essentials covered by
guaranteed income"). Self-investment can't be derived (Rob: it spans learning/tuition/books that never
touch an account), so it is a tagged tier; the spent/saved flag keeps the **forecast correct** (spent
reduces wealth, saved is retained + grows) and **double-count-safe**. The split is framed as **the goal,
not a fixed percentage** (50/30/20 vs 60/20/20 vary everywhere, and a prescribed target reads as advice →
trips the lint). Importers populate the lines (the IWT profile already routes Fixed→essential,
Guilt-Free→discretionary, Investments+Savings→saved). [[2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures]]
**Status:** active

## 2026-06-25 — Per-person longevity / health adjustment (new engine input)
**Decision:** Add a **per-person longevity adjustment** so a what-if can model someone not expected to
reach peer-average age (e.g. known health conditions). It feeds the cohort-table `JointLifeSampler` as one
of: a **fixed assumed death age**, a **±years offset** to life expectancy, or a **mortality multiplier /
rated age** (insurer-style). Exact mechanism chosen at build; ships with a golden-master test.
**Why:** Rob wants to tweak expected lifespan in a child what-if; mortality is currently derived only from
DOB + sex with no health lever. This is a genuine **engine** feature (not a form-state override of an
existing field), so it is planned as its own small piece, keeping the engine framework-free + tested.
[[2026-06-24 — Mortality model: embed ONS cohort life tables]]
**Status:** active

## 2026-06-25 — Data-layer integrity: single-definition + reconciliation invariants + real-file golden fixtures
**Decision:** Treat data-layer consistency as a hard, **tested** requirement, not a hope.
Concretely: (1) **one definition, one home** for every quantity — totals are **derived from their
parts**, never stored alongside them (e.g. `ExpenseProfile::targetAnnualSpend()` sums
essential+discretionary; ages derive from DOB; the engine DTOs stay the single source of truth that
storage and UI map to/from). (2) **Reconciliation invariants** must be asserted in tests:
sum(imported monthly line items)×12 == reported essential spend; net sale proceeds == sale −
mortgage − costs − CGT; per-variant terminal wealth == liquid + property. (3) Every spreadsheet
profile must be verified against a **sanitised real-file golden fixture** — a structurally faithful
copy of the real workbook (same layout traps: decoy "take home" rows, merged headers,
total/remainder rows) with the figures replaced by fakes, committed to the suite — because the
synthetic happy-path test alone let **two real double-counting bugs through** (`PayAndExpenditures`),
caught only by running on the real file by hand. (4) Every figure a view shows must trace to **one
computed value**; the panel, the CSV export and the interpretation read the same field, pinned by a
test. (5) Aggregated/imported totals are **surfaced for review** (no-silent-failure applied to
*counting*), so a mismatch is visible, never absorbed.
**Why:** Rob was burned on a past project not by hallucinated numbers (those were verifiable) but by
the data layer **inconsistently counting the same information** — the same quantity aggregated
differently in different places. The integer-pence rule, the DTO single-source-of-truth and the
round-trip equality tests already defend the *transport* boundary (a stored value decrypts to an
identical DTO), but they do **not** defend the *aggregation* boundary, where this class of bug lives.
The two `PayAndExpenditures` double-counting bugs are direct evidence this project is not immune; the
only thing that caught them was a manual real-file run, which CI does not repeat. Making
reconciliation an explicit, tested invariant — plus a committed real-shaped fixture — turns "verified
once by hand" into "verified every build." **Implemented for the importers (2026-06-25):**
`tests/Fixtures/Import/GoldenWorkbooks.php` (sanitised real-file fixtures, layout-faithful, fake
figures) + `tests/Unit/Import/ImportReconciliationTest.php` reconcile each profile's output to the
sheet's own stated totals. On its first run the guardrail immediately caught — and we fixed — **two
live wrong-aggregation bugs** in the IWT `ConsciousSpendingPlan` importer that the synthetic tests
missed: a per-bucket "… TOTAL" row was summed on top of its line items (essential came out ~2×), and
the `NET WORTH` Investments/Savings rows were miscounted as monthly contributions. The fix makes a
bucket's own TOTAL authoritative. Still to do: the displayed-figure-provenance test (#4, panel == CSV
== interpretation) and the import reconciliation panel (#5). [[2026-06-25 — `.xlsx` import via PhpSpreadsheet; a bespoke profile for the personal workbook]] [[2026-06-24 — Persistence: one encrypted payload per row, mappers in the app]]
**Status:** active

## 2026-06-25 — `.xlsx` import via PhpSpreadsheet; a bespoke profile for the personal workbook
**Decision:** Read `.xlsx` uploads with **`phpoffice/phpspreadsheet`** (an **app-layer** dependency —
the engine stays framework- and dependency-free). Profiles no longer take a raw string; they take a
sheet-aware **`Spreadsheet`** value object (sheetName → string rows) built by **`SpreadsheetReader`**
from CSV or XLSX. XLSX is read **data-only**, taking the values Excel last **cached** (no
recalculation), so unsupported formulas don't break the import. Multi-tab workbooks get a **tab
picker** (`updatedImportFile` lists sheet names; `Spreadsheet::select` narrows to the chosen one
before parsing). A bespoke **`PayAndExpenditures`** profile reads Rob's scenario tabs: the expenditure
block is anchored on **"% of Take Home Pay"** (the only heading unique to it — bare "take home" and
"Expenditure Item" both collide with rows/headers above it), summing monthly outgoings → essential;
the income block above the deductions header maps **State Pension → a state pension, DLA → tax-free
income, salary → gross, and a pension named in a later column → an annuity**. Imported income lands on
**Person 1 with no start age** — the sheet carries neither ages nor a person split — and that is
**flagged in the import summary**, not guessed. Everything imports as **essential** (no per-line split
yet). **Each mapping was verified by running the profile on Rob's real workbook**, not just synthetic
fixtures.
**Why:** Rob supplied real `.xlsx` files and wants to upload them directly. Reading cached values keeps
a formula-heavy personal workbook importable without a calc engine. Anchoring on the unique header and
verifying on the real file caught two double-counting bugs that synthetic tests alone missed (Rob
flagged the over-confidence) — so "trustworthy / no silent failure" is upheld by surfacing every
imported total and every unset field for review rather than fabricating ages or a discretionary split.
Refines [[2026-06-25 — Scenario builder is a wizard; spreadsheet import via a profile registry]]. The
**line-item expense categories** data model and **Nischa** (a 50/30/20 dashboard) stay deferred.
**Status:** active

## 2026-06-25 — Scenario builder is a wizard; spreadsheet import via a profile registry
**Decision:** (1) The builder is a **five-step, free-navigation wizard** (About & people; Pensions &
income; Your net worth = savings + the home; Spending; The decision). Stepping is **server-side**
(`@if($step===N)` + `wire:click` nav), not Alpine `x-show`, because the existing tests drive the
component by setting properties and calling `save()` (never the DOM), so server-side steps stay fully
unit-testable without a browser and the property/`save()` contract is unchanged. A failed `save()`
catches `ValidationException`, sets `$step` to the first step owning an errored field (a static
field→step map) and re-throws, so the user lands on the problem. Accessibility (`aria-current`,
focusable headings + error summary via dispatched events, `aria-invalid`/`aria-describedby`,
double-submit guard, a new `endAge ≥ startAge` rule) is built into the restructure rather than bolted
on. (2) **Spreadsheet import** is an `App\Import\ImportProfile` **registry**: each profile turns one
known layout's contents into a partial form state (`ImportResult`), money summed as **exact integer
pence** (`MoneyText`, mirroring the assembler's no-float rule), monthly figures ×12 to annual. The
**RetireForecast CSV** profile is the one calibrated reader; it pre-fills only spending + the main
salary and reports honestly what the household still needs by hand (budgets carry cashflow, not the
balance sheet). **IWT / Nischa ship as registered `UncalibratedProfile` stubs** that refuse with a
reason until a real sample export maps their cells — no guessing a layout we have not seen. XLSX
(needs phpoffice/phpspreadsheet) and line-item expense categories are deferred to Rob's call.
**Why:** A wizard was Rob's explicit UX ask and the single long form was the a11y pain point; keeping
it server-side preserves the test suite and avoids a browser dependency overnight. The profile
registry makes import extensible and honest — the popular third-party templates are first-class once
calibrated, and "no silent failure" holds (every refusal carries a reason). Reusing the integer-pence
rule keeps money lossless across the import boundary. [[2026-06-24 — UI: hand-rolled Livewire + a separate assembler, charts as enhancement]] [[2026-06-25 — External-review triage: what we adopt, and three declines]]
**Status:** active

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
