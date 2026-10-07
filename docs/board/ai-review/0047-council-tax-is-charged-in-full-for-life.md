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


## Links

**Relates to**
- `0046` - Pension Credit itself is that card, and Council Tax Reduction is computed off the same
  applicable amount.

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

## Plan
The Council Tax Reduction calculation can reuse the Pension Credit applicable amount the engine
already computes.

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

### 2026-09-06 review (v20260906183724-b383)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 266s, run by this job rather than reported by the card.

**acceptance: sound**

I traced each criterion to real code.

**#1 own cost line** ÔÇö `Property::$annualCouncilTax` sits beside `$runningCosts` (`packages/finance-engine/src/Dto/Property.php`), is charged by `PathProjector::councilTaxNominal` and reported by `YearResult::councilTax`. Test `test_council_tax_is_its_own_cost_line_beside_maintenance_and_insurance` exists.

**#2 single-person discount** ÔÇö `CouncilTax::liabilityAnnual` takes a `$singleOccupant` flag; `PathProjector::councilTaxNominal` passes `$aliveCount === 1`. Charged after, not before, the survivor spend factor, so the card's actual bug is fixed. Test exists.

**#3 Council Tax Reduction** ÔÇö `CouncilTax::reductionAnnual` uses the Pension Credit award (passport, capital limit, 20% taper on income above the applicable amount). Gated on `$award !== null`, so pension-age only. Test exists.

**#4 disabled band** ÔÇö record half: `ScenarioBuilder` rules/blank state `property.councilTaxDisabledBand` ÔåÆ `HouseholdAssembler` maps to `CouncilTaxBand`; test `test_the_council_tax_bill_and_a_disabled_band_reduction_reach_the_property`. Apply half: `CouncilTaxBand::reducedNinths` used in `CouncilTax::liabilityAnnual`; test `test_a_disabled_band_reduction_charges_the_band_below`.

I tried to break it and could not. The renting gap (`homeSold` charges nothing) is real but is card 0112, not this card.

VERDICT: sound

**scope: defect**

Findings (scope lens):

**Over the fence / grew:** nothing crossed into Pension Credit. `Benefits\CouncilTax::reductionAnnual` only *reads* `PensionCreditResult`; the calculator is untouched. Fence held.

**Half done ÔÇö the split was never done in the demo.** The card's first task is "split council tax out of `runningCosts`". In `app/Demo/DemoScenario.php`, `baseState()`, `runningCosts` stays `'5000'` and `'councilTax' => '2200'` is added *beside* it. The old `Property` docblock defined `runningCosts` as maintenance + insurance + **council tax**, so the demo household now pays its council tax twice: essential spend rises about ┬ú2,200 a year against the demo everyone opens first. Same shape in `tests/Support/HouseholdFixture.php` `household()` and `tests/Support/BuilderStateFixture.php` `full()` (6,400 + 2,100), which is why no test catches it.

**Declared, not hidden:** the four statutory figures are stated with no `verified_on` (card 0111), a renter is charged no council tax at all (card 0112), and the browser check is still owed. All three are written on the card, so they are deferrals, not creep.

VERDICT: defect

**breakage: defect**

Findings, lens = breakage.

**1. PLSA benchmark loses the bill.** `ResultPresenter::plsaBenchmark()` builds comparable spend from `expenseProfile` plus `primaryResidence->runningCosts` only. Council tax is now a separate field and is never added back, so a user who does what the new `council_tax_bundled` note tells them (move the bill out of running costs into the Council tax box) sees comparable spend drop by the whole bill and can fall a PLSA tier. Silent, and no test builds a split bill against the benchmark.

**2. A bought home is charged council tax twice.** `HousingComparison::newHomeRunningCosts()` falls back to 1% of value, and `ResultPresenter::assumedFigures()` and the computed-figure note both describe that figure as covering "maintenance, insurance and council tax". The new property then also copies `annualCouncilTax` across. Both notes are now false, and on the fallback path the bill is charged inside upkeep and again as its own line.

**3. Care is not occupancy.** `PathProjector::councilTaxNominal()` reads `aliveCount === 1`. A partner in permanent care is a disregarded person, so the survivor at home is charged the couple's rate. No test builds it.

