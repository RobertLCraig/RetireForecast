# The year a deferred State Pension claim starts counts no notional pension

## Why
Found building card 0097. During State Pension deferral, Pension Credit counts the notional
undeferred pension as income (`PathProjector::notionalDeferredStatePensionNominal`). Its window
ends at the START of the claim year, so in that year the months before the claim date count no
pension at all, and the part year actually paid is spread over 52 weeks. A claimant deferring to
a November date is assessed on about one month of pension for the whole year, and Pension Credit
tops up the rest. Deferring is meant not to conjure Pension Credit, and in that one year it does.

## Links

**Relates to**
- `0097` - prorated the notional pension in the State Pension age year, and left the claim year
  as it was so the card did not grow.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a deferred claim starts part way through a year, THE APP SHALL count the notional pension for the part of that year before the claim date. proves: `test_the_claim_year_counts_the_notional_pension_before_the_claim`
<!-- AC:END -->

## Tasks
- [ ] Extend the notional window into the claim year, for the part before `startFraction` of the claim month.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`.
