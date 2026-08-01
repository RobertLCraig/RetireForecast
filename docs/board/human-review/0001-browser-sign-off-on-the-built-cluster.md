# Browser sign-off on the built cluster

## What I need from you
Open the app and confirm the panels built since 2026-06-29 read correctly, because none of them
has ever been looked at in a browser: they are proven by tests and by numeric audit only. A pass
is "the figures on screen match the audit and the words make sense to a reader who is not you".
A failure on a numbered step points at that step's surface, not at the whole cluster.

Run `php artisan scenarios:audit` first, and start a queue worker: steps 4 and 5 need one.

1. <http://retireforecast.test/scenarios/9> , the results page. The **"To spend / month"** and
   **"Available capital"** ladder columns, the **investment-charges** line beneath it, the
   **"If one of you died"** section, and the **"What paying for advice would cost"** section.
   Pass: every figure is present and none reads as a placeholder.
2. Same page: the budget panel's mortgage instalment is labelled **computed** (it reads GBP 0 in
   the stored inputs by design), and the assumed-figure and depreciation notes are present. Pass:
   a stay-put plan shows no bought-home assumptions.
3. <http://retireforecast.test/scenarios/9/afford> , the monthly block and the **Check how sure**
   hand-off. Pass: it queues runs and returns to Compare.
4. Compare: the **"To spend / month"** and **"Available capital"** pair, and the **"Hide
   non-viable plans"** toggle re-rendering the burndown chart.
5. Thresholds, the trade-off map and the assistant. A spinner that never resolves means the
   worker is stale (see How to pick up), not that the feature is broken.
6. The four park-home scenarios, ids 51 to 54.
7. `npm run a11y` over the post-06-29 panels (docs/spec/A11Y.md), the mobile results nav, and the
   2FA QR scan at <http://retireforecast.test/account/security>.
8. **Download a PDF.** Pass: the four server-drawn charts read well on paper and the wide
   cashflow ladder is legible at print size, with no clipped final column.

## Why
The gating item for everything else. The whole post-2026-06-29 cluster is built but unreviewed
in a browser, so nothing downstream can be called finished until it has been looked at.

## Options
1. **One sitting, whole cluster.** Work the list below end to end. Longest single session, but
   the queue clears in one go and the "unreviewed" flag comes off the project.
2. **Split by surface.** Separate passes for a11y, the new panels, the PDF, and the Monte Carlo
   re-run. Each is independently checkable; four shorter sessions.
3. **Split by risk.** Eyeball the newest surfaces first (investment charges, tax relief, the
   "If one of you died" section, the advice-cost section) because they are least exercised, and
   defer the a11y and PDF passes.

## Recommendation
Option 2. `php artisan scenarios:audit` runs first regardless (it checks the figures and their
disclosure before you look), and the queue worker must be running or thresholds, the trade-off
map, assistant answers and the "Check how sure" MC runs will all be dead on the page.

## What to look at
- Browser a11y pass over the post-06-29 panels (`npm run a11y`; docs/spec/A11Y.md); mobile
  results nav; the 2FA QR scan.
- The panels: annuitisation, stress-test, care-risk, withdrawal-sequencing, IHT, the
  spending-smile ladder, the decision-support finishers, the assistant, and the
  "What you can afford" screen with its "Check how sure" hand-off.
- 2026-07-30: the "To spend / month" and "Available capital" columns on the results ladder, the
  pair on Compare, the monthly block on `/afford`; the budget panel's computed mortgage
  instalment (it reads GBP 0 in the stored inputs by design); the assumed-figure and
  depreciation notes; the four park-home scenarios (51 to 54).
- 2026-07-30: open a downloaded PDF. It is now a full landscape print of the whole results page
  with all four charts drawn server-side. Check the charts read well on paper and the wide
  cashflow ladder is legible at print size.
- 2026-07-31: the investment-charges line under the cashflow ladder (screen + PDF); the 8th
  assumption row ("Investment charges (a year)"); the "Tax relief on your contribution" select
  on each DC pension at builder step 3; the "If one of you died" section and its PDF twin, plus
  the death-in-service cover select at builder step 1; the "What paying for advice would cost"
  section and its advice-fee input.
- **Re-run the Monte Carlo.** House-price volatility, salary volatility and above-CPI care
  escalation are live for the first time, so every stored fan is narrower than the model now says.

## Decided
<!-- one line, dated, when the pass is done -->
