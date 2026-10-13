# One home's own price shock compounds every year, where the evidence says it is mostly one-off

## Why
Found building card 0086. `AssumptionSet::singlePropertyVolatility()` scales the yearly index
house-price shock by `SINGLE_PROPERTY_VOLATILITY_MULTIPLE` (2.0), so over T years the home's spread
is 2 x 9% x sqrt(T): the house-specific part grows like a random walk.

The repeat-sales evidence in `docs/research/RESEARCH-0086-single-property-volatility.md` (Finding 2)
says the house-specific shock is mostly ONE-OFF: about 10-18% in total, taken at sale, and it "does
not scale with the (square root of the) holding period" (Giacoletti). Over 20 years the evidence puts
one home at about 1.1x to 1.4x the index; the engine gives 2.0x at every horizon.

This errs cautious, so it is not a safety fault. It does over-widen the fan on long stay-put plans,
which lowers their success probability against selling plans and can change which plan ranks
first. Card 0086 could not change it: its "Not this card" excludes the mechanism.

## Links

**Relates to**
- `0086` - sourced the 2.0 and left the compounding mechanism as it was.
- `0029` - built the mechanism.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a plan keeps one home for 20 years with no per-home volatility entered, THE APP SHALL give that home's real value a spread that is the index spread widened by a one-off house-specific shock, not the index spread doubled every year. proves: `test_the_house_specific_shock_does_not_compound_with_the_holding_period`
<!-- AC:END -->

## Tasks
- [ ] Decide the one-off shock size from the research note (Giacoletti 9.6-17.8% per resale; take the cautious end) and where it is applied (at sale, and to the estate at death).
- [ ] Keep the user's "Your home's price swing" input meaningful, and disclose the new figure reading its constant.
- [ ] Update ASSUMPTIONS.md §13 and bump `ScenarioForecaster::ENGINE_VERSION`.
