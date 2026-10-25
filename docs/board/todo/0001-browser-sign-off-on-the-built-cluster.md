# Browser sign-off on the built cluster

## What I need from you

**Open the app and sign off the eight numbered surfaces below.** Start at
<http://retireforecast.test/scenarios/9>. Run `php artisan scenarios:audit` first, and start a queue
worker, because steps 4 and 5 need one.

**Pass** is both of these, on every step: the figures on screen match the audit, and the words make
sense to a reader who is not you.

**Fail** on a numbered step points at that step's surface rather than at the whole cluster, so
report the number. Step 5 has a known false alarm: a spinner that never resolves means the worker is
stale, not that the feature is broken.

**Why it needs you** None of these panels has ever been looked at in a browser. They are proven by
tests and by numeric audit only, and neither can tell you whether a sentence reads as nonsense to
somebody who did not write it, or whether the wide cashflow ladder is legible at print size.

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

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE CARD SHALL record a pass or fail, with findings, for step 1 (results-page ladder columns, investment charges, "If one of you died", advice-cost section). proves: manual
- [ ] #2 THE CARD SHALL record a pass or fail for step 2 (computed mortgage label, assumed-figure and depreciation notes, no bought-home notes on stay-put). proves: manual
- [ ] #3 THE CARD SHALL record step 3 (/afford "Check how sure" queues runs and returns to Compare), with a live queue worker. proves: manual
- [ ] #4 THE CARD SHALL record step 4 (Compare pair and "Hide non-viable plans" re-render, plus card 0009's "Re-run all" click). proves: manual
- [ ] #5 THE CARD SHALL record step 5 (thresholds, trade-off map and assistant resolve), with a live queue worker. proves: manual
- [ ] #6 THE CARD SHALL record step 6 (park-home scenarios 51 to 54). proves: manual
- [ ] #7 THE CARD SHALL record step 7 (`npm run a11y` output, mobile nav, 2FA QR, and card 0012's four hand checks). proves: manual
- [ ] #8 THE CARD SHALL record step 8 (the downloaded PDF's charts and the ladder's final column) and the Monte Carlo re-run. proves: manual
- [ ] #9 THE SIGN-OFF SHALL record a pass or fail with evidence for each of steps 1 to 8 on the card. proves: manual
- [ ] #10 THE SIGN-OFF SHALL record the `scenarios:audit` output and the worker-up "Re-run all" result from card 0009. proves: manual
- [ ] #11 THE SIGN-OFF SHALL record card 0012's four hand a11y checks on the results page. proves: manual
- [ ] #12 THE SIGN-OFF SHALL record a pass or fail for each of steps 1 to 8, with the `php artisan scenarios:audit` result taken first. proves: manual
- [ ] #13 THE SIGN-OFF SHALL run steps 3, 4 and 5, and card 0009's "Re-run all", with a live queue worker, and record that the runs finished. proves: manual
<!-- AC:END -->

## Comments

**2026-09-30** Triage of the human-review lane. This card asks a person to look at finished work,
and that is what `ai-review/` is for, so it moves there: a reviewing agent drives the eight steps
with the browser tools against the Herd site, which serves this tree and not a worktree. State
found today: `https://retireforecast.test` answers 200; **no queue worker is running** (no
`queue:work` process, `jobs` 0 pending, `failed_jobs` 0), so steps 3, 4 and 5 spin until one is
started with the line in HANDOVER "How to pick up", which only an attended session may do. Two
checks other cards defer to this pass are folded in: card 0009's in-app "Re-run all" click on
`/scenarios/9/compare` with the worker up, and card 0012's four hand a11y checks on the results
page (ApexCharts table twins, 400% reflow, screen-reader order, link text in context). Anything
that reads as nonsense or a placeholder is a finding, one per step number.

### 2026-10-25 review (v20261025133727-1599)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 645s, run by this job rather than reported by the card.

**acceptance: defect**

I checked the card. It has no `## Acceptance` block, so I judged it against its numbered steps.

**What the card asks for:** a browser pass over eight surfaces. Each step needs a pass or fail result, with the step number.

**What I found:**

- The card has no record that any of the eight steps was done. There is no pass, no fail and no finding against any step number.
- The only comment (2026-09-30) says that no queue worker was running. No later comment says a worker was started. Without a worker, steps 3, 4 and 5 cannot pass.
- The diff changes code and docs, for example `ResultPresenter`, `ScenarioPdfController` and `scripts/a11y-authenticated.mjs`. Code does not complete this card. The card asks for an observed result in a browser, and no code path can give that.
- The card also asks for a Monte Carlo re-run, a "Re-run all" click (from card 0009) and four hand a11y checks (from card 0012). There is no record of any of them.

I could not trace any step to a recorded result, so each step is a defect.

