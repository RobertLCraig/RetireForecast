# An engine test names the couple's lender, and the private-data guard does not look there

## Why
Raised by card 0043. Its guard scans tracked files under `docs/` and `tests/` only, which is what
that card's criterion asked for. The docblock of
`packages/finance-engine/tests/Property/AmortisationScheduleTest.php` names the lender and the
product of the couple's real mortgage quote. Point the guard at that test and it fails there.

## Links
**Relates to**
- `0043` - built the guard.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a tracked file under `packages/finance-engine/tests/` contains a denied term, THE APP SHALL fail the test suite. proves: `test_no_tracked_doc_or_test_names_the_private_couple`
<!-- AC:END -->

## Tasks
- [ ] Add `packages/finance-engine/tests` to the paths `NoPrivateDataTest::trackedFiles()` lists
- [ ] Reword the docblock to "a real lender's ESIS" and point at the gitignored capture
