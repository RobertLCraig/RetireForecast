# Research: how the cashflow-modelling sector solves this

_Captured 2026-06-25. Purpose: we are not inventing — these features are solved in UK
retirement / cashflow-planning software (Voyant, Timeline, CashCalc, Truth) and by public
sector standards (PLSA Retirement Living Standards, FCA/SMPI). This doc records how they do
it and what we adopt, so the build follows a proven shape. Every figure here that would enter
code carries a ⚠️ until sourced + `verified_on`, same as the tax figures._

> Triggered by review (2026-06-25/26): designing edit/clone/compare, line-item expenditure and
> projection drill-down surfaced gaps in our data model that are cheaper to fix now than later.

---

## 1. Scenario architecture — base plan + what-if children + compare

**How the sector does it (Voyant, the market leader):**
- Each client has ONE **base plan** = their current situation.
- **What-if plans are copies of the base plan**, amended to model options ("retire 2 years
  later", "spend £3k less", "take the lump sum", a market shock). Two creation modes: *Quick*
  (an exact copy you then amend) and *Guided* (steps you through building a specific scenario).
- **Copy-on-write inheritance:** changes to the base plan filter through to a what-if **unless
  that item has already been amended in the copy** — amending an item "breaks the link" to the
  base for that item only.
- **Compare Plans:** a side-by-side view of two plans' results with **synchronised charts**,
  shown only when more than one plan exists.

**What we have:** Household + a single `Scenario` (one housing decision). We already run the
**three housing variants (stay / buy / rent) on identical seeds** and render them side by side
— so the *comparison rendering* exists, but the *data model* has no notion of base + what-if.

**What we adopt (and the model we choose):**
- Adopt **base plan + named what-if children + Compare**. This is exactly where Rob's
  edit/clone thinking was heading; **confirmed in scope**.
- A child **overrides only 1–2 parameters** of the base — stored as a **delta**, not a full
  copy. Effective inputs = the base's `builder_state` **overlaid with the child's overrides**,
  resolved by one merge function (+ a round-trip test). Rationale: a **full copy duplicates the
  whole base into every child, so a later base fix leaves children stale — they fork** — the
  exact "same quantity stored in two places that drifts" the guardrails exist to prevent. A delta
  keeps the base single-source; a child holds only its tweaks and otherwise tracks the base. This
  is Voyant's copy-on-write ("changing an item breaks the link for that item only"). _(Corrects an
  initial full-copy lean — see DECISIONS 2026-06-25; full-copy was rejected because it forks.)_
- **Compare** = run base + child, reuse the existing variant side-by-side rendering.

**Gap this closed:** the scenario model must support **delta child what-ifs** (overrides on the
base, owner-scoped) and **compare** from the start — design the edit feature so a child falls out
of the persisted form-state, rather than retrofitting later.

---

## 2. Expenditure — line items, tiers, a benchmark, an income floor, and phases

Line items alone are just input. The sector ties **essential vs discretionary** to four things
that are the actual payoff:

**(a) Tiering — needs / wants / wishes.** Advisers split spending into **needs** (essential —
the last to go: housing, food, utilities, insurance), **wants** (discretionary — comfort,
leisure), and sometimes a third **wishes/aspirational** tier (legacy, gifting, the big trip).
Our `essential` / `discretionary` maps to needs / wants; a third tier is optional, not required.

**(b) PLSA Retirement Living Standards — the UK benchmark.** A "basket of goods" yardstick at
three lifestyles, 2025/26 ⚠️ (confirm against PLSA before coding):
| | One person | Two person |
|---|---|---|
| Minimum | £13,400 | £21,600 |
| Moderate | £31,700 | £43,900 |
| Comfortable | £43,900 | ~£60,600 |
Killer caveat for **us specifically**: the standards assume the **home is owned outright and
EXCLUDE housing costs and care**. Since our whole point is **buy-vs-rent**, a renter's housing
must be **added on top** before comparing to PLSA, or the benchmark misleads. (Also excludes
care + debt.) Cheap to adopt: a few sourced constants + a "your essentials ≈ PLSA *Moderate*"
readout.

**(c) Income floor — why the essential/discretionary split matters.** The core adviser move:
**cover essential spend with guaranteed/secure income** (State Pension + DB + annuities), fund
discretionary from flexible/invested assets. Our **"essentials always met" success measure IS
the income-floor concept.** A `essential £X vs guaranteed income £Y → floor covered / shortfall
£Z` readout reuses data we already capture and is high-signal. Requires classifying income as
**guaranteed** (State Pension, DB, annuity) vs **flexible** (drawdown, GIA) — derivable from our
existing income/pension types; define the rule explicitly.

**(d) Spending "smile" / phases (Blanchett).** Real spend **declines ~1%/yr** through retirement
("go-go" ~first 10 yrs → "slow-go" ~75–85 → "no-go" late 80s+), ticking up late for health — the
"retirement spending smile". Many tools let spend vary by phase; a flat real essential
**overstates** late-life spend. This is an **engine expense-model change** (age-varying spend),
so **defer** — flag as the next engine piece after flat line-items land.

**What we adopt now:** line-items (source of truth; essential/discretionary totals **derived as
the sum of the lines**, per the reconciliation discipline) + the **income-floor readout** + the
**PLSA benchmark**. Defer phased spending and the optional third tier.

---

## 3. Projection drill-down — deterministic cashflow ladder + Monte-Carlo success rate