ADD: THE CARD SHALL record a pass or fail, with findings, for step 1 (results-page ladder columns, investment charges, "If one of you died", advice-cost section). proves: manual
ADD: THE CARD SHALL record a pass or fail for step 2 (computed mortgage label, assumed-figure and depreciation notes, no bought-home notes on stay-put). proves: manual
ADD: THE CARD SHALL record step 3 (/afford "Check how sure" queues runs and returns to Compare), with a live queue worker. proves: manual
ADD: THE CARD SHALL record step 4 (Compare pair and "Hide non-viable plans" re-render, plus card 0009's "Re-run all" click). proves: manual
ADD: THE CARD SHALL record step 5 (thresholds, trade-off map and assistant resolve), with a live queue worker. proves: manual
ADD: THE CARD SHALL record step 6 (park-home scenarios 51 to 54). proves: manual
ADD: THE CARD SHALL record step 7 (`npm run a11y` output, mobile nav, 2FA QR, and card 0012's four hand checks). proves: manual
ADD: THE CARD SHALL record step 8 (the downloaded PDF's charts and the ladder's final column) and the Monte Carlo re-run. proves: manual

VERDICT: defect

**scope: defect**

**Findings (scope)**

1. **The sign-off itself was never done.** The card asks for one thing: drive eight browser steps and record a pass or fail for each. The card file `docs/board/ai-review/0001-browser-sign-off-on-the-built-cluster.md` has no results at all. Its only commit is the lane move (`13c151a5`). There is no audit output, no screenshots, no per-step verdict and no PDF check. Card 0009's "Re-run all" click and card 0012's four a11y hand checks were folded into this card, and they are not recorded either. The Monte Carlo re-run is also not recorded.

2. **The diff is far bigger than the card.** The card asks for a review, not code. The change touches 600 files and adds about 77,000 lines. Some examples:
   - engine rules in `PathProjector`, `InheritanceTaxCalculator`, `CouncilTax`, `HousingBenefit` and `SupportForMortgageInterest`
   - assistant scenario editing (`ScenarioEditCapture`)
   - a rewrite of `README.md`
   - a new `AGENTS.md`
   - dozens of new board cards

   None of this is in the card's eight steps.

3. **Encoding damage.** In `.gitignore`, the existing comments went from one layer of mis-encoding to two (the dash now shows as `├óÔé¼ÔÇØ`). The card did not ask for any edit to that file.

ADD: THE SIGN-OFF SHALL record a pass or fail with evidence for each of steps 1 to 8 on the card. proves: manual
ADD: THE SIGN-OFF SHALL record the `scenarios:audit` output and the worker-up "Re-run all" result from card 0009. proves: manual
ADD: THE SIGN-OFF SHALL record card 0012's four hand a11y checks on the results page. proves: manual

VERDICT: defect

**breakage: defect**

I am in read-only mode. I did not change any file.

**What I found:** The card has no `## Acceptance` block. I judged it on what its "What I need from you" section promises.

Nothing on the card or in the diff shows that anyone did the eight browser steps. The only comment, dated 2026-09-30, is triage. It says no queue worker was running, so steps 3, 4 and 5 could not pass. The card records no pass or fail for any step. It also does not record the `scenarios:audit` output, the folded-in 0009 "Re-run all" click, or the four 0012 hand checks.

The diff is the whole cluster's code. It is not evidence that the review happened. A green suite cannot prove it either, because the card exists exactly because tests cannot show this.

Silent breakage: the card moved to `ai-review/`, so the "unreviewed" flag looks cleared, but no review was done.

ADD: THE SIGN-OFF SHALL record a pass or fail for each of steps 1 to 8, with the `php artisan scenarios:audit` result taken first. proves: manual
ADD: THE SIGN-OFF SHALL run steps 3, 4 and 5, and card 0009's "Re-run all", with a live queue worker, and record that the runs finished. proves: manual
ADD: THE SIGN-OFF SHALL record card 0012's four hand a11y checks on the results page. proves: manual

VERDICT: defect

**acceptance written**, because the card had none

- [ ] #1 THE CARD SHALL record a pass or fail, with findings, for step 1 (results-page ladder columns, investment charges, "If one of you died", advice-cost section). proves: manual
- [ ] #2 THE CARD SHALL record a pass or fail for step 2 (computed mortgage label, assumed-figure and depreciation notes, no bought-home notes on stay-put). proves: manual
- [ ] #3 THE CARD SHALL record step 3 (/afford "Check how sure" queues runs and returns to Compare), with a live queue worker. proves: manual
- [ ] #4 THE CARD SHALL record step 4 (Compare pair and "Hide non-viable plans" re-render, plus card 0009's "Re-run all" click). proves: manual
- [ ] #5 THE CARD SHALL record step 5 (thresholds, trade-off map and assistant resolve), with a live queue worker. proves: manual
- [ ] #6 THE CARD SHALL record step 6 (park-home scenarios 51 to 54). proves: manual
- [ ] #7 THE CARD SHALL record step 7 (`npm run a11y` output, mobile nav, 2FA QR, and card 0012's four hand checks). proves: manual
- [ ] #8 THE CARD SHALL record step 8 (the downloaded PDF's charts and the ladder's final column) and the Monte Carlo re-run. proves: manual
- [ ] #9 THE SIGN-OFF SHALL record a pass or fail with evidence for each of steps 1 to 8 on the card. proves: manual
- [ ] #10 THE SIGN-OFF SHALL record the `scenarios:audit` output and the worker-up "Re-run all" result from card 0009. proves: manual
- [ ] #11 THE SIGN-OFF SHALL record card 0012's four hand a11y checks on the results page. proves: manual
- [ ] #12 THE SIGN-OFF SHALL record a pass or fail for each of steps 1 to 8, with the `php artisan scenarios:audit` result taken first. proves: manual
- [ ] #13 THE SIGN-OFF SHALL run steps 3, 4 and 5, and card 0009's "Re-run all", with a live queue worker, and record that the runs finished. proves: manual

