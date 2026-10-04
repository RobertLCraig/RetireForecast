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

**Relates to**
- `0112` - the same missing figure on the sell-and-rent plan. Settle where a renter's bill comes
  from once, for both.
- `0088` - kept the let home's bill as the stand-in and said so on the result.

## Not this card
The owner's council tax and its reductions (card 0047). The let home's running costs and repairs
(card 0088).

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a let home's household rents elsewhere, THE APP SHALL charge council tax on the rented home, not on the let one. proves: `test_a_let_and_rent_plan_is_charged_council_tax_on_the_home_it_rents`
- [ ] THE APP SHALL state on the result which council tax figure the plan was charged and where it came from. proves: `test_the_let_and_rent_council_tax_note_names_the_rented_homes_bill`
<!-- AC:END -->

## Plan
`PathProjector::councilTaxNominal` charges the let home's `annualCouncilTax`. The note is the
`letting_caveats` branch in `ResultPresenter::inputNotes()`. Do this after card 0112 decides where a
renter's bill comes from, and reuse that answer.

## Comments
