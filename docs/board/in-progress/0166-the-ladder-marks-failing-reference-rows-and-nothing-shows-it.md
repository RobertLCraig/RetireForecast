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
- [ ] #1 WHEN a year of a rent plan fails a standard reference, THE APP SHALL mark that row in the cashflow table on the results page and in the PDF. proves: `test_the_cashflow_table_marks_the_rows_that_fail_a_reference`
<!-- AC:END -->

## Plan
Read `failsReference` in `resources/views/livewire/scenario-results.blade.php` and
`resources/views/pdf/partials/report.blade.php`, or delete the key and its comment if Rob would
rather the banner alone carries it.

## Comments
