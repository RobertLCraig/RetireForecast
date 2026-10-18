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
- [x] WHEN a DC pension is nominated to somebody other than the surviving spouse, THE APP SHALL remove it from the household at the member's death rather than pass it to the survivor. proves: `test_a_pot_nominated_to_a_child_leaves_the_household_at_the_first_death`
- [x] THE APP SHALL keep the first death's Inheritance Tax on that pot unchanged, so the tax and the settlement describe the same event. proves: `test_the_first_death_tax_still_charges_the_pot_that_left`
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

**2026-10-18** RESULT: done
TESTS: +2 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: packages/finance-engine/tests/Forecast/InheritedPensionForecastTest.php
TOUCHED: app/Forecast/ScenarioForecaster.php
TOUCHED: docs/HANDOVER.md
TOUCHED: docs/board/todo/0206-an-unanswered-pension-nomination-is-taxed-as-leaving-but-kept.md
OUT-OF-SCOPE: 0206

Built: each pot now carries `nominatedAway` (answered SomeoneElse), and settleEstates does not pass such a pot to the survivor. ENGINE_VERSION bumped to finance-engine/pot-nominated-away-leaves-the-household. Both new tests were watched failing first for the card's reason: the survivor kept the pot (90.6m pence vs a 53.7m ceiling) and the household lost 0 where the first-death tax said 430k left it.

Scope call: the card's Task said carry `nominatedToSpouse`, but that flag reads an UNANSWERED nomination as not-the-spouse. Dropping on it took the pot from every default couple plan and reddened 18 tests, which is a change the card did not decide. So only an answered SomeoneElse leaves; null still passes. The leftover inconsistency (null is taxed as leaving but kept) is card 0206, which needs research into what schemes do with no expression of wish.

Re-examined test: test_a_pension_nominated_away_from_the_spouse_costs_the_first_death asserted the TOTAL IHT is higher when nominated away. That only held because the pot was taxed at both deaths, the double count this card removes. Its last assertion now checks the final estate no longer holds the pot; its first-death assertions are unchanged.

Monte Carlo golden master stayed put, as the card expected (its fixture has no SomeoneElse nomination). No UI changed, so no browser check is owed.
