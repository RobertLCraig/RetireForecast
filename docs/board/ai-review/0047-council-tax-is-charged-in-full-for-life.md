# Council tax is charged at full rate for the whole projection

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 9). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Council tax is bundled into `Property::runningCosts` with maintenance and insurance, and charged in
full every year. Three things follow.

**No single-person discount.** Property running costs are added after the survivor spend factor is
applied, so a survivor is charged a couple's council tax for the rest of their life. The discount
is 25% and it is automatic.

**No Council Tax Reduction.** Pension-age support is prescribed by regulation, so councils cannot
cut it. A survivor near the Pension Credit line qualifies for close to full reduction, which on a
typical bill is well over a thousand pounds a year the model charges and they would not pay.

**No disabled band reduction.** It is **not means-tested**. It needs only a qualifying feature - an
extra bathroom, a room used for the disabled person's needs, or space to use a wheelchair indoors -
and it drops the bill a whole band. It is claimable now, not at some future point in the plan.

The Council Tax Reduction calculation can reuse the Pension Credit applicable amount the engine
already computes.

## Not this card
Pension Credit itself, which is card 0046.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL hold council tax as its own cost line, separate from maintenance and insurance. proves: `test_council_tax_is_its_own_cost_line_beside_maintenance_and_insurance`
- [x] #2 WHEN only one person remains in a household, THE APP SHALL apply the single-person discount. proves: `test_the_single_person_discount_applies_once_one_member_remains`
- [x] #3 WHEN income and capital qualify, THE APP SHALL award Council Tax Reduction on the pension-age basis. proves: `test_council_tax_reduction_is_awarded_on_the_pension_age_basis`
- [x] #4 THE APP SHALL let a user record a disabled band reduction and apply it. proves: `test_the_council_tax_bill_and_a_disabled_band_reduction_reach_the_property`
<!-- AC:END -->

## Tasks
- [x] Split council tax out of `runningCosts`, with a band input
- [x] Apply the single-person discount from the first death
- [x] Compute Council Tax Reduction from the existing applicable amount, sourced and dated
- [x] Add the disabled band reduction as an input, and prompt for it

## Comments

**2026-09-06** RESULT: done
TESTS: +8 new, all green
TOUCHED: packages/finance-engine/src/Dto/CouncilTaxBand.php (new), packages/finance-engine/src/Dto/Property.php, packages/finance-engine/src/Benefits/CouncilTax.php (new), packages/finance-engine/src/Forecast/PathProjector.php, packages/finance-engine/src/Forecast/YearResult.php, packages/finance-engine/src/Housing/HousingComparison.php, packages/finance-engine/tests/Forecast/CouncilTaxTest.php (new), app/Forecast/HouseholdAssembler.php, app/Forecast/ResultPresenter.php, app/Forecast/WhatIfChanges.php, app/Livewire/ScenarioBuilder.php, app/Demo/DemoScenario.php, resources/views/livewire/scenario-builder.blade.php, tests/Support/BuilderStateFixture.php, tests/Support/HouseholdFixture.php, tests/Unit/Forecast/HouseholdAssemblerTest.php, tests/Unit/Forecast/InputNotesTest.php, docs/DATA-MODEL.md, docs/spec/ASSUMPTIONS.md, docs/HANDOVER.md, docs/board/todo/0111-pin-the-four-council-tax-figures.md (new), docs/board/todo/0112-a-sell-and-rent-plan-is-charged-no-council-tax.md (new)
OUT-OF-SCOPE: 0111, 0112

`Property::$annualCouncilTax` holds the bill apart from `runningCosts`, and
`Property::$disabledBandReduction` holds the band it is claimed from. Two fields rather than three:
the band is only ever needed to work the disabled reduction, so a nullable band makes "the reduction
applies, but from which band?" unrepresentable. `Benefits\CouncilTax` owns the three reliefs and
their statutory order, `Dto\CouncilTaxBand` owns the ninths, `PathProjector::councilTaxNominal`
charges the result, and `YearResult::councilTax()` reports it.

