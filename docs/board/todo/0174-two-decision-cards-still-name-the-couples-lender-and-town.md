---
needs: 0043
---
# Two decision cards still name the couple's lender, broker and town

## Why
Raised by card 0043. Its guard, `tests/Feature/Docs/NoPrivateDataTest.php`, finds private names in
cards 0022 and 0023. Card 0043 was not allowed to edit other cards, so it exempts those two in
`PENDING_SCRUB` and points at this card. Run the test with both entries removed to see each hit.

## Links
**Relates to**
- `0043` - built the guard and scrubbed every other tracked doc.
- `0022`, `0023` - the two cards to scrub.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL fail the suite for a denied term in any tracked card, with no card exempt. proves: `test_no_tracked_doc_or_test_names_the_private_couple`
<!-- AC:END -->

## Tasks
- [ ] Replace the names and the estate figure in 0022 and 0023 with neutral words and a pointer to
      the gitignored capture that holds them
- [ ] Delete `PENDING_SCRUB` and its use from the test
