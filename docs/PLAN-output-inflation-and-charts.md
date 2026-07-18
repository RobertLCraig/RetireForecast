# PLAN — output legibility, category inflation, and the missing time-series charts

> **Status: DRAFT spec, ready for a fresh agent. Nothing here is built yet.** Dated 2026-07-18,
> written from a code-grounded adversarial review of the engine outputs and the Blade output layer
> (two mapping passes over `packages/finance-engine/src` and `resources/views`). Build in green,
> committed slices per the build order; honour the reconciliation/completeness bar (CLAUDE.md).
> Each decision below carries its reasoning and a research link — keep that discipline when you build
> (every figure lands in `docs/ASSUMPTIONS.md` / a `TaxYear` record with a `source` + `verified_on`,
> not as a magic number). **This is a plan, not a locked decision** — the open questions marked
> *Decide* need Rob's call (or the executing agent's, recorded in DECISIONS) before that slice ships.

## Why / motivating findings

An adversarial review (2026-07-18) found the engine is accurate and well-tested, but three classes of
problem sit **between the engine and the reader**:

1. **Some headline verdicts are biased in the reassuring direction** — the dangerous direction for a
   decision-support tool. Care cost is absent from every deterministic surface; the Affordability screen
   leads with a deterministic yes/no while the real Monte-Carlo probability is a coin-flip; and the MC's
   Gaussian returns understate the left tail where "running out" lives.
2. **The output is information-heavy** — an ~1,170-line, 18-section results page that front-loads up to
   four advisory banners before the first number and doubles every chart with an inline table.
3. **Nearly every chart a user would want already has its data computed per year and thrown at a table
   instead of a picture** — `incomeBySource` (11 sources/yr), the spend split, the wealth legs, tax,
   mortgage balance are all on `YearResult` and only one time-series chart (the wealth fan) exists.

The through-line: most of the value here is **presentation**, not new engine capability — except the
inflation and tail-risk items in Part A, which are genuine correctness gaps and take priority under Rob's
"accuracy over less work" rule.

---

## Part A — correctness (engine)

### A1. Per-category cost inflation, starting with care (highest priority)

**Finding.** The engine draws **one** CPI series and models every other rate as a *real spread* over it
(`MonteCarlo/ReturnModel.php:132`; `Dto/AssumptionSet.php`). Only property service charges
(`ExpenseProfile::propertyCostsRealGrowth`) and rent (`ForecastSettings::rentInflationReal`) get their own
real rate. **Care fees ride flat CPI** — the sampler applies self-funder fees in real terms with no
above-CPI escalation. Because the display is real today's-money, all costs look flat over the horizon.

