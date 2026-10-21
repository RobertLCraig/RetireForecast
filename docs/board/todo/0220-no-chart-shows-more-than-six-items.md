---
needs: 0212, 0213
---
# No chart shows more than six items

## Why
Rob's rule (2026-10-21): a chart for a layperson carries 4 to 6 items so it does not get crowded.
Found by the full review the same day:

- **Income chart** (`ResultPresenter.php:1981`, `partials/time-series-charts.blade.php:38`): up to 8
  sources plus "Other" = 9 bands.
- **Compare chart** (`ResultPresenter.php:850`, `scenario-compare.blade.php:238`): one line per plan,
  no limit. It sets no colours, so ApexCharts' default palette repeats after 5 and two plans can
  share a colour.
- **Life-event lines** (`ResultPresenter.php:2189-2240`): up to 9 on every time-series chart, labelled
  in 9px vertical text (`:2292`), too small for older eyes.
- **Dead code:** the strategy-comparison options (`ResultPresenter.php:778-796`) are built and never
  drawn.

## Direction
- Income: group into 5 bands - Earnings; Guaranteed pensions (DB, State Pension, annuity; reuse
  `SECURE_SOURCES`); Pension pots drawn; Savings drawn; Benefits and other. The table keeps the full
  breakdown, and the bands must sum to the same total (reconciliation test).
- Compare: draw the base plus the 4 best-lasting plans, with a fixed 5-colour palette; the rest go in
  the table with "N more plans in the table". Label each line at its end, not by colour alone.
- Event lines: keep retirement and State Pension start only; the rest go to the events list. 12px.
- Delete the dead strategy-comparison builder.
- Apply the same counts to the PDF charts (`app/Export/ChartSvg.php`).

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL build no chart with more than 6 series, on screen or in the PDF. proves: `test_no_chart_carries_more_than_six_series`
- [ ] #2 THE APP SHALL make the grouped income bands sum to the ungrouped total every year. proves: `test_the_grouped_income_bands_reconcile_to_the_total`
- [ ] #3 WHEN a family has more than 5 plans, THE APP SHALL draw 5 and say how many more are in the table. proves: `test_the_compare_chart_draws_at_most_five_plans`
- [ ] #4 Rob looks at each chart in a browser and none is crowded. proves: manual
<!-- AC:END -->
