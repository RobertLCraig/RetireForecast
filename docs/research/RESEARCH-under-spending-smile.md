# Research: how to represent age-varying retirement spend (the "smile")

_2026-07-06. Prompted by promoting phased spend to the next engine piece (PLAN "The under-spending
case"). Rob chose the **per-line-item** scope and delegated the **representation** to research. This
records the industry norm, the sources, and the decision the engine implements._

## Why it matters (accuracy, not fidelity)

Real retirement spend does not stay flat in real terms — it declines through most of retirement and
ticks up late for health. Modelling a flat real spend to death therefore **understates how much can
be safely spent early**, which manufactures the very under-spending the source case study (James
Shack's "Mark") warns about, and it **biases the buy / rent / downsize verdicts** the tool exists to
compute. So the smile is a correctness item, not a nicety. `ExpenseProfile` previously held one flat
real essential + discretionary figure with no age-banded path anywhere in the engine.

## The evidence base — Blanchett's "retirement spending smile"

David Blanchett's 2014 research: real spending declines on average ~1%/yr, reaching a **trough ~26%
below the starting level at ~age 84**, then rises again late (not necessarily above the start until
the mid-90s) as **rising health costs offset falls in other categories**. Early retirement is
travel/eating-out heavy (go-go); the middle slows (slow-go); the end rises on care (no-go). Kitces'
own decomposition: ~1%/yr in the first decade, ~2%/yr in the second, ~1%/yr in the last.

- Retirement Researcher — the smile numbers: https://retirementresearcher.com/retirement-spending-smile/
- Kitces — total spending declines over time: https://www.kitces.com/blog/estimating-changes-in-retirement-expenditures-and-the-retirement-spending-smile/

## How the market *represents* it

No single universal form, but for a **per-line-item** model (Rob's scope) they converge on
**per-item, age-bounded amounts** — i.e. piecewise breakpoints per line:

| Source | Representation | Note |
|---|---|---|
| **Voyant** (UK adviser market-leader) | Stepped expenditure — each expense item has start/end ages | Per-item breakpoints; "life in retirement" steps down, "care" steps in. https://planwithvoyant.com/uk |
| **Kitces / Basu "age banding"** (most accurate) | Per **category** decline schedules | Captures *composition shift* — travel/leisure fall, healthcare rises; the aggregate smile is their sum. ~10%/decade, middle decade deeper. https://www.kitces.com/blog/age-banding-by-basu-to-model-retirement-spending-needs-by-category/ |
| **RightCapital** | 3 phases (go-go/slow-go/no-go): start age + % adjustment each | A convenience *template* over breakpoints, applied to the goal. https://help.rightcapital.com/article/models_retirement_spending/ |
| **Blanchett** | A %/yr real-decline curve at the aggregate level | The underlying evidence, not a per-line editor form. |

The UK market-leader (Voyant, per-item start/end ages) and the most-accurate academic method (Basu,
per-category schedules) both land on **per-item age-bounded amounts**. RightCapital's phases and
Blanchett's %/yr curve are convenience layers / approximations *over* that same primitive.

## Decision — a piecewise-real `SpendPath` of `{fromAge, amount}` bands, one per line

Store each line's path as a piecewise-constant **real** breakpoint list. It is a strict superset of
every form above (flat = 1 band; Mark's £60k→£40k@75 = 2; go-go/slow-go/no-go = 3; a Basu
per-category schedule = however many; a Blanchett curve = a band per year), it is exactly what the
future "hand-draw the smile" editor emits, and convenience templates (%/yr, phases) compile *down to*
bands, so the store stays general and reconciliation-clean (`amountAt` is a pure lookup; an aggregate
path is the exact per-age sum of its line paths).

Per-line scope **subsumes "discretionary only"**: essentials that stay flat carry no band;
discretionary that fades carries a declining one — the accurate choice because it captures the
composition shift, not just an aggregate fade. The late-life rise is **care**, already modelled
separately (`CareCostSampler`), so the engine models the down-slope. **Only an `always`-condition
line may smile** (a mortgage / service charge / commute is flat and stops by its condition).

Implementation + guards: see DECISIONS 2026-07-06 ("Age-varying spend (the smile)") and DATA-MODEL
"ExpenseProfile". Engine layer built slices 1–4 (`SpendPath`, `ExpenseProfile`, `PathProjector`,
`HouseholdAssembler`); the builder UI + result surfacing follow.

## Deferred / v1 limits

- Bands key off the **reference (first-declared) person's age** (same convention/limit as one-off
  costs — a couple with very different ages tracks person 1).
- **Contingent costs don't smile** (mortgage / service charge / commute are flat; a band on them is
  ignored).
- The **hand-draw-the-smile editor** and **phase/%-per-year templates** are UI conveniences over the
  same band store (not yet built — the builder v1 is a per-line band table).
- The sibling under-spending items (a **lifetime-gifting lever**, an **under-spender headline
  reframe**) remain in PLAN "The under-spending case".
