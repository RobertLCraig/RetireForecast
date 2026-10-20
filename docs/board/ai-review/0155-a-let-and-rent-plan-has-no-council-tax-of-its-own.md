---
needs: 0112
---
# A let-and-rent plan has no council tax of its own

## Why
A household that lets its home and rents another place pays council tax on the place it rents.
The forecast has no figure for that home. It charges the let home's council tax instead, although
that bill is the tenant's.

Card 0088 kept that bill on purpose. Dropping it would leave the plan paying no council tax at
all, which flatters letting. The `letting_caveats` note says the let home's bill stands in for the
real one. But a stand-in is not the real figure. The rented home is often smaller and in a lower
band, so the forecast can overcharge. Where the household moves to a dearer area, it undercharges.

It is the let-and-rent sibling of card 0112 (sell-and-rent pays no council tax at all). Both need
the same figure: the council tax on the home the household rents.

## Links

**Blocked by**
- `0112` - the same missing figure on the sell-and-rent plan. Settle where a renter's bill comes
  from once, for both.

**Relates to**
- `0088` - kept the let home's bill as the stand-in and said so on the result.
- `0047` - owns the owner's council tax and its reductions, which this card leaves alone.

## Not this card
The owner's council tax and its reductions (card 0047). The let home's running costs and repairs
(card 0088).

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN a let home's household rents elsewhere, THE APP SHALL charge council tax on the rented home, not on the let one. proves: `test_a_let_and_rent_plan_is_charged_council_tax_on_the_home_it_rents`
- [x] THE APP SHALL state on the result which council tax figure the plan was charged and where it came from. proves: `test_the_let_and_rent_council_tax_note_names_the_rented_homes_bill`
<!-- AC:END -->

## Plan
`PathProjector::councilTaxNominal` charges the let home's `annualCouncilTax`. The note is the
`letting_caveats` branch in `ResultPresenter::inputNotes()`. Do this after card 0112 decides where a
renter's bill comes from, and reuse that answer.

## Comments

**2026-10-20** RESULT: done
TESTS: +3 new, all green
TOUCHED: app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
docs/HANDOVER.md
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/CouncilTaxTest.php
tests/Unit/Forecast/InputNotesTest.php
tests/Feature/Forecast/LetAndRentCouncilTaxTest.php
OUT-OF-SCOPE: none

Reused card 0112's answer: the housing-step box 'Council tax on the rented home' (HousingAction::$rentCouncilTax). Blank carries the let home's bill, as 0112 carries the current bill to a rent plan, so a plan with the box empty does not move.

Engine: PathProjector::councilTaxNominal charges settings->rentCouncilTax to a let home that is not sold, as it already did for a renter. App: ScenarioForecaster::buildSettings passes the scenario's rentCouncilTax when the home is let. ENGINE_VERSION is finance-engine/let-and-rent-pays-the-rented-homes-council-tax; the re-run is owed only for let plans with that box filled. No new input, so no builder change.

Note: the letting_caveats council tax sentence names the entered bill and says it is the one entered for the home you rent. Where the let home's bill is still bundled in its running costs, the note says that bill is charged too, because the forecast cannot separate it from upkeep (a double charge in that one case, disclosed, not fixed: separating it is the reader's 'own box' input).

Watched failing first: the engine test charged 177778 (the let home's band-reduced bill) not 150000; the note test found the let home's 2,000 bill and no 1,500; the extra wiring test (LetAndRentCouncilTaxTest) charged 210000, not 123400, with the ScenarioForecaster change reverted.

Not checked: a browser look at the note. Herd serves C:\Dev\RetireForecast, not this worktree. The builder box was already visible on every variant's housing step; its hint ('Leave blank to charge the same bill as your home now') reads true for a let plan too.
