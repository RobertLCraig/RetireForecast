# A pension nominated away from the spouse is still inherited by them

## Why
Found while building card 0057. That card made the Inheritance Tax spouse exemption on a pension
follow `DcPension::$nominatedBeneficiary`, so a pot nominated to a child is now correctly chargeable
on the first death. What it did NOT change is where the money goes.

`PathProjector::settleEstates` moves every one of the deceased's pots to the surviving partner
whatever the nomination says. So a pot nominated to a child is taxed as though it left the household
and then spent as though it never did: the survivor draws it for their own shortfalls, it grows in
their name, and it is counted again in the estate at the final death.

That is a completeness fault in both directions at once. The plan pays first-death Inheritance Tax
on money it keeps, and the survivor's income, wealth and success odds are all propped up by money a
scheme would have paid to somebody else. It bites hardest on exactly the household card 0057 was
raised for: a large pot, an expression of wish naming a child, and a long survivor period.

## Links

**Relates to**
- `0057` - added the nomination and routed the exemption off it; deliberately left the settlement
  alone, because moving the money is a projection change and that card was scoped to the tax.
- `0040` - the same function's per-person settlement, and the split rules it already keeps.

## Not this card
The beneficiary's income tax on the pot, which card 0057 built.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a DC pension is nominated to somebody other than the surviving spouse, THE APP SHALL remove it from the household at the member's death rather than pass it to the survivor. proves: `test_a_pot_nominated_to_a_child_leaves_the_household_at_the_first_death`
- [ ] THE APP SHALL keep the first death's Inheritance Tax on that pot unchanged, so the tax and the settlement describe the same event. proves: `test_the_first_death_tax_still_charges_the_pot_that_left`
<!-- AC:END -->

## Tasks
- [ ] Carry `nominatedToSpouse` through the settlement in `PathProjector::settleEstates`, dropping a
      pot the survivor does not inherit instead of adding it to their pots.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`: every two-person plan with a pot nominated away
      from the spouse loses that money from the survivor's years.
- [ ] Expect the Monte Carlo golden master to stay put (its fixture has no such nomination) and say
      so, or re-pin it per that test's own docblock.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The pot array already carries `nominatedToSpouse`
(card 0057 put it there for the tax), so the fix is one branch in the settlement loop.

`InheritedPensionForecastTest` builds the couple this needs: two pots, both deaths past 75, the
nomination switchable. Assert on the survivor's wealth in a year after the first death, which is
where the difference shows, and watch it fail before the fix.

Run `php artisan test` after.

## Comments