**How the sector does it (confirmed the split we already have):**
- The primary walk-through is a **deterministic year-by-year "cashflow ladder"**: per year, the
  columns are **income by source** (State Pension, DB, drawdown, rental, etc.) → **tax** → **net
  income** → **expenditure** → **surplus / deficit** (the gap filled from the portfolio) →
  **asset balances** and net-worth trajectory, with key events marked (mortgage paid off, pot
  peaks, pot depletes).
- Alongside it, a **stochastic Monte-Carlo "probability of success"** (e.g. "succeeds in 78% of
  scenarios") as the risk lens.

**Framing (important for compliance):** success rate is **not pass/fail** — Kitces' point is a
"50% success" can be fine because you adjust en route. Present it as **a probability under stated
assumptions**, which also keeps it on the right side of the banned-phrase lint.

**What we have:** a `DeterministicForecaster` producing `YearResult[]` and the Monte-Carlo
`Simulator` producing the bands + success probability — we just don't surface the year-by-year.

**What we adopt:** the **cashflow ladder from the deterministic central plan** (NOT from Monte
Carlo — there is no single MC path; ⚠️ verify `YearResult` exposes per-source income / per-pension
pot before committing), each with the existing accessible-table + CSV + disclaimer pattern; the
MC success-rate stays the risk lens.

---

## 4. "What is my DC pot worth?" — projecting a current value forward

Rob's core question (Person 2 not yet retired, knows only today's value):

**How the sector does it:**
- **SMPI (Statutory Money Purchase Illustration)** — the regulated projection: grow the pot to
  retirement on a prescribed real return + annuitise on prescribed terms (GAD / Government
  Actuary's Department bases). Deterministic, single number.
- **Sustainable withdrawal rule of thumb** — "**3–4%** of the pot, inflation-adjusted" as a
  starting point. ⚠️ Our compliance rule (DECISIONS 2026-06-25) is firm: this may be stated as a
  **neutral fact/definition, never a target** (no "the safe 3–4% range") — it edges toward advice.
- Pot at retirement depends on pot size, contributions, growth (often 4–6% before inflation),
  charges, lifespan, spend, inflation.

**What we have / adopt:** our Monte-Carlo already projects the pot forward (current value +
contributions + growth → pot at access age → drawdown) — *richer* than SMPI's single rate. The
**per-pension readout**: `current £X → projected pot at access age (median + p10–p90) → indicative
sustainable income`. **Defer** an annuity-equivalent (needs new mortality-based pricing math).
SMPI/GAD bases are a useful sourced sanity-check for the projection.

---

## Gaps this research surfaced (catch before building)

1. **Scenario data model** must support **clone + compare** (base/what-if), not just single
   scenarios — design it into the edit feature now.
2. **Expenditure** is flat (essential/discretionary totals); the sector does line-items + an
   **income floor** + a **PLSA benchmark** + (later) **phased spend**.
3. **Income** must be classifiable **guaranteed vs flexible** for the floor — define the rule.
4. **PLSA benchmark vs our buy-vs-rent**: PLSA assumes outright ownership / excludes housing —
   must add the housing leg before benchmarking, or it misleads.
5. **Drill-down**: the ladder is **deterministic**; verify `YearResult` exposes the per-source /
   per-pension detail the columns need (may be a small engine addition).

## Sources

- Scenario / what-if architecture: [Voyant — How to add a what-if plan (UK)](https://support.planwithvoyant.com/hc/en-us/articles/360043396452-How-to-add-a-what-if-plan-UK), [Voyant — Compare Plans, List View](https://support.planwithvoyant.com/hc/en-us/articles/360055259971-Compare-Plans-List-View-Show-a-side-by-side-comparison-of-plan-results-including-account-balances), [pyrford fp — how cashflow tools work + limits](https://www.pyrfordfp.com/post/why-we-don-t-rely-on-cashflow-software-when-planning-a-sustainable-retirement-income)
- PLSA Retirement Living Standards: [PLSA](https://www.plsa.co.uk/Press-Centre/News/Article/PLSA-launches-Retirement-Living-Standards), [Loughborough University 2025](https://www.lboro.ac.uk/news-events/news/2025/june/cost-of-retiring-falls-calculated-loughborough/), [Pension Bible guide](https://www.pensionbible.co.uk/guides/plsa-retirement-living-standards)
- Tiering needs/wants/wishes: [Thrivent — needs, wants & wishes](https://www.thrivent.com/insights/budgeting-saving/categories-of-spending-understanding-needs-wants-and-wishes)
- Income floor: [Boldin — income floor](https://www.boldin.com/retirement/establishing-retirement-income-using-an-income-floor/), [SmartAsset — flooring approach](https://smartasset.com/retirement/flooring-approach-to-retirement-income-planning)
- Spending smile / phases (Blanchett): [Kitces — Monte Carlo & success](https://www.kitces.com/blog/monte-carlo-retirement-projection-probability-success-adjustment-minimum-odds/), [WealthKeel — spending smile](https://wealthkeel.com/blog/retirement-spending-smile-curve/)
- Cashflow ladder structure: [Income Lab — retirement cash-flow planning guide](https://incomelaboratory.com/retirement-cash-flow-planning-guide/)
- DC projection / SMPI / sustainable income: [Nest — SMPI assumptions](https://www.nestpensions.org.uk/schemeweb/dam/nestlibrary/smpi-assumptions.pdf), [Money to the Masses — sustainable drawdown](https://moneytothemasses.com/saving-for-your-future/pensions/what-is-a-sustainable-income-that-you-can-drawdown-from-your-pension)
