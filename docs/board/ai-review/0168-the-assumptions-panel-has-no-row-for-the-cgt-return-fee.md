# The assumptions panel has no row for the CGT-return fee

## Why
Found by the 2026-09-05 review of card 0032, repeated by the 2026-09-28 manager pass, and still
true on 2026-10-05. `ResultPresenter::assumptionsPanel()` lists one row per selling-cost component
and says the sale waterfall's total traces to it. On a sale that owes CGT,
`HousingProceeds::compute()` appends a £750 return fee that is no component, so the panel is £750
under the waterfall and the fee has no stated basis there. That breaks the no-invisible-figures
rule. The panel gets the `HousingAction` only, not the computed proceeds, so it cannot see whether
CGT is due.

## Links

**Relates to**
- `0032` - added the fee, and the panel's selling-cost rows and total.
- `0167` - a reader's own quote for the same fee is charged on top of it.
- `0092` - sourced the £750 figure, which this card leaves as it is.

## Not this card
- The £750 figure itself, which card 0092 sourced.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a sale owes CGT, THE APP SHALL show the 60-day return fee in the assumptions panel, with its amount read from `HousingProceeds::CGT_RETURN_FEE_PENCE`. proves: `test_the_assumptions_panel_shows_the_cgt_return_fee_when_cgt_is_due`
<!-- AC:END -->

## Plan
Pass the panel the sale's `HousingProceeds` (or its breakdown) where the caller has it, and add a
row for the fee when it appears.

## Comments

**2026-10-22** RESULT: done
TESTS: +1 new, all green
TOUCHED: app/Forecast/ResultPresenter.php
app/Livewire/ScenarioResults.php
app/Export/ScenarioReport.php
tests/Unit/Forecast/AssumptionsPanelTest.php
OUT-OF-SCOPE: none

`ResultPresenter::assumptionsPanel()` takes an optional `?HousingProceeds $proceeds` as its last argument. When that sale owes CGT, the panel adds a row named 'Selling cost — ' plus `HousingProceeds::CGT_RETURN_LABEL`. Its amount is read from `CGT_RETURN_FEE_PENCE`. The results page and the PDF report pass the proceeds only when the strategy on display sells (`$ladderContext->homeSold()`), which is the same gate the sale waterfall uses.

I watched the test fail first, for the right reason: the panel had no fee row ('' does not contain £750.00). It also proves that a sale with no CGT gets no row.

Assumption: a reader's own quote line under the return's label (card 0167) is already listed as one of their own lines, so the panel adds no second row for it. This follows the engine, which charges the quote in place of the default. The 'Selling costs in total' row still sums only the reader's own lines and says so ('from your lines above'). I did not change it.

Not seen in a browser: this worktree is not what Herd serves, so the new row still needs a browser check on the results page and in the PDF.
