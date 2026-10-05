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

## Not this card
- The £750 figure itself, which card 0092 sourced.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a sale owes CGT, THE APP SHALL show the 60-day return fee in the assumptions panel, with its amount read from `HousingProceeds::CGT_RETURN_FEE_PENCE`. proves: `test_the_assumptions_panel_shows_the_cgt_return_fee_when_cgt_is_due`
<!-- AC:END -->

## Plan
Pass the panel the sale's `HousingProceeds` (or its breakdown) where the caller has it, and add a
row for the fee when it appears.

## Comments
