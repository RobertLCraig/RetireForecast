# A renter who unticks "leaving your home to your children" loses the downsizing addition

## Why
`PathProjector::recordFinalDeathIht()` passes the recorded disposal to the calculator only when
`ForecastSettings::$homeToDescendants` is true. The builder labels that checkbox "Leaving your home
to your children / direct descendants". A sell-and-rent household owns no home at death, so
unticking it is the natural reading, and the downsizing addition then vanishes with no warning.

The statute asks whether the estate's OTHER assets pass to direct descendants, not whether a home
does, and `InheritanceTaxCalculator::downsizingAddition()` already caps on the non-home assets. So
on a plan with no home, the flag is gating on a question the reader was not asked.

Found by the card 0053 review (scope lens, finding 2) and left for the builder to judge; out of
that card's criteria. The same flag also stands in for "there are issue" at the first death
(`issueTakeUnderIntestacy`), so changing its meaning is not a one-line fix.

## Links

**Relates to**
- `0053` - added the disposal and the gate.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a plan owns no home at death but its estate passes to direct descendants, THE APP SHALL still apply the downsizing addition. proves: `test_a_renter_leaving_the_estate_to_children_keeps_the_downsizing_addition`
<!-- AC:END -->

## Plan
Decide first whether the checkbox means "the estate passes to direct descendants" (relabel it, and
the gate stands) or "the home does" (then the addition needs its own question). The relabel is the
smaller change and also matches its use at the first death.

## Comments
