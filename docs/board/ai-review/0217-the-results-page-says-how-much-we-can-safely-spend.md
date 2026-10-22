---
needs: 0212, 0213
---
# The results page says how much we can safely spend each month

## Why
Found by the full review on 2026-10-21 (what-the-couple-needs pass). "How much can we spend?" is the
first question a couple asks. The answer exists only on `/afford` ("Most you could spend on
treats", `afford` view L142-150) and in the ladder inside a closed fold-out on the Money tab. The
results page never says it.

## Direction
Add a tile to `sec-headline`: "You can safely spend about £X a month (£Y a year), in today's money".
Read it from the same solver `/afford` uses (essentials plus `affordableFreeSpendMonthly`); never
recompute it. Under it, the figure for the survivor after the first death. State the confidence the
figure is solved at, and link to `/afford` for the detail.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL show a monthly and a yearly safe-spend figure on the verdict tab, in today's money. proves: `test_the_verdict_tab_shows_the_safe_monthly_spend`
- [x] #2 THE APP SHALL show the same figure as `/afford` for the same scenario. proves: `test_the_verdict_safe_spend_matches_afford`
<!-- AC:END -->

## Comments

**2026-10-22** RESULT: built
TESTS: +2 new in `ScenarioResultsTest`
TOUCHED: `ScenarioResults::render()` (`safeSpend`), the verdict hero in `scenario-results.blade.php`
OUT-OF-SCOPE: the confidence the figure is solved at. `SustainableSpend` solves on the expected
path only, so the tile says that and points at the "out of 10" figure instead of quoting a level.

The tile reads `SustainableSpend::forScenario()`, the solver `/afford` reads, so the two pages
cannot differ. It shows the most the plan could spend on top of essentials, monthly and yearly.
A plan that cannot cover essentials says so in words rather than "£0". The survivor line is what the
plan pays to live on after the first death (`spendableSummary`), not a second solve, and is worded
that way. The solver runs about 30 deterministic forecasts on every render of the results page;
cache it if the page gets slow. **Not seen in a browser.**

