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
- [x] WHEN a sell plan is on display, THE APP SHALL NOT describe the sold home's loan or council tax as
      a cost of that plan. proves: `test_a_sell_plan_is_not_told_about_the_loan_it_repaid`
<!-- AC:END -->

## Plan
The notes are in `app/Forecast/ResultPresenter.php`, `inputNotes()`, sections (c), (c2), (c3) and
(c4c). `$variant` is already passed in. `tests/Unit/Forecast/InputNotesTest.php` holds the fixtures.

## Comments

**2026-10-20** RESULT: done
TESTS: +1 new, all green
TOUCHED: app/Forecast/ResultPresenter.php
tests/Unit/Forecast/InputNotesTest.php
docs/board/todo/0209-a-sell-plan-is-told-its-old-mortgage-payment-is-still-charged.md
OUT-OF-SCOPE: 0209

The card left "hide or reword" to the build. I did both: I hid the loan notes and reworded the council tax note for a buy plan.

- Loan notes (c) `mortgage_redemption`, (c2) `lifetime_mortgage_rollup` and (c3) `repayment_mortgage` are gated on `keepsCurrentHome($variant)`. A sell plan clears that loan in year 0, so the notes are not raised.
- Council tax (c4c). On a rent plan, `council_tax` is not raised, because (c4c2) `council_tax_rent` already states the rent plan's own bill. On a buy plan, the code does NOT drop the bill. `HousingComparison::buyVariant` carries it to the bought home, so hiding the note would hide a figure the plan charges, which breaks the no-invisible-figures rule. The note now says the bill is carried to the home you buy, and it reads the buy plan's own years ($shown) for the figures it states. `council_tax_bundled` names the sold home's running costs, so it is raised only on stay put.

The test watched red first, for the reason the criterion gives: `mortgage_redemption` was raised on buy_outright. It builds two homes (roll-up with a redemption year and a separate council tax bill; repayment loan with the bill bundled into running costs). For each, a stay-put control must raise every note, then it runs the real buy and rent variant forecasts through `HousingComparison::variantInputs`.

Assumed, not settled from the repo: on a buy plan where the old bill is bundled and an explicit buy running cost is entered, I have not checked whether any council tax reaches the bought home at all. I did not card it because I did not confirm it.

Not seen in a browser. The worktree is not served by Herd, so the results screen still needs a browser check. Full suite run with `php artisan test` (there is no pest.bat, see handover). Pint is clean.
