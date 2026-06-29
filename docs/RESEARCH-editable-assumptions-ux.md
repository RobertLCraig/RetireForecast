# Research: editable assumptions, buy-vs-rent presentation, and cost breakdowns — how existing tools do it

_2026-06-29. Prompted by Rob's browser-pass feedback: "make all thresholds/assumptions editable in the website"
(investment growth, age of death), "move buy-vs-rent into clear what-if scenarios rather than baked into the single
report", show selling costs as real figures with a breakdown, and "do a bit more research on how this is handled and
shown by existing applications — are there any free ones we can look at?"_

This complements [RESEARCH-cashflow-modelling.md](RESEARCH-cashflow-modelling.md) (the **adviser** sector — Voyant /
Timeline / CashCalc). This note is about **consumer / free** tools and the specific UX of *editable assumptions*,
*buy-vs-rent*, and *cost breakdowns*. Findings, then what to adopt.

## Free tools worth actually looking at (and what each does well)
| Tool | Free? | Look at it for |
|------|-------|----------------|
| **Guiide** (guiide.co.uk) | Free | The closest UK peer — pensions + State Pension aware, stress-testing, "design a happy retirement". UK tax framing. |
| **Boldin** (boldin.com, was NewRetirement) | Free "Basic" tier | Editable assumptions + **set your own goal age and model 85 vs 95 side by side**; structured guided entry; Monte Carlo. The reference for *longevity scenarios + scenario comparison*. |
| **ProjectionLab** (projectionlab.com) | Free tier (limited) | **Change an input, watch the projection update in real time**; Sankey cash-flow; backtesting. The reference for *live-update editable assumptions*. |
| **NYT "Is it better to rent or buy?"** | Free | The reference for **buy-vs-rent as a focused comparison**: editable home-price growth, rent growth, inflation, investment return, and a full **cost breakdown** (closing, broker, maintenance) in an *advanced-settings* panel with sensible defaults. |
| **Actuaries Longevity Illustrator** (longevityillustrator.org) | Free | **Joint-life** longevity for couples — "how long as a couple?" and "by how many years might one outlive the other?" Probabilistic, educational. Validates our last-survivor cohort approach + a good framing to borrow. |
| **Honest Math** | Free | Transparency ethos — *every* assumption is changeable, black-swan events addable. |
| **cFIREsim / FIRECalc** | Free, open | Monte Carlo with very editable assumptions + historical backtesting. |

## Patterns to adopt
1. **Sensible *sourced* defaults, every key assumption overridable.** Every tool ships defaults (NYT inflation 2%,
   growth sliders; Boldin/ProjectionLab return + inflation) but lets the user change all of them. Honest Math's whole
   pitch is "transparent = every assumption changeable". → Keep our sourced presets (FCA / DMS / OBR) as **starting
   points**, but let the user tweak individual figures into a **derived "custom" set** (growth, inflation, house/rent
   growth, selling-cost components, age of death). This is exactly Rob's "make all thresholds/assumptions editable".
2. **An *advanced-settings* / assumptions panel.** Keep the basic flow simple; put the detailed editable assumptions
   behind an accessible panel (NYT's "don't overlook the advanced settings"). We already render a **read-only**
   assumptions panel (built 2026-06-29) — the next step is to make it (or a linked editor) **editable**.
3. **Real-time update.** Change an assumption → the projection moves immediately (ProjectionLab). We have Livewire
   reactivity + a cheap deterministic preview, so this is feasible — and it converges with workstream **#7 (real-time
   cost toggles)**.
4. **Age of death = a "plan-to age" you can set and stress-test.** Norm default ~90–95 (in a 31k-plan study, 70% used
   90, 20% used 95); per person, and joint for couples; the recommended practice is **two plans (e.g. 85 vs 95)**, not
   one. → We are already *ahead* here: ONS **cohort mortality** (a real distribution, better than a flat "age 90") +
   a per-person **longevity lever** (peer / fixed-age / ±years) + now the **modelled death year on screen**
   (`deathCalendarYears`). The gap is **UX**: make the lever obvious and editable, show the resulting age, and make
   "what if you live to 95?" a one-click stress test (our delta-child what-ifs already support this).
5. **Buy-vs-rent as a deliberate, focused comparison — not baked into every report.** NYT treats it as its own tool
   with its own editable assumptions + cost breakdown. → Matches Rob's ask: the primary report should focus on **one
   chosen strategy**, with buy-vs-rent offered as an explicit **what-if / Compare** (we already have delta-child
   scenarios + a Compare view). **Prerequisite: the contingent-cost fix (#1)**, or the comparison is dishonest
   (phantom mortgage/commute) — see DECISIONS 2026-06-29.
6. **Costs as editable line items, shown in £.** NYT breaks closing / broker / maintenance into editable fields with
   defaults. → Decompose our **selling costs** (currently one 2% rate) into **estate agent (~1–1.5%) + legal/
   conveyancing (~£1.5k) + EPC/removals**, each editable with a sensible default, all shown in £; SDLT + CGT stay
   auto-computed from the engine; moving costs editable. (Interim done 2026-06-29: the label now names what the 2%
   covers and shows the £.)

## How this maps to Rob's decisions (2026-06-29)
- **#1 contingent-cost placement → option (b):** auto-classify each expense line by category/label (mortgage,
  service charge, ground rent → *while owning the home*; commute → *while working*; everything else → *always*) with a
  **per-line override** in the builder. Matches the universal "sensible default + override" pattern.
- **"Everything editable":** introduce a user-editable **custom assumption set** (derived from a preset) + surface the
  longevity lever + cost components as editable, with live preview. Big, so phase it (below).
- **Buy-vs-rent → what-if:** after #1, make it a deliberate Compare rather than three always-on variants.

## Suggested sequencing (for Rob to confirm)
1. **#1 contingent-cost placement (option b)** — the correctness fix; unblocks an honest buy-vs-rent. Engine +
   data-model + auto-classify + per-line override. Highest value (it changes the numbers).
2. **Per-variant deterministic ladder (#6)** — now shows *correct* per-strategy numbers; pairs with #1.
3. **Editable assumptions layer** — custom set derived from a preset (growth, inflation, house/rent growth), the
   longevity lever surfaced + the modelled age shown, cost components decomposed + editable, with live preview.
4. **Buy-vs-rent as a deliberate Compare/what-if** + the per-option narrative (#5), milestone-anchored.

Each stays education/guidance only (the banned-phrasing lint) and carries reconciliation tests.

## Sources
- [Rob Berger — best retirement calculators](https://robberger.com/best-retirement-calculators/) ·
  [ProjectionLab](https://projectionlab.com/) · [Boldin vs ProjectionLab](https://www.boldin.com/retirement/boldin-vs-projectionlab/)
- [Boldin — longevity & life-expectancy calculators](https://www.boldin.com/retirement/longevity-trends-and-life-expectancy-calculators/) ·
  [Actuaries Longevity Illustrator](https://www.longevityillustrator.org/) ·
  [Kitces — life-expectancy assumptions for singles/couples/survivors](https://www.kitces.com/blog/life-expectancy-assumptions-in-retirement-plans-singles-couples-and-survivors/)
- [Guiide (UK)](https://www.guiide.co.uk/) · [NYT rent-vs-buy (via Get Rich Slowly)](https://www.getrichslowly.org/the-new-york-times-rent-vs-buy-calculator/)
