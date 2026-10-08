# The park-home guess is not counted as an assumed figure

## Why
When nobody says what kind of home it is, `Property::isChattelDwelling()` reads a home modelled as
losing value as a park home. That guess moves the estate (no residence nil-rate band, a 10%
commission off the value and off any sale). It is disclosed, but only as the opening sentence of
the `chattel_dwelling` note in `ResultPresenter::inputNotes()`. It is not an
`assumedFigures()` entry, so `scenarios:audit` and the assumed-figure parity checks do not count
it. The equal-shares default from the same card IS an `assumedFigures()` entry, so the two
engine-side defaults of card 0059 are disclosed two different ways. Raised by the 0059 scope
review (finding 2) and the 2026-09-28 manager pass.

## Links

**Relates to**
- `0059` - added the derived park-home reading and the `chattel_dwelling` note.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a home is read as a park home because nobody answered and it is modelled as losing value, THE APP SHALL disclose that reading as an assumed figure. proves: `test_a_derived_park_home_is_disclosed_as_an_assumed_figure`
- [ ] WHEN the reader answered what kind of home it is, THE APP SHALL NOT report the answer as assumed. proves: `test_a_stated_park_home_is_not_reported_as_assumed`
<!-- AC:END -->

## Plan
Move the "You did not tell us what kind of home this is" sentence out of the `chattel_dwelling`
note into `ResultPresenter::assumedFigures()`, keyed on `$home->isChattelDwelling === null &&
$home->isChattelDwelling()`. Test in `ChattelDwellingNoticeTest`.

## Comments
