---
needs: 0212, 0213
---
# A result says when it was run, and warns when it is out of date

## Why
Found by the full review on 2026-10-21. The verdict shows path count, mode and seed (L216-219), but
no run date. The PDF shows only its own "generated" date (`pdf/partials/report.blade.php:15`).
Nothing tells the reader that a result predates the current `ENGINE_VERSION`, though HANDOVER lists
many re-runs owed. A reader can act on a stale figure without knowing.

## Direction
Under the verdict heading: "Run on {completed date}". When the run's stored engine version differs
from the current one, or the inputs changed since the run, show an amber "This forecast is out of
date: re-run it" with the button. The PDF prints the run date beside the generated date. Read the
stored version; do not add a second copy of it.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL show the run's completion date on the verdict tab and in the PDF. proves: `test_the_results_show_the_run_date`
- [ ] #2 WHEN the run's engine version is not the current one, THE APP SHALL show the out-of-date warning. proves: `test_a_run_from_an_older_engine_is_flagged_out_of_date`
- [ ] #3 WHEN the run is current, THE APP SHALL show no warning. proves: `test_a_current_run_shows_no_out_of_date_warning`
<!-- AC:END -->
