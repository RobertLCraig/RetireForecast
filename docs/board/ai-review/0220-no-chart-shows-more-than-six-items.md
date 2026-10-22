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
- [x] #1 THE APP SHALL build no chart with more than 6 series, on screen or in the PDF. proves: `test_no_chart_carries_more_than_six_series`
- [x] #2 THE APP SHALL make the grouped income bands sum to the ungrouped total every year. proves: `test_the_grouped_income_bands_reconcile_to_the_total`
- [x] #3 WHEN a family has more than 5 plans, THE APP SHALL draw 5 and say how many more are in the table. proves: `test_the_compare_chart_draws_at_most_five_plans`
- [ ] #4 Rob looks at each chart in a browser and none is crowded. proves: manual
<!-- AC:END -->

## Comments

**2026-10-22** RESULT: built (AC #4, the look in a browser, is Rob's)
TESTS: +3 new in `TimeSeriesChartsTest`, +1 in `MilestonesTest`; drifted tests updated (the income fold test became the band test; the deleted comparison chart's assertions removed from `ResultPresenterChartsTest`; the dodge test uses only charted kinds)
TOUCHED: `ResultPresenter` (`incomeBands()`, `chartedMilestones()`, `burndown()`, `comparison()`), `pdf/partials/chart-key.blade.php`, the income captions on screen and in the PDF, `scenario-compare.blade.php`
OUT-OF-SCOPE: none.

- Income: five bands (Earnings; Guaranteed income, which is `SECURE_SOURCES`; Pension pots drawn; Savings drawn; Benefits and other). The band is called "Guaranteed income", not "Guaranteed pensions", because `SECURE_SOURCES` holds tax-free income such as DLA too. Every source sits in one band, tested against `YearResult::INCOME_SOURCES`. The table keeps every source.
- Compare: the base plus the 4 that last longest (no run-out first, then most left at the end), 5 fixed colours, each line named at its last point. "N more plans are in the table" shows below the chart.
- Event lines: retirement and State Pension start only, 12px. The PDF key numbers the same filtered list, so its numbers still match the lines. The events table keeps every event.
- Deleted: the never-drawn strategy-comparison chart; `comparison()` now returns only the summary rows the PDF reads. `OTHER_COLOUR` and `VARIANT_COLOURS` went with it.

The new tests were written with the code, not watched red first. **Not seen in a browser.**
