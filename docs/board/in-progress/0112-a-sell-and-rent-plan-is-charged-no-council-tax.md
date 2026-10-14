# A sell-and-rent plan is charged no council tax at all

## Why
A household that sells and rents still pays council tax. The forecast charges it none.

Council tax is a cost of the DWELLING, not of owning one. A tenant of an ordinary self-contained
flat is the liable person and gets a bill in their own name, on top of the rent. In this model the
sell-and-rent variant has no property at all (`HousingComparison::rentVariant` passes a null
`Property` into `withHousing`), so both places council tax could be charged are gone: the home's
running costs and, since card 0047, its own `annualCouncilTax` line. The only housing cost left is
the rent figure the reader entered.

What it costs: every comparison the tool exists to run. Sell-and-rent is one of the three plans on
the ranked chart, and it is being handed a housing bill that is short by a whole council tax every
year for the rest of the plan, in a comparison three reviewers have already called too close to read
off. The direction is the wrong way round: it flatters renting against staying put, and the card
0047 work makes the gap worse rather than better, because an owner's bill now correctly falls with
the single-person discount while a renter's stays at zero.

It came to be this way because council tax was bundled inside `Property::runningCosts`, which the
sell variants strip on sale for the good reason that maintenance and insurance really do stop when
you no longer own the building. Nobody ever decided that the council tax inside that bucket should
stop with them.

## Links

**Relates to**
- `0047` - split council tax into its own line and made it shrink; it charges the line only while a
  home is owned, which is where this gap is now visible.
- `0022` - the keep-or-sell decision this misprices. Do not read the ranked comparison off until it
  is settled.

## Not this card
The owner's council tax, its three reductions and how they are assessed. All of that is card 0047's
and is built. This card only decides what a renter is charged and charges it.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a plan sells the home and rents, THE APP SHALL charge council tax on the rented home for every year of the tenancy. proves: `test_a_renting_household_is_charged_council_tax`
- [ ] WHEN only one person remains in a renting household, THE APP SHALL apply the single-person discount and any Council Tax Reduction, on the same rules an owner gets. proves: `test_a_renters_council_tax_falls_the_same_way_an_owners_does`
- [ ] THE APP SHALL state on the result which council tax figure a renting plan was charged and where it came from. proves: `test_the_renting_council_tax_note_states_what_is_charged`
<!-- AC:END -->

## Tasks
- [ ] Decide where a renter's bill comes from, and say so on the card before building: the same
      figure the current home carries, or its own input beside the rent.
- [ ] Charge it in `PathProjector` for a household with no home, alongside the rent.
- [ ] Extend `ResultPresenter`'s council tax note to the renting case.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. Run `php artisan test` first: it must be green before
anything changes.

The charge lives at one place, `PathProjector::councilTaxNominal`, which today returns zero when the
home is sold or absent. The reliefs it applies do not care whether the household owns or rents, so
the arithmetic is already right; what is missing is a figure to apply it to once
`$household->primaryResidence` is null.

The first task is the real decision and is worth a written answer before any code. Two shapes, and
the cheaper one is probably right:

1. **Carry the current home's bill across**, exactly as card 0047 carries it to a bought home. One
   line, no new input, and it is the cautious direction if the rented home is smaller. It is wrong
   in the case that matters most though, because somebody renting after selling usually moves
   somewhere cheaper, and it would charge them a band they left.
2. **Add a council tax input beside the rent**, on the housing step, blank meaning "the same as
   now". Honest, and it matches how `annualRent` and `buyRunningCosts` are already entered per
   plan, at the cost of one more field in four places (`blankProperty` or the housing block,
   validation, `loadState` backfill, `BuilderStateFixture::full`). See the note on that in
   CLAUDE.md before adding it.

Whichever is chosen, `HousingComparison::rentSettings` and `rentVariant` are where a rent-plan
figure would have to be threaded, and `ScenarioForecaster::ENGINE_VERSION` needs a bump: every
stored plan with a rent leg gets more expensive, so its wealth, depletion year and success odds all
move and a stored re-run is owed.

## Comments
