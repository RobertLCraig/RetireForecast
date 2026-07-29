# PLAN: "Available capital" and "monthly allowance" — the two figures a reader actually wants

> A ready-to-build spec. A fresh agent should be able to execute this end to end. Read
> `docs/HANDOVER.md` first (orient), then this. Follows the project doc standard and the engine rules
> in `CLAUDE.md` (integer pence, one definition per quantity, reconciliation invariants, tests green).

**Stage:** DRAFT — scoped, not built. Deferred while a concurrent session lands Pension Credit bug fixes.
**Owner lane:** output legibility (sibling of [PLAN-output-inflation-and-charts.md](PLAN-output-inflation-and-charts.md)'s B-series).
_Last updated: 2026-07-29 (scoped; no code changed)_

## Why this exists
Rob, 2026-07-29: *"we really need to highlight 'Available capital' and 'budgeted monthly allowance' for
each year, for each scenario, to compare how much they should plan to be able to spend."*

It arose from the park-home work, where the question "what annual holiday budget can they afford?"
turned out to be **the output of the exercise, not an input to it** — and the tool cannot currently
answer it. It generalises well beyond that one option: it is the single most useful thing this tool
could tell a non-financial reader, and the household it is built for (see the private V2 doc) is
exactly that reader.

## The gap
Everything the tool reports is **annual**, and the two figures a person actually plans against are
**capital in hand** and **money per month**. Specifically:

1. **There is no monthly figure anywhere.** The cashflow ladder is annual
   (`ResultPresenter::ladder()`), as are the charts, Compare and the PDF.
2. **"Available capital" does not exist as a concept.** The nearest is the ladder's `usableWealth`,
   which is `liquidWealth + pensionWealth` — and that is **an overstatement**: it counts a £100,000
   pension as £100,000 of available capital when drawing it is taxable, so it is worth perhaps
   £75,000–£85,000 in the hand. Reported at face value beside cash, it flatters every pension-heavy
   plan. (Flag as a correctness issue in its own right — see "Correctness note" below.)
3. **Net worth is actively misleading for this purpose.** `totalWealth` includes home equity, which
   you cannot spend without moving. A reader comparing plans on net worth is comparing the wrong
   number — the park-home option makes this vivid (high spendable cash, collapsing home value).
4. **`spendTarget` is an input echoed back, not an answer.** In a year with unmet spend the plan
   "targets" money it does not have, so the target overstates what they can actually spend.

## Definitions (get these right; they are the whole deliverable)
One definition, one home, derived from `YearResult` — never recomputed in a view.

### Available capital (per year, per scenario)
| Figure | Definition | Why |
|---|---|---|
| **Available now** | `liquidWealth` (cash + GIA + ISA) | Spendable this year without tax on withdrawal. **This is the headline.** |
| **Available if drawn** | `pensionWealth`, shown **separately and labelled taxable** | Accessible but not equivalent to cash. Never silently added to the line above. |
| *(excluded)* | home equity | Not spendable while they live there. State the exclusion in the caption — its absence is the point. |

### Monthly allowance (per year, per scenario)
| Figure | Definition | Why |
|---|---|---|
| **Total monthly allowance** | `(spendTarget − unmetSpend) ÷ 12` | What the plan can actually **fund** that year. In a shortfall year this is below target — which is the honest number and the one `spendTarget` gets wrong. |
| **of which essential** | `min(essentialSpend, funded) ÷ 12` | The floor: bills, food, housing. |
| **of which free to choose** | `(funded − essential) ÷ 12` | **The holiday/cruise budget.** This is the figure the park-home exercise was raised to find. |

### Sustainable monthly allowance (one headline per scenario)
The flat monthly discretionary spend the plan can carry **for life** without running short — a solve,
not a read. **Reuse `LeverThresholdService` / `ThresholdRunner`**, which already does exactly this kind
of "how far can we go?" search; do not write a second solver. One figure per scenario, shown on Compare
and on the affordability screen.

## Real vs nominal — non-negotiable
Every figure is **real (today's money)**, like the rest of the engine's output. A "£412/month allowance
in 2049" means *£412 of today's spending power*, and a reader will assume cash-of-the-day unless told.
**Label every monthly figure "in today's money"** at the point of display, not in a footnote. (The
nominal toggle is still deferred — see PLAN-output-inflation-and-charts.md.)

## Changes
Presenter + Blade only. **No engine change** — every input already exists on `YearResult`.

### `ResultPresenter`
- Extend the `ladder()` row with `availableCapital`, `pensionCapital`, `monthlyAllowance`,
  `monthlyEssential`, `monthlyFree`.
- **Divide once.** Compute monthly pence as `intdiv(annualPence, 12)` from the SAME annual figure the
  ladder prints; never format an annual string and re-parse, and never let ×12 disagree with the annual
  row by more than the rounding remainder. This is the data-integrity rule applied to a derived unit.
- New `spendableSummary(ForecastResult): array` — the per-scenario headline block (available capital
  now, monthly allowance now, monthly allowance in the survivor years, sustainable monthly allowance).

### Views
- **Results:** a compact two-figure block near the top ("You'd have £X available, and about £Y a month
  to live on"), plus the new columns in the ladder's `<details>` table.
- **Compare:** an "available capital / monthly allowance" pair per scenario — this is the direct answer
  to *"compare how much they should plan to be able to spend"*, and the main deliverable.
- **Affordability (`/afford`):** the sustainable monthly allowance beside each existing verdict; this
  screen is already written for the least-numerate reader and is the natural home for it.
- **PDF:** mirror the results block (print must not drift from screen — the existing rule).

## Tests / guards
- **Reconciliation:** for every year, `monthlyEssential + monthlyFree == monthlyAllowance`, and
  `monthlyAllowance × 12` equals the funded annual spend to within the rounding remainder (<12p).
- **Shortfall honesty:** in a year with `unmetSpend > 0`, the monthly allowance is **strictly less**
  than `spendTarget ÷ 12`. This is the defect being fixed — pin it.
- **Availability:** `availableCapital` equals `liquidWealth` exactly and **excludes** home equity —
  assert against a scenario with a large home and small savings, where the two differ by a lot.
- **Pension is never silently added** to available capital (assert they are separate keys and that the
  headline does not equal `usableWealth`).
- **Cross-surface:** results, Compare, `/afford` and the PDF all show the same figure for the same
  scenario/year — built from one presenter call, per the displayed-figure-provenance rule.

## Correctness note to raise separately
`usableWealth = liquid + pension` (ladder, burndown chart, the below-floor safety check) treats
pre-tax pension money as cash. That **overstates available capital and understates the depletion
risk** for pension-heavy plans, and it also drives the "below buffer floor" warning, so the warning
fires later than it should. Not fixed by this plan (it would move existing reported figures and needs
its own decision on how to net the tax). **Add to DATA-MODEL "Known divergences" when this is built**,
and do not reuse `usableWealth` for the new "available capital" figure.

## Build order
1. Presenter figures + reconciliation tests (nothing visible yet).
2. Results block + ladder columns.
3. Compare pair — the main deliverable.
4. `/afford` sustainable allowance (reuses `LeverThresholdService`).
5. PDF mirror.

Steps 1–3 answer Rob's question. 4 is the one that needs the queue worker (threshold runs).

## Open question for Rob
- **Should the sustainable monthly allowance be solved against essentials-only survival, or against
  "money lasts to the final modelled death with the buffer intact"?** The second is stricter and
  matches the existing safety-floor concept; the first gives a larger, more cheerful number. Default
  proposed: the **stricter** one, per [[adverse-default-user-editable]].