VERDICT: defect


**2026-09-06** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 4 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 4 of 4 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.

**2026-09-28** Manager pass: reopened #1 and #2 because the findings hold on main. #1: the bill is not yet separate from upkeep everywhere. `DemoScenario::baseState()` still has `runningCosts` 5000 beside the new `councilTax` 2200, so the demo pays council tax twice (same in `HouseholdFixture::household()` and `BuilderStateFixture::full()`); `HousingComparison::newHomeRunningCosts()` falls back to 1% of value described as including council tax, then copies `annualCouncilTax` as well; and `ResultPresenter::plsaBenchmark()` adds `runningCosts` but never the council tax line, so moving the bill out drops comparable spend. #2: `PathProjector::councilTaxNominal()` still applies the discount on `aliveCount === 1`, so a survivor at home whose partner is in permanent care (a disregarded person) pays the couple's rate.

**2026-10-07** RESULT: done
TESTS: +2 new, 1 extended, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php, packages/finance-engine/tests/Forecast/CouncilTaxTest.php, app/Forecast/ResultPresenter.php, app/Forecast/ScenarioForecaster.php, app/Demo/DemoScenario.php, resources/views/livewire/scenario-builder.blade.php, tests/Support/HouseholdFixture.php, tests/Support/BuilderStateFixture.php, tests/Unit/Forecast/PlsaBenchmarkTest.php, tests/Unit/Forecast/ComputedRunningCostsDisclosureTest.php, docs/HANDOVER.md, docs/board/todo/0180-a-home-left-empty-for-care-is-charged-full-council-tax.md (new)
OUT-OF-SCOPE: 0180

This is the rework of the 2026-09-28 manager pass that reopened #1 and #2.

#2, care is not occupancy. `test_the_single_person_discount_applies_once_one_member_remains` is extended with a couple whose second member enters care at 85, both alive, run through `PathProjector` with a care-draws stub. It was watched fail at 200000 against 150000 (the couple's bill charged while one partner was in care). `PathProjector::projectYear` now counts the members alive and NOT in care this year (`careAnnualCost` above zero is the engine's only 'in care' signal) and passes that to `councilTaxNominal`, renamed `$occupants`. With nobody at home it falls back to the living count, which is the old behaviour. A home left empty because everyone is in care is Class E exempt in England, but this session had no web to fetch the regulation, so it is card 0180 rather than built.

#1, the bill is held apart everywhere. Three of the reviewer's findings:
- PLSA: `ResultPresenter::plsaBenchmark` adds the gross `annualCouncilTax` back to the running costs. New test `test_a_council_tax_bill_held_apart_is_still_counted_in_comparable_spend`, watched fail at 21,000 against 23,000.
- Bought home: the engine never charged twice. The 1% rate is a MAINTENANCE rate (Checkatrade), and the bill is copied across as its own line. What was false was the description. The assumed-figure note and the computed-figure note no longer say the upkeep covers council tax, and they now say the bill is charged on top at the current home's figure. The builder's buy-running-costs hint also stops asking for council tax there, because a bill typed into both boxes WOULD be charged twice. New test `test_the_upkeep_of_a_bought_home_never_claims_the_council_tax_charged_beside_it`, watched fail on the 'insurance and council tax' wording.
- Demo double count: `DemoScenario` running costs go from 5000 to 2800, so running costs plus the 2200 bill equal the pre-split 5000. The two test fixtures got the same split (6400 to 4300). No test pins the demo figure. A test that asserted 2800 + 2200 = 5000 would only restate the edit, so none was written.

The #1 `proves:` name is the engine test and is unchanged. It still proves the engine line. The app-side halves are the two new tests above.

`ENGINE_VERSION` is bumped to `finance-engine/council-tax-single-occupant-beside-care`. The stored re-run is owed only for two-member plans with a split bill and a care spell.

Still owed: a browser check of the changed upkeep notes and the builder hint, because this was built in a worktree. The four statutory figures are still unverified (card 0111). The orient hook asked again for a HANDOVER.md fold-out. That is outside this card, so it was not done.
