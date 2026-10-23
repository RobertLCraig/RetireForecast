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
- [x] #1 WHEN a tracked file under `packages/finance-engine/tests/` contains a denied term, THE APP SHALL fail the test suite. proves: `test_no_tracked_doc_or_test_names_the_private_couple`
<!-- AC:END -->

## Tasks
- [ ] Add `packages/finance-engine/tests` to the paths `NoPrivateDataTest::trackedFiles()` lists
- [ ] Reword the docblock to "a real lender's ESIS" and point at the gitignored capture

## Comments

**2026-10-23** RESULT: done
TESTS: +0 new (extended test_no_tracked_doc_or_test_names_the_private_couple), all green
TOUCHED: tests/Feature/Docs/NoPrivateDataTest.php
TOUCHED: packages/finance-engine/tests/Property/AmortisationScheduleTest.php
OUT-OF-SCOPE: none

trackedFiles() now lists packages/finance-engine/tests beside docs and tests. Watched it fail first on AmortisationScheduleTest.php lines 15 and 16, the lender's name, and on nothing else in the engine tests. The docblock now says "a real lender's ESIS", a 5-year fixed repayment mortgage, and points at the gitignored docs/HOUSING-OFFERS.local.md. The product code and quote date went with the name. The schedule figures stay: no denied term matches them, and the test asserts against them.
The name is still in git history. Purging it is a filter-repo call for Rob, not this card.
