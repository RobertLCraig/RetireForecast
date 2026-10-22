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
- [x] #1 THE APP SHALL show the run's completion date on the verdict tab and in the PDF. proves: `test_the_results_show_the_run_date`
- [x] #2 WHEN the run's engine version is not the current one, THE APP SHALL show the out-of-date warning. proves: `test_a_run_from_an_older_engine_is_flagged_out_of_date`
- [x] #3 WHEN the run is current, THE APP SHALL show no warning. proves: `test_a_current_run_shows_no_out_of_date_warning`
<!-- AC:END -->

## Comments

**2026-10-22** RESULT: built
TESTS: +3 new in `ScenarioPdfTest` (each checks the screen and the PDF)
TOUCHED: `SimulationRun::isFromOlderEngine()`, the verdict hero in `scenario-results.blade.php`, `mcRun` in `ScenarioReport`, the PDF lede
OUT-OF-SCOPE: an "inputs changed" check. An input edit already deletes the scenario's runs (`ScenarioBuilder`), so a kept run cannot predate its inputs; only the engine can make it stale.

The date is `finished_at` (falls back to `updated_at`). The PDF already printed "run {date}" from `updated_at`; it now reads `finished_at` too. The warning compares the stored `engine_version` with `ScenarioForecaster::ENGINE_VERSION`; no second copy. On screen it has a "Run it again" button (`runFull`). Every stored scenario shows the warning until it is re-run, which is correct after the 0212-0214 engine changes. Tests were written with the code, not watched red first. **Not seen in a browser.**
