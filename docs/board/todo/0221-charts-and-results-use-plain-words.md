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
- [ ] #1 THE APP SHALL not show "percentile", "real £", "fat tail" or "Monte Carlo" in any results or compare view without a plain-words explanation beside it. proves: `test_results_views_carry_no_unexplained_jargon`
- [ ] #2 THE APP SHALL render a data-driven takeaway sentence above every chart. proves: `test_every_chart_has_a_takeaway_sentence`
<!-- AC:END -->
