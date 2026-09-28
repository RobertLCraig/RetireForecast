# A sell plan still shows the notes about the home it sold

## Why
Found while building card 0089.

Four notes in `ResultPresenter::inputNotes()` describe the home as entered, and each is gated only
on `$household->primaryResidence`, never on the strategy on display:

1. `mortgage_redemption` (the entered mortgage's redemption year and what happens then),
2. `lifetime_mortgage_rollup` (the entered roll-up loan and what it leaves to inherit),
3. `repayment_mortgage` (the entered loan's instalment and clearing year),
4. `council_tax` / `council_tax_bundled` (the entered home's bill and its reductions).

A sell-and-buy or sell-and-rent plan sells that home in year 0 and clears its loan from the
proceeds, so on those screens the four notes describe a loan and a bill the plan on display never
carries. Card 0089 left them reading the stay-put forecast on purpose: fed the sell plan's forecast
instead, the roll-up note would report a £0 balance on the bought home, which is wrong in a new way.
Whether they should be hidden on a sell plan, or reworded as "what you are selling", is this card.

This was read from the code, not seen on a screen.

## Links

**Relates to**
- `0089` - split the notes into the household as entered and the plan on display, and left these four
  on the entered side.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a sell plan is on display, THE APP SHALL NOT describe the sold home's loan or council tax as
      a cost of that plan. proves: `test_a_sell_plan_is_not_told_about_the_loan_it_repaid`
<!-- AC:END -->

## Plan
The notes are in `app/Forecast/ResultPresenter.php`, `inputNotes()`, sections (c), (c2), (c3) and
(c4c). `$variant` is already passed in. `tests/Unit/Forecast/InputNotesTest.php` holds the fixtures.

## Comments