**Why it's wrong.** Care is the single largest fat-tail cost in the model *and* the fastest-inflating major
category in UK retirement. Self-funder care-home fees rose ~10% in the year to Dec 2025 and ~20% over two
years — several points above CPI — driven by National Living Wage and the Apr-2025 employer-NI rise, and
this is a structural (labour-cost) driver, not a one-off. More broadly, the pensioner spending basket
(energy/food/care heavy) has repeatedly outrun headline CPI. A tool whose whole purpose is to surface
care and longevity risk currently *understates the cost of exactly that risk.*
- Care fee inflation: [LaingBuisson — "nearly 20% more … over two years"](https://www.laingbuisson.com/press-releases/older-people-forced-to-pay-nearly-20-more-for-their-care-as-fees-skyrocket-over-the-last-two-years/), [carehome.co.uk care-home fees 2026](https://www.carehome.co.uk/advice/care-home-fees-and-costs-how-much-do-you-pay), [gov.uk MSIF provider fee reporting 2024–25](https://www.gov.uk/government/publications/market-sustainability-and-improvement-fund-2024-to-2025-care-provider-fees/market-sustainability-and-improvement-fund-msif-provider-fee-reporting-2024-to-2025).
- Pensioner basket vs CPI: [ONS RPI pensioner index / Economics Help summary](https://www.economicshelp.org/blog/14638/inflation/inflation-rates-for-pensioners/), [ONS Consumer price inflation](https://www.ons.gov.uk/economy/inflationandpriceindices/bulletins/consumerpriceinflation/november2025).

**Target shape.** Copy the existing `propertyCostsRealGrowth` mechanism (the precedent is proven, tested,
and null-safe) to a **care real-growth rate**:
- Add `AssumptionSet::careCostRealGrowth` (`?Percent`, null = flat-real, the back-compat default) — a real
  (above-CPI) annual escalation applied to the self-funder care fee in `Care/` / the care leg of the
  projector. Care fees then compound at CPI + this rate to the (late-life) year the spell falls.
- Optionally generalise to an **essentials real-growth** rate later (energy/food) — but ship care first;
  it carries the most money and the clearest evidence.

**Decide (Rob):** the shipped figure. The 10%/20% headlines are recent NLW/NI spikes, **not** a long-run
assumption. Propose a **long-run care real growth of ~+2% real (CPI + 2%)** as the default, flagged in
`ASSUMPTIONS.md` as a judgement call (the same treatment as house-growth's +1% real), with a note that
recent years ran far hotter. Sets B/C can carry a higher figure. *Confirm the figure + source stamp.*

### A2. Put an expected care cost in the deterministic path

**Finding.** Care is **Monte-Carlo-only** (`docs/METHODOLOGY.md` "Care costs"). But the Affordability
verdict and the central cashflow ladder run the **deterministic** projection, which contains **no care at
all**. So the plain-English "do the essentials last for life? **Yes**" — built specifically for the elder
couple who can't read the fans — is computed on a path that omits their biggest late-life expense.

**Why it's wrong.** It's not just inaccurate, it's *falsely reassuring* for the least numerate reader —
the worst failure mode for this tool.

**Target shape (two options — Decide).**
- **(a) Expected-value care in the deterministic path.** Charge a probability-weighted care cost
  (P(care) × typical cost, placed at end of life) as a deterministic one-off. Simple, always-present,
  but blends a lumpy tail risk into a smooth central line.
- **(b) Keep deterministic care-free but make the omission loud + always show the MC care panel beside the
  verdict**, and add a deterministic "with a typical care spell" toggle/variant. Honest about the lumpiness.
- **Recommendation:** (b) as the floor (cheap, honest), (a) as a follow-on refinement. Either way, the
  Affordability verdict must not read "Yes, lasts for life" while silently ignoring care. Ties to B1.

### A3. Fat-tailed returns (the MC left tail is optimistic)

**Finding.** MC returns are drawn from a **normal** distribution (`ReturnModel.php`), and inflation is
drawn **independently** of returns. Both cut the same way: they understate the probability of the bad
states where depletion happens.

**Why it's wrong.** Gaussian Monte Carlo is documented to *underestimate failure rates by ~10–17
percentage points* versus fat-tailed / historical-bootstrap analysis; real equity markets produce 30%+
drops far more often than a normal predicts. A headline "90% success" from a thin-tailed world is not a
90% from the world that produced 1973–74 or 2008.
- [Kitces — fat tails vs safe withdrawal rates](https://www.kitces.com/blog/monte-carlo-analysis-risk-fat-tails-vs-safe-withdrawal-rates-rolling-historical-returns/), [Quant Decoded — when Monte Carlo fails](https://quantdecoded.com/en/when-monte-carlo-fails-retirement-planning-pitfalls), Blanchett, Finke & Pfau (2017) "Planning for a More Expensive Retirement".

**Target shape.** Opt-in fat tails, same null-safe contract as the stochastic-growth work
(DECISIONS 2026-07-18): an `AssumptionSet::returnTailDegreesOfFreedom` (`?int`, null = normal, the
back-compat default). When set, `ReturnModel` draws asset shocks from a **Student-t** with that d.o.f.
(scaled to preserve the target volatility), so lower d.o.f. → heavier tails. The historical stress-test
already gives a fat-tailed cross-check; this brings the *headline* MC into line.

**Decide (Rob):** whether to ship it *on* by default (more honest, but every stored success probability
shifts down and needs a re-run) or ship it *off* with a compare overlay. Given the byte-identical-reproduce
discipline, propose **off by default (null), documented, with a "stress the tails" toggle** — mirroring how
DMS/OBR ship as overlays rather than replacing the default.

### A4. Triple lock — restore the earnings leg (directional; lower priority)

**Finding.** The triple lock is modelled as `max(inflation, 2.5%)` (`docs/METHODOLOGY.md`), dropping the
**earnings-growth** leg.

**Why it matters.** Earnings has been the *binding* leg repeatedly (8.5% in Apr-2024). Over 2010–2023 the
state pension rose 60% vs prices 42% / earnings 40% — the lock adds a persistent wedge the OBR puts at
~+0.58pp/yr above earnings. Dropping the earnings leg **understates** guaranteed income — note this pulls
*opposite* to A1–A3 (it makes plans look worse, so it's a smaller safety concern, but it's still wrong).
- [House of Commons Library — the triple lock](https://commonslibrary.parliament.uk/the-triple-lock-how-will-state-pensions-be-uprated-in-future/), [IFS R272 — triple lock costs & uncertainty](https://ifs.org.uk/sites/default/files/2023-09/R272-The-triple-lock-costs-and-uncertainty.pdf).

**Target shape (Decide).** Two ways, in effort order:
- **(a) A simple triple-lock wedge:** uprate the state pension at CPI + a small fixed real wedge
  (~0.5%/yr, OBR-sourced). Cheap, captures the long-run drift, no new stochastic coupling.
- **(b) Model the earnings leg properly:** `max(inflation, inflation + realSalaryGrowthDraw, 2.5%)`, reusing
  the salary-growth draw already added 2026-07-18. More faithful, but couples SP to the earnings shock.
- **Recommendation:** (a) for v1 (sourced, simple, robust); flag (b) as a refinement.

---

## Part B — output legibility (presentation, no engine change)

Diagnosis from the review: the single results page is ~1,170 lines / 18 sections
(`resources/views/livewire/scenario-results.blade.php`), front-loads up to four advisory banners before the
first figure, and renders every chart's backing table **inline** (open), roughly doubling the page. The
best consumer planners converge on **one hero number + one hero chart per screen, progressive disclosure,
tables as click-to-drill** — ProjectionLab (a net-worth-over-time hero + a single success rate; Sankey
cashflow), Guiide (a single income-vs-target banded bar as the whole first screen), and the
guaranteed-income-floor framing.
- [ProjectionLab / Guiide / RetireEasy roundup](https://pension-planner.uk/), [guaranteed-income-floor calculator](https://retirementcalculators.uk/calculators/retirement-income-calculator/), [Snap Projections on decumulation UX](https://snapprojections.com/blog/decumulation-strategies/).

### B1. Verdict-first landing, led by probability

Promote a **verdict layer** to the default post-run view (the `/afford` screen already exists — reuse it),
but fix its honesty gap: **lead with the Monte-Carlo probability**, not the deterministic yes/no. A green
"Yes, this lasts" next to a hidden ~55% is the most misleading surface in the app (the handover's own
"honesty gap" note). Show the deterministic path as the *expected* case beside a plain-word probability
band ("roughly a coin toss" / "very likely"), and surface the care panel next to it (ties to A2).

### B2. Collapse the 18 sections into ~4 tabs/accordions

*Verdict → Money over time → Where the money goes → The fine print.* Keep every current section, but behind
tabbed/accordion navigation so the first paint is the verdict + the hero chart, not a wall. No data removed;
purely reorganised. The existing in-page TOC (`scenario-results.blade.php:9-30`) becomes the tab set.

### B3. Push every raw data-table into its `<details>` twin

The a11y contract (every figure also in a visually-hidden `<table>` + CSV — `resources/js/charts.js`) is
non-negotiable and stays. But several tables currently render **open** inline; move them inside the existing
`<details>` "Show the numbers" disclosure so the page halves without losing the accessible source of truth.

### B4. Demote the advisory banners

The input-notes / "New in this build" / "Since your last run" / care-not-modelled banners
(`scenario-results.blade.php:158-210`) render **above** the headline. Move them below the verdict + hero
chart (or into the "fine print" tab), so the reader sees the answer first.

### B5. Bring the PDF up to parity

The PDF is a strictly poorer subset (tables only, reduced cashflow columns —
`resources/views/pdf/partials/report.blade.php`). Once Part C's charts exist, render them into the PDF (or
at least the hero wealth + income charts) so a paper reviewer isn't left with numbers only. Lower priority.

---

## Part C — the missing time-series charts (presentation; data already computed)

Every chart below is buildable from **existing** `YearResult` fields — this is a presenter + Blade job, not
an engine change. Follow the established pattern: build the ApexCharts option blob server-side in a presenter
(`app/Forecast/ResultPresenter.php`), render via the `chart()` Alpine component, ship the `<details>` table
twin (B3). Build in value order:

| # | New chart | Data source (already on `YearResult`) | Why |
|---|---|---|---|
| C1 | **Income staircase** — stacked area of the 11 income sources over time | `incomeBySource` | What Guiide/Voyant lead with; shows the salary→DB→State-Pension→drawdown handover at a glance. Highest value. |
| C2 | **Where your wealth is** — stacked area: pension / ISA+GIA / cash / home equity over time | `pensionWealth`, `liquidWealth`, `homeEquity()` | Rob's "progression of investments" + "cash funds" asks in one chart. |
| C3 | **Costs over time** — essential vs discretionary vs one-offs, care spike visible | `essentialSpend`, `discretionarySpend`, `oneOffCosts`, spend-smile path | Rob's "costs and how they change" ask; makes the smile + care spike legible. Pairs with A1 (shows category inflation). |
| C4 | **Guaranteed income vs spending floor over time** | `essentialSpend` vs secure-income sources | Turns the static coverage bar into the "income floor" story the best UK tools sell on. |
| C5 | **Tax paid over time** | `totalTax` | Makes fiscal drag visible as a rising line. |
| C6 | **Debt / equity-release balance over time** | `mortgageBalance` | The roll-up compounding is a picture, not a ladder column. |

**Also:** add a **nominal-pounds toggle** to the time-series charts. Everything is real today's-money
(correct, but counter-intuitive — a couple thinks "£X in 2045"). The projector already has the nominal
figures pre-deflation; expose a nominal view so the inflation effect from A1 is *visible* rather than
deflated away. And surface terminal **p25/p75** (computed, currently only p10/p50/p90 shown) on the wealth
chart's table.

---

## Open questions / decisions needed (Rob or the executing agent — record in DECISIONS)

1. **A1 care real-growth figure** — propose CPI + 2% real default (flagged judgement call), sets B/C higher. *Decide + source-stamp.*
2. **A2 deterministic care** — expected-value in the central path, or care-free + loud omission + MC panel + a "with a care spell" variant. *Decide.*
3. **A3 fat tails** — ship on-by-default (re-runs every stored result) or off with a "stress the tails" overlay. *Decide.*
4. **A4 triple lock** — simple CPI+wedge (recommended) vs full earnings-leg modelling. *Decide.*
5. **B1 probability wording** — the plain-word bands for the verdict (map probability → words); pass the banned-phrasing lint (advice mode is on, but keep it factual). *Decide the bands.*
6. **Scope split** — Part A (correctness) can ship independently and first; Parts B/C are a separable UX workstream. Confirm the order (recommend A1/A2 → C1/C2/C3 → B1 → rest).

## Tests (the reconciliation / completeness bar)

- **A1:** care fees compound at CPI + the real rate over the horizon (assert a late-life care cost exceeds a
  flat-CPI baseline by the expected factor); null `careCostRealGrowth` reproduces the pre-change engine
  byte-for-byte (back-compat, mirroring the stochastic-growth guards).
- **A2:** with care in the deterministic path, a household with a modelled care spell shows a non-zero care
  cost on the central ladder and the Affordability verdict reflects it (completeness — the input reaches the
  result); without it, unchanged.
- **A3:** a heavier tail (lower d.o.f.) widens the loss tail and *lowers* success probability vs normal on
  the same seed; null d.o.f. = the current normal draw, byte-identical.
- **A4:** the state pension uprates above `max(inflation, 2.5%)` in a year where the wedge/earnings leg
  binds; a flat/zero wedge reproduces the current behaviour.
- **C1–C6:** each chart's `<details>` table reconciles to the same `YearResult` figures the ladder shows
  (one definition — no second store of "income" or "wealth"); the nominal toggle's figures re-inflate to the
  projector's internal nominal values.
- **B:** the a11y pass (`npm run a11y`, `docs/A11Y.md`) stays green after the tab/accordion restructure;
  every chart keeps its table + CSV twin.

## Build order (each slice green + committed)

1. **A1 — care real-growth** (`AssumptionSet::careCostRealGrowth`, care leg, presets, `ASSUMPTIONS.md`,
   completeness + back-compat tests). Highest correctness value, cheapest (copies `propertyCostsRealGrowth`).
2. **A2 — care in the deterministic path** + the Affordability honesty fix framing (feeds B1).
3. **C1 + C2 + C3 — the three hero time-series charts** (income staircase, wealth composition, costs) +
   the nominal toggle. Most story for least work; all data exists.
4. **B1 — verdict-first, probability-led landing.**
5. **B2 + B3 + B4 — tabbed restructure, tables behind `<details>`, banners demoted.**
6. **A3 — fat-tailed returns** (opt-in Student-t) + A4 triple-lock wedge. Correctness refinements.
7. **C4 + C5 + C6 — income-floor, tax, debt charts; B5 — PDF chart parity.**

## Files to touch (map)

- **Engine (Part A):** `src/Dto/AssumptionSet.php` (careCostRealGrowth, returnTailDegreesOfFreedom),
  `src/Care/*` + `src/Forecast/PathProjector.php` (care escalation; expected-care in the deterministic path),
  `src/MonteCarlo/ReturnModel.php` (Student-t draw), the State-Pension uprating in `PathProjector`,
  `src/Assumptions/AssumptionSetLibrary.php` (preset figures).
- **App / presentation (Parts B/C):** `app/Forecast/ResultPresenter.php` (new chart option blobs + the
  verdict/probability presenter), `app/Livewire/Affordability.php` + `resources/views/livewire/affordability.blade.php`
  (probability-led verdict), `resources/views/livewire/scenario-results.blade.php` (tabs, tables into
  `<details>`, banners demoted, the six new chart sections), `resources/js/charts.js` (a stacked-area helper
  + nominal-axis flag if needed), `app/Http/Controllers/ScenarioPdfController.php` +
  `resources/views/pdf/partials/report.blade.php` (B5).
- **Fixtures/tests:** `tests/Support/*Fixture.php` (new assumption fields), new engine tests
  (care escalation, Student-t, triple lock) + feature tests (charts reconcile; a11y stays green).
- **Docs:** `docs/ASSUMPTIONS.md` (care real growth, tail d.o.f., triple-lock wedge — sourced + dated),
  `DATA-MODEL.md` (the new `AssumptionSet` fields), `docs/METHODOLOGY.md` (category inflation; care in the
  central path; fat tails; the earnings leg), `DECISIONS.md` (each open-question resolution), HANDOVER.
</content>
</invoke>
