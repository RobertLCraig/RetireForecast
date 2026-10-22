# One fixed DB rate serves both deferment and payment

## Why
Found by the 2026-09-05 scope review of card 0035, repeated by the 2026-09-28 manager pass, and
still true on 2026-10-06. `DbPension` carries one `fixedEscalationRate`. `PathProjector` flattens it
to one `fixedRate` per scheme and `escalateDbPensions()` passes it to both phases. The builder shows
one box when either dropdown says Fixed. So a scheme that revalues at a fixed rate in deferment and
escalates at a different fixed rate in payment cannot be entered.

## Links

**Relates to**
- `0035` - separated the two bases but not the rate.
- `0095` - sources the default fixed rate.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a scheme is set to Fixed in both phases with two different rates, THE APP SHALL revalue at the deferment rate until normal retirement age and escalate at the in-payment rate after it. proves: `test_a_fixed_db_scheme_uses_its_own_rate_in_each_phase`
<!-- AC:END -->

## Plan
Split `fixedEscalationRate` into a revaluation rate and an in-payment rate on `DbPension`, the
builder and `HouseholdAssembler`, and pass the phase's own rate in `escalateDbPensions()`. A new
builder field moves four things together (blank default, validation, load backfill,
`BuilderStateFixture::full`). Each blank rate stays disclosed as an assumed figure.

## Comments
