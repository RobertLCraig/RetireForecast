# The ladder marks failing-reference rows and nothing shows it

## Why
Found by the 2026-09-05 review of card 0031, and still true on 2026-10-05.
`ResultPresenter::ladder()` sets `failsReference` on every cashflow row whose year fails a tenant
reference, with a comment saying the table marks it. No template reads the key, on screen or in
the PDF. Only `RentReferencingNoticeTest` does. The reader sees the banner but cannot see which
rows it means.

## Links

**Relates to**
- `0031` - added the row key and the banner.

## Not this card
- When the referencing flag is raised, which card 0031 settled.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a year of a rent plan fails a standard reference, THE APP SHALL mark that row in the cashflow table on the results page and in the PDF. proves: `test_the_cashflow_table_marks_the_rows_that_fail_a_reference`
<!-- AC:END -->

## Plan
Read `failsReference` in `resources/views/livewire/scenario-results.blade.php` and
`resources/views/pdf/partials/report.blade.php`, or delete the key and its comment if Rob would
rather the banner alone carries it.

## Comments

**2026-10-22** RESULT: done
TESTS: +1 new, all green
TOUCHED: resources/views/livewire/scenario-results.blade.php
resources/views/pdf/partials/report.blade.php
tests/Feature/Forecast/RentReferencingNoticeTest.php
OUT-OF-SCOPE: none

The year cell of each cashflow row whose `failsReference` is true now reads "fails a reference": under the year on the results page (amber-700, like "unmet"), and under the year in the PDF's "Where the money comes from each year" table, the income table, since income is what fails it. The row-tint legend on both gains one sentence explaining the mark, only on a plan that has the banner. Kept the key rather than deleting it, as the Plan's first option.

`test_the_cashflow_table_marks_the_rows_that_fail_a_reference` renders the rich fixture's rent plan on screen and as the PDF HTML and asserts the set of table rows carrying the mark equals exactly the years the ladder flags (2030, then 2032 onward; 2031 passes, so a mark on every row would fail it). Watched it fail first with zero rows marked on screen.

Not seen in a browser: built in a worktree, so the results page and the PDF still need a look.
