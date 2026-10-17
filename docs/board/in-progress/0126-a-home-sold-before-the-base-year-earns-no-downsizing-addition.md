# A home sold before the plan starts earns no downsizing addition

## Why
Card 0053 built the Inheritance Tax downsizing addition, but only for a disposal the MODEL makes:
the year-0 sell variants and an in-projection forced sale. `Household::$formerResidenceDisposal` is
derived, and there is no builder field behind it.

So a household that already downsized, say in 2020, and comes to this tool in 2026 has no disposal
on record and gets no addition. The statute would give them one, and the amount is worth up to
£175,000 of band per person. Their forecast therefore overstates the Inheritance Tax on every
option, including staying put, which is the option a household that has already downsized is most
likely to be weighing.

Nothing tells them either: the results page names the addition only when there IS one, so the
absence reads as "the rule does not apply to me".

## Links

**Relates to**
- `0053` - built the rule; this is the input it has no way to receive.

## Not this card
The arithmetic, which is settled and tested.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL let a reader record a former main home sold before the plan starts, with its value and the year. proves: `test_a_disposal_entered_in_the_builder_earns_a_downsizing_addition`
- [ ] THE APP SHALL keep a modelled disposal and an entered one on one definition, so a plan that sells again does not claim two additions. proves: `test_a_modelled_sale_replaces_an_entered_prior_disposal`
<!-- AC:END -->

## Tasks
- [ ] Add the builder field. **Four things move together**: the blank default, validation,
      `loadState` backfill and `BuilderStateFixture::full`.
- [ ] Map it through `HouseholdAssembler` to `Household::$formerResidenceDisposal`, and decide the
      precedence against the disposal the housing transforms set.
- [ ] Check the value basis: the statute wants the value of the interest disposed of, net of what
      was secured on it, which is what the engine records for its own disposals.
