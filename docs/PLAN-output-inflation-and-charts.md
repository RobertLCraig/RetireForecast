# PLAN — output legibility, category inflation, and the missing time-series charts

> **Status: DRAFT spec, ready for a fresh agent. Nothing here is built yet.** Dated 2026-07-18,
> written from a code-grounded adversarial review of the engine outputs and the Blade output layer
> (two mapping passes over `packages/finance-engine/src` and `resources/views`). Build in green,
> committed slices per the build order; honour the reconciliation/completeness bar (CLAUDE.md).
> Each decision below carries its reasoning and a research link — keep that discipline when you build
> (every figure lands in `docs/ASSUMPTIONS.md` / a `TaxYear` record with a `source` + `verified_on`,
> not as a magic number). **The six original open questions are now resolved** (2026-07-18 research pass) by
> Rob's standing rule: research the industry figure, default to the **most adverse** where several are
> defensible, and expose every one as a **user-editable UI control** with the sourced alternatives
> ([[adverse-default-user-editable]]). See "Decisions resolved by research". One residual judgement flag
> (the A4 State-Pension default) is surfaced there for Rob to overrule at build time if he wishes.

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

**Resolved (2026-07-18 research) — default CPI + 2% real, long-run; user-editable.** Care fees are ~60–75%
staff cost pinned to the National Living Wage, which government ratchets deliberately above prices; PSSRU/LSE
and OBR long-term social-care projections escalate care unit costs on **earnings/productivity (~2% real above
CPI)**, not CPI, and median care-worker pay is up ~23% real since 2015/16. The recent ~10%/yr (≈CPI+4–5%)
run-rate is an NLW + employer-NI spike, **not** a standing assumption. Defensible standing range 1.5–3% real;
most adverse of the plausible standing values → **CPI + 2%** default, with a time-limited "care-cost shock"
(CPI+4–5% for the first few years) offered as an option. Sources:
[King's Fund Social Care 360](https://www.kingsfund.org.uk/insight-and-analysis/long-reads/social-care-360-expenditure),
[PSSRU/LSE Wittenberg long-term care projections](https://eprints.lse.ac.uk/88376/1/Wittenberg_Adult%20Social%20Care_Published.pdf),
[cashflow-planning inflation guidance](https://www.truthsoftware.co.uk/cashflow-assumptions-inflation/).

### A2. Put an expected care cost in the deterministic path

**Finding.** Care is **Monte-Carlo-only** (`docs/METHODOLOGY.md` "Care costs"). But the Affordability
verdict and the central cashflow ladder run the **deterministic** projection, which contains **no care at
all**. So the plain-English "do the essentials last for life? **Yes**" — built specifically for the elder
couple who can't read the fans — is computed on a path that omits their biggest late-life expense.

**Why it's wrong.** It's not just inaccurate, it's *falsely reassuring* for the least numerate reader —
the worst failure mode for this tool.

**Resolved (2026-07-18 research) — an explicit care-stress variant, ON by default, beside a clearly-labelled
care-free base; NOT expected-value averaging.** This is what the FCA frame and the professional cashflow
tools (Voyant, CashCalc, Timeline) do: care is a user-toggled late-life stress scenario shown *alongside* the
base, never a small probability-weighted amount smeared into the central line. Averaging a severely
right-skewed tail (most people little/no care; ~1 in 10 face >£100k) is both unrealistic — almost nobody
experiences the average — and *falsely reassuring*, understating the very person in the tail the projection
exists to protect. So: (1) keep the central line care-free but **labelled** ("assumes no residential care");
(2) render, adjacent and visible by default, a **care-stress scenario** on the adverse parameters below ("if
you need N years of nursing care at £X/wk from age Y, your money lasts until Z"); the Affordability verdict
must never read "Yes, lasts for life" beside a silently care-free path. A probability-weighted "typical
outcome" view is offered as an *option*, captioned as **not** a safety margin. Sources:
[FCA cashflow-modelling guidance](https://www.fca.org.uk/firms/undertaking-cashflow-modelling-demonstrate-suitability-retirement-related-advice),
[Voyant plan settings](https://support.planwithvoyant.com/hc/en-us/articles/360044947012-About-Plan-Settings).

**Care parameters — adverse defaults (feed both the care-stress variant and the existing MC care model).**
The Monte-Carlo care *probability* is already sex-differentiated (committed `21e0efe`); these set the
*adverse* fee / duration / onset defaults, each user-editable:

| Parameter | Adverse default | Central alternative | Source |
|---|---|---|---|
| Nursing self-funder fee | **£1,800/wk** (top-decile / dementia-nursing) | £1,594/wk national avg | LaingBuisson 35th ed (Feb 2025) |
| Residential self-funder fee | **£1,300/wk** | £1,278/wk national avg | LaingBuisson 35th ed |
| Spell length | **~4 yr** (upper tail) + an 8–10 yr long-stay option | ~2.5 yr (mean) | PSSRU (mean 29.7m); BUPA (~27% >3 yr) |
| Prob. of a residential/nursing spell | **~50%+ (women's end)** | ~33% blended | AU/US lifetime-admission analogues |
| Real fee escalation | **CPI + 2%** (per A1) | CPI + 1% | see A1 |
| Regional loading | London/SE **+25–35%** available | national avg | LaingBuisson regional spread |

Current tool fees (£1,300 residential / £1,600 nursing) are confirmed accurate for 2025
([LaingBuisson 35th ed](https://www.laingbuisson.com/press-releases/one-in-seven-independent-nursing-homes-charge-over-1800-a-week-to-new-admissions-as-increases-to-the-national-living-wage-uplifts-and-employers-national-insurance-contributions-drive-care-h/));
nudge to ~£1,400 / ~£1,750 for 2026/27 at the observed ~10%/yr. The UK lifetime *care-home admission*
probability is proxied from AU/US analogues (no clean UK headline exists) — caveat it in the UI.

### A3. Fat-tailed returns (the MC left tail is optimistic)

**Finding.** MC returns are drawn from a **normal** distribution (`ReturnModel.php`), and inflation is
drawn **independently** of returns. Both cut the same way: they understate the probability of the bad
states where depletion happens.

**Why it's wrong.** Gaussian Monte Carlo is documented to *underestimate failure rates by ~10–17
percentage points* versus fat-tailed / historical-bootstrap analysis; real equity markets produce 30%+
drops far more often than a normal predicts. A headline "90% success" from a thin-tailed world is not a
90% from the world that produced 1973–74 or 2008.
- [Kitces — fat tails vs safe withdrawal rates](https://www.kitces.com/blog/monte-carlo-analysis-risk-fat-tails-vs-safe-withdrawal-rates-rolling-historical-returns/), [Quant Decoded — when Monte Carlo fails](https://quantdecoded.com/en/when-monte-carlo-fails-retirement-planning-pitfalls), Blanchett, Finke & Pfau (2017) "Planning for a More Expensive Retirement".

**Target shape.** `AssumptionSet::returnTailDegreesOfFreedom` (`?int`) + a negative-skew parameter.
`ReturnModel` draws asset shocks from a **Student-t** (scaled to preserve the target volatility), so lower
d.o.f. → heavier tails. The field stays nullable **for back-compat with stored runs** (null = normal, so old
snapshots reproduce byte-identically and a user can pick Normal), but — per the adverse-default rule — the
**shipped presets set d.o.f. = 3**, so a new forecast is fat-tailed by default.

**Resolved (2026-07-18 research) — default fat tails ON: Student-t, d.o.f. 3 ("severe") + negative skew,
Cholesky-correlated; plus a stagflation coupling.** A plain Gaussian is the acknowledged *convenience*
baseline the whole tail-risk literature exists to correct; the NAIC/Academy equity economic-scenario-generator
"stylised facts" require fat tails, negative skew and volatility clustering, and retail analogues (Retirement
Lab) ship Student-t d.o.f. 3 "severe" / 5 "moderate". Empirical equity-return d.o.f. sits at ~3–7 (the large
negative left tail ~2–5). Most adverse defensible → **d.o.f. 3 + negative (Fernandez–Steel) skew**.
- **A3b — stagflation coupling (the current independent-inflation draw is the weakest link for a UK tool).**
  The UK's worst real-return decade (1970s) was inflation-driven; drawing inflation independently of real
  returns makes joint stagflation near-impossible and understates sequence risk. Draw inflation **jointly,
  negatively correlated with real returns (~−0.3 to −0.5)** so stagflation can occur, and let the stock–bond
  correlation move positive in high-inflation draws (diversification fails exactly when inflation is high).
- **Honest caveat (must be in the UI copy).** i.i.d. fat-tailed draws omit **mean reversion**; Kitces argues
  this makes i.i.d. MC *overstate* multi-year catastrophe. A **block bootstrap** (3–5-yr blocks) captures
  *both* empirical fat tails and serial dependence and is the academic favourite — offer it as the "honest
  middle" and show the same plan under multiple engines side-by-side so the reader sees the spread.
- **Effect:** ~+10–17pp higher 30-yr failure at a 4% withdrawal vs the current Normal — this is *why* the tool
  defaults pessimistic. Sources:
  [Kitces — fat tails vs SWR](https://www.kitces.com/blog/monte-carlo-analysis-risk-fat-tails-vs-safe-withdrawal-rates-rolling-historical-returns/),
  [NAIC/Academy equity-ESG stylised facts](https://content.naic.org/sites/default/files/inline-files/ESG%20Stylized%20Facts%20for%20Equity%20(final)%20(3).pdf),
  [Baltussen et al., stagflation regimes (FAJ 2023)](https://www.tandfonline.com/doi/full/10.1080/0015198X.2023.2185066),
  [Retirement Lab methodology](https://retirement-lab.com/how-it-works/).

### A4. State Pension uprating — the current rule is neither faithful nor adverse

**Finding.** The tool uprates the State Pension by `max(CPI, 2.5%)` (`docs/METHODOLOGY.md`). That is **not**
the most adverse option and it is not the faithful one either: long-run, the **earnings** leg (dropped here)
usually binds, while the 2.5% floor makes the current rule *more* generous than pure CPI in low-inflation
years. So it sits awkwardly between the two.

**Why it matters + what "most adverse" means here (⚠️ the one place adverse ≠ current law — Rob please
note).** For a household *relying on* State Pension income, **lower uprating is the adverse case**. UK
long-run projections assume positive real earnings growth, so the ranking most-generous→most-adverse is
`full triple lock ≥ earnings+wedge ≥ double lock ≥ earnings-only ≥ CPI-only`. Per the adverse-default rule
the default is therefore **CPI-only (a prices link)** — the lowest defensible long-run uprating. **Caveat
(must be in the UI copy):** earnings-linking is the current *statutory minimum* (Pensions Act 2014 s.5), so a
CPI-only default assumes a future government legislates the earnings link away — a genuine policy risk (the
OBR baseline still assumes the full triple lock continues, and warns it drives over half the projected
State-Pension-cost rise to the 2070s), but a departure from *today's* law. If you would rather the default
respect current law, the most-adverse **legally-consistent** choice is **earnings-only**. Recommend CPI-only
as the adverse default with earnings-only offered as the "legal floor" option.

- Faithful modelling note: the truest representation of the *actual* triple lock in a real-earnings framework
  is **earnings + a wedge** — OBR puts the wedge at **~0.58pp/yr** (data since 1993) or **~1.04pp/yr** (since
  2011); IFS finds moving triple→double lock changes little long-run because earnings usually binds.
- Sources:
  [OBR Fiscal Risks & Sustainability (Jul 2026)](https://obr.uk/frs/fiscal-risks-and-sustainability-july-2026/),
  [IFS R272 — triple lock costs & uncertainty](https://ifs.org.uk/sites/default/files/2023-09/R272-The-triple-lock-costs-and-uncertainty.pdf),
  [IFS — triple vs double lock does little long-run](https://ifs.org.uk/articles/moving-triple-double-lock-does-little-long-run-state-pension-affordability),
  [House of Commons Library — the triple lock](https://commonslibrary.parliament.uk/the-triple-lock-how-will-state-pensions-be-uprated-in-future/).

**UI options (most-generous → most-adverse):** full triple lock `max(earnings, CPI, 2.5%)` · earnings + wedge
(0.58pp / 1.04pp) · double lock `max(earnings, CPI)` · earnings-only (legal floor) · **CPI-only (adverse
default)**.

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
"honesty gap" note). Show the deterministic path as the *expected* case beside a plain-word probability band,
and surface the care panel next to it (ties to A2).

**Resolved (2026-07-18 research) — conservative word bands; reserve green/"on track" for ≥80%.** The industry
benchmark is MoneyGuidePro's **Confidence Zone (70–90%)**; the adviser norm (Kitces) treats ~70% as the
*floor* of "acceptable," not "comfortable." Per the adverse rule, anchor the "on track" floor higher (80%) so
a coin-flip lands firmly in "at risk." Follow Kitces' reframing: present it as a **probability of needing to
adjust**, not "chance of running out," and always pair it with the shortfall size and the guaranteed-income
floor (State Pension + any DB/annuity).

| Success probability | Plain-English band | Colour | Meaning for the couple |
|---|---|---|---|
| **≥ 90%** | Very secure | Green | Highly likely to last; you may even be able to spend a little more or leave more behind. |
| **80–89%** | On track | Green | Holds up well in the large majority of futures — the target zone. |
| **70–79%** | Broadly on track — keep under review | Amber | Usually fine, but a bad run of years could need modest spending cuts. Revisit yearly. |
| **50–69%** | At risk | Orange/Red | Too close to a coin-flip. Likely to need real changes — lower spending, or lean on guaranteed income. *(55% lands here.)* |
| **< 50%** | Unlikely on the current plan | Red | More likely than not to fall short as planned. Needs a rethink now. |

Sources: [Envestnet MoneyGuide Confidence Zone](https://soundmindinvesting.com/articles/will-your-retirement-nest-egg-last-how-to-use-moneyguidepro-to-find-out),
[Kitces — reframing retirement risk as over/under-spending](https://www.kitces.com/blog/retirement-income-risk-monte-carlo-probability-sucess-over-under-spend/),
[Schwab — stress-testing your plan](https://www.schwab.com/learn/story/stress-testing-your-retirement-plan).

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

## Decisions resolved by research (2026-07-18)

The six original open questions were resolved by Rob's standing rule — **research the industry figure; where
several are defensible, default to the most adverse; expose every one as a user-editable UI control with the
sourced alternatives** ([[adverse-default-user-editable]]). The per-item sections above hold the reasoning +
sources; this table is the consolidated **builder-control spec** (default = the shipped preset; alternatives =
the selectable options). Every figure gets a `source` + `verified_on` stamp in `ASSUMPTIONS.md` when built.

| Assumption | Adverse default (shipped) | User-selectable alternatives |
|---|---|---|
| Care real inflation (A1) | **CPI + 2%** | CPI+0 / +1 / +3; time-limited "care shock" CPI+4–5% |
| Care in the forecast (A2) | **Care-stress variant ON**, beside a labelled care-free base | care-free only; probability-weighted "typical" (captioned) |
| Care fees / spell (A2) | **£1,800 nursing / £1,300 residential /wk, ~4 yr, ~50%+ incidence** | national-avg fees; ~2.5 yr; ~33% incidence; 8–10 yr long-stay; regional loading |
| Return distribution (A3) | **Student-t d.o.f. 3 + negative skew** | Normal; t(5) moderate; block bootstrap; (later) regime-switching |
| Inflation coupling (A3b) | **Joint, −0.3…−0.5 vs real returns** (stagflation possible) | independent (current); regime-switching |
| State Pension uprating (A4) | **CPI-only** (⚠️ see residual flag) | full triple lock; earnings+wedge; double lock; earnings-only |
| Probability wording (B1) | **Green/"on track" only ≥80%; 50–69% = "At risk"** | (fixed bands; see B1 table) |

**Residual judgement flag for Rob (the only one that isn't a pure figure):** the **A4 State Pension default**
is the one place "most adverse" departs from *current law* — CPI-only assumes a future government repeals the
statutory earnings link. Per the rule I've set **CPI-only** as the adverse default, with **earnings-only**
offered as the "respects today's law" option. If you'd rather the default not presume a law change, switch the
default to earnings-only; either way both are selectable. Flag noted so you can overrule at build time.

**Scope / build order** (resolved): Part A ships independently and first; Parts B/C are a separable UX
workstream. Recommended order is in "Build order" below (A1/A2 → C1/C2/C3 → B1 → rest).

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
