# The roll-up note's figures on a maturity forced sale are untested

## Why
Card 0056 changed `ResultPresenter::inputNotes()` so the `lifetime_mortgage_rollup` note reads the
LAST year the home is still held, not the final projected year. That also changes the year, balance
and equity the note prints for a plan whose home is sold by a **forced sale at maturity**, which
has nothing to do with care. The review of 0056 (scope finding 2) and the 2026-09-28 manager pass
both flagged it, and no test covers it: `InputNotesTest::rollUpState()` builds a home that is never
sold.

## Links

**Relates to**
- `0056` - introduced the last-year-with-property loop.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a roll-up plan is sold by a forced sale at maturity, THE APP SHALL report the balance and equity of the last year the home was held. proves: `test_the_roll_up_note_reads_the_last_year_before_a_forced_sale`
<!-- AC:END -->

## Plan
Extend `InputNotesTest` with a roll-up state that sets a redemption year and `forced_sale`, and
assert the note names that year's balance rather than £0.

## Comments
