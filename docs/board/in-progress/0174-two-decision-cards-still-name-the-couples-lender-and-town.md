---
needs: 0043
---
# Two decision cards still name the couple's lender, broker and town

## Why
Raised by card 0043. Its guard, `tests/Feature/Docs/NoPrivateDataTest.php`, finds private names in
cards 0022 and 0023. Card 0043 was not allowed to edit other cards, so it exempts those two in
`PENDING_SCRUB` and points at this card. Run the test with both entries removed to see each hit.

## Links

**Blocked by**
- `0043` - built the guard and scrubbed every other tracked doc; it is still awaiting review.

**Relates to**
- `0022`, `0023` - the two cards to scrub.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL fail the suite for a denied term in any tracked card, with no card exempt. proves: `test_no_tracked_doc_or_test_names_the_private_couple`
<!-- AC:END -->

## Tasks
- [ ] Replace the names and the estate figure in 0022 and 0023 with neutral words and a pointer to
      the gitignored capture that holds them
- [ ] Delete `PENDING_SCRUB` and its use from the test

## Comments

**2026-10-13** RESULT: blocked
TESTS: +0 new, none run (nothing changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card cannot be built by an unattended loop session. Its first task is to rewrite cards 0022 (now in human-review/) and 0023 (now in todo/), and the loop's hard rules forbid a card session from editing any card other than raising a new one. That is the same rule that stopped card 0043 and made this card exist, so a loop take will block here every time.

The second task (delete PENDING_SCRUB from tests/Feature/Docs/NoPrivateDataTest.php) cannot go first on its own: with both cards still carrying the denied terms, criterion #1's test turns red, and red is never committed.

What would unblock it, either one:
1. A person (Rob, or an attended session) does the scrub of 0022 and 0023 by hand, replacing the names and the estate figure with neutral words and a pointer to docs/SCENARIO-V2.local.md, then deletes PENDING_SCRUB and its use (pendingCard(), the $stale branch) in the same commit. Run `php artisan test --filter test_no_tracked_doc_or_test_names_the_private_couple` with PENDING_SCRUB emptied first to see each hit and watch it fail.
2. Mark this card not_for_the_loop: so the scheduler stops sending it here.

Recommend 1: it is two small text edits plus a short test deletion, about ten minutes attended.
