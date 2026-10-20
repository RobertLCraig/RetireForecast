# A sell plan is told its old mortgage payment is still charged

## Why
Found while building card 0153.

The `mortgage_payment` note in `ResultPresenter::inputNotes()`, section (c3b), reads the payment
off `$household->expenseProfile->mortgageCosts()`, the household as entered, and is not gated on
the strategy on display. A sell plan sells the home in year 0 and `HousingComparison::withHousing`
strips the old home's mortgage cost, so on a sell-and-buy or sell-and-rent screen the note says
"Your mortgage payment of £X a year is charged as an essential cost" for a payment the plan never
charges. On a mortgaged buy, the payment charged is the new home's interest, a different figure.

Card 0153 hid the sold home's loan notes (c) to (c3) on a sell plan and left this one, because the
card named only those four.

This was read from the code, not seen on a screen.

## Links

**Relates to**
- `0153` - hid the other notes about the sold home's loan on a sell plan.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a sell plan is on display, THE APP SHALL NOT state the entered home's mortgage payment as
      a cost of that plan. proves: `test_a_sell_plan_is_not_told_its_old_mortgage_payment_is_charged`
<!-- AC:END -->

## Plan
`app/Forecast/ResultPresenter.php`, `inputNotes()`, section (c3b). `$variant` is passed in, and
`self::keepsCurrentHome($variant)` is the gate (c) to (c3) use. `tests/Unit/Forecast/InputNotesTest.php`
`test_a_sell_plan_is_not_told_about_the_loan_it_repaid` builds the variant forecasts to copy.

## Comments