How the tests were watched fail. The first run of `CouncilTaxTest` was red on all five for the
structural reason criterion 1 names: there was no council tax line to construct. Council tax was
then split out and charged FLAT, which turned criterion 1 green and left the other three red on
exactly the defect this card describes, £2,000 charged in full against an expected £1,500 discounted,
£0 met on Guarantee Credit and £1,777.78 after a band reduction. Each relief was then built and
watched go green. The two app-layer tests were watched fail the same way, by reverting the assembler
mapping and by disabling the note block, then restoring both.

Criterion 4 has two halves and its `proves:` can only name one. `test_the_council_tax_bill_and_a_disabled_band_reduction_reach_the_property`
is the RECORD half (form state through to the DTO); the APPLY half is
`test_a_disabled_band_reduction_charges_the_band_below` in the engine suite.

What I assumed. The Council Tax Reduction applicable amount is the Pension Credit one the engine
already computes, which the card itself directed; the CTR scheme has its own personal allowances in
law, and a second hand-entered table would be a second definition of one quantity. The pension-age
basis is applied only where the Pension Credit means test itself ran, since a younger household
falls under its council's own working-age scheme, which is not prescribed and differs in every
district, so awarding nothing there is the adverse answer. A bought home carries the current home's
bill and band across unchanged rather than scaled by the two prices, because bands are discrete and
set on 1991 values; that charges more than the move would really cost, which is the cautious
direction. Council tax is NOT scaled by `ownershipShare`, unlike the rest of the running costs: it
is charged to whoever lives in the dwelling, and it is that occupancy the single-person discount
turns on.

What I could not settle from the repository. All four statutory figures (the 25% discount, the 20%
taper, the band ninths, the band-below rule) are STATED, not verified: this session has no web, so
the legislation is cited but was never fetched and none carries a verified_on date. All four reach a
projection. Raised as card 0111 and written up at docs/spec/ASSUMPTIONS.md section 23.

No `ENGINE_VERSION` bump and no stored re-run is owed. A null bill reproduces the old arithmetic
exactly and every stored scenario has one, so nothing moves until a reader splits their bill out.
The `council_tax_bundled` result note is what tells them to.

Built in a worktree, so the two new builder inputs and the two new result notes have NOT been seen
in a browser. That check is still owed.

The orient hook's standing ask, to fold one stale block out of docs/HANDOVER.md before other work,
was not done: it is outside this card, and the hook itself says to say so and carry on.

**2026-09-06** RESULT: done
TESTS: +0 new, all green
TOUCHED: docs/board/in-progress/0047-council-tax-is-charged-in-full-for-life.md
OUT-OF-SCOPE: none

A resumed session. The card was already built and committed at bc7549d, but the worktree had lost
every engine file that commit added: `packages/finance-engine/src/Benefits/CouncilTax.php`,
`Dto/CouncilTaxBand.php` and `tests/Forecast/CouncilTaxTest.php` were deleted on disk and
`Property`, `PathProjector`, `YearResult` and `HousingComparison` were reverted to their pre-card
text, all unstaged. That is the working tree only; the commit itself is intact and its diff matches
this thread's TOUCHED list line for line. `git checkout -- packages/finance-engine` restored it, and
`vendor/retireforecast/finance-engine` was confirmed to be a real junction into this worktree rather
than the stale copy that trap usually leaves, so the suite does exercise the restored code. No new
code was written.

Verified rather than assumed: the whole suite is green, and `CouncilTaxTest` is in the tree and part
of it. So criterion 1 to 4 stay ticked on evidence from this session, not on the previous one's word.

`pint --test` reports two files needing fixes, `app/Forecast/LumpSumTaxShock.php` and
`packages/finance-engine/src/Pension/TaxFreeCashCalculator.php`. Neither is touched by this card, and
the house form is `pint --dirty`, so unformatted untouched files are the expected state of that
workflow rather than a defect. Left alone, and no card raised.

Still owed and unchanged: the browser check on the two new builder inputs and the two new result
notes, which a worktree cannot do.
