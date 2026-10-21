# The spending breakdown can put the repayment instalment on a mortgage protection line

## Why
Found while building card 0024. `ResultPresenter::expenseBreakdown()` replaces the zeroed "Mortgage"
line with the amortisation schedule's instalment when the home has repayment terms. It picks the
line with `ResultPresenter::isMortgageLine()`, which matches any label containing "mortgage", and it
substitutes onto the FIRST match only.

So a household whose lines read "Mortgage protection insurance" before "Mortgage" sees the
instalment printed against the premium, the premium's own amount gone from the panel, and the real
"Mortgage" line shown at £0. The subtotals then no longer match what the projection charges.

Card 0024 added `HouseholdAssembler::isMortgagePayment()`, the one rule for which line is the
payment. `isMortgageLine()` is a second definition of the same thing and disagrees with it.

## Links

**Relates to**
- `0024` - where this was found; it added `isMortgagePayment()`, the rule `isMortgageLine()` disagrees with.

## Not this card
How the projection charges the premium or the payment. That is card 0024 and is correct.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN a home has repayment terms and a "Mortgage protection insurance" line is listed before the "Mortgage" line, THE APP SHALL show the instalment on the "Mortgage" line and the premium at its own amount. proves: `test_the_instalment_never_lands_on_a_protection_premium_line`
<!-- AC:END -->

## Plan
Make `isMortgageLine()` ask `HouseholdAssembler::isMortgagePayment()` as well as the label test, or
replace it with that rule. Add the test beside the other `expenseBreakdown` tests.

## Comments

**2026-10-21** RESULT: done
TESTS: +1 new, all green
TOUCHED: app/Forecast/ResultPresenter.php
tests/Unit/Forecast/SpendableViewTest.php
OUT-OF-SCOPE: none

`ResultPresenter::isMortgageLine()` now takes the whole line and asks `HouseholdAssembler::isMortgagePayment()` beside the 'mortgage' label test, so the breakdown and the projection share one rule for which line is the payment. test_the_instalment_never_lands_on_a_protection_premium_line lists 'Mortgage protection insurance' (420) before 'Mortgage' (0) and was watched failing first: 'Mortgage' printed £0.00 instead of £15,822.48. It now pins the instalment on 'Mortgage', the premium at £420.00, and the total at £19,242.48. No engine change, so no ENGINE_VERSION bump and no re-run owed. Built in a worktree: the panel is not seen in a browser.
