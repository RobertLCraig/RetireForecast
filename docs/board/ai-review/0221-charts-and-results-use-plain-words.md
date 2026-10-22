---
needs: 0220
---
# Charts and results use plain words, and each chart says what it means

## Why
Found by the full review on 2026-10-21 (layperson pass).

- Fan legend: "10th-90th percentile / 25th-75th percentile / Median (50th)" (`ResultPresenter.php:610-612`).
- Y-axis: "Spendable money, excl. home, after pension tax (real £)" (`:589`, `:899`).
- Results page: "fat tail" (L281), "joint-life mortality model" (L255), "at your margin" (L486),
  "RNRB", "Monte Carlo" (also `threshold-explorer.blade.php:282`), "real £" throughout.
- No chart has a takeaway line. Captions describe the chart, not the answer.

## Direction
- Fan: "Likely range (8 in 10 futures)", "Middle half", "Typical outcome".
- Y-axis: "Money you can spend (today's £)". Replace "real £" with "today's money" everywhere.
- Plain swaps: "fat tail" -> "a small chance of a very large bill"; "Monte Carlo" -> "10,000 possible
  futures"; spell out RNRB once with a hover definition.
- Above each chart, one sentence built from the figures: e.g. "Typical outcome: your money lasts to
  2047, when you would be 88 and 90." Use `depletionYear` / `runsShortYear`, already computed.
- Keep the term in a glossary tooltip where a reader may meet it elsewhere (a pension statement).

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL not show "percentile", "real £", "fat tail" or "Monte Carlo" in any results or compare view without a plain-words explanation beside it. proves: `test_results_views_carry_no_unexplained_jargon`
- [x] #2 THE APP SHALL render a data-driven takeaway sentence above every chart. proves: `test_every_chart_has_a_takeaway_sentence`
<!-- AC:END -->

## Comments

**2026-10-22** RESULT: built
TESTS: +2 new in `tests/Feature/Livewire/PlainWordsTest.php` (both watched red first: 15 jargon hits, then a chart with no takeaway); 1 drifted axis-title assertion updated in `TimeSeriesChartsTest`
TOUCHED: `ResultPresenter` (fan legend and axis, `today's £`, takeaways on the fan, the three time-series charts and `burndown()`, `planningHorizonBasis()`), `ThresholdPresenter` (`netPosition` and `sCurve` takeaways), the results, Compare and threshold-explorer views, one PDF sentence
OUT-OF-SCOPE: takeaways in the PDF (the AC covers the views); the glossary tooltip (every term was swapped for plain words instead, so none was needed); RNRB (no longer on any rendered page); the builder's horizon labels ("Cautious (75th percentile)") live in the engine enum, so changing them needs an `ENGINE_VERSION` bump: a card of its own if wanted.

The jargon test reads the whole rendered page, chart option JSON included, so a legend or an axis title cannot hide jargon. It decodes entities, so "Monte&nbsp;Carlo" is caught too. Each takeaway is built from the figures the chart draws and sits above it with `data-takeaway`. The Compare takeaway names the plan with the most left to spend at its end: a fact, not a ranking of which to choose. **Not seen in a browser.**
