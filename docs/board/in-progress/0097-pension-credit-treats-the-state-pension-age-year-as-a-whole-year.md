# Pension Credit is awarded for the whole of the year State Pension age is reached

## Why
Pension Credit has a qualifying-age gate: every living member of the household must be at or over
State Pension age. `PathProjector::meansTestedBenefitNominal` reads that gate a **calendar year at a
time**, `if ($calendarYear < $state['spaYear'][$person->id]) return 0;`, and then awards
`guaranteeCreditWeekly * weeksPerYear`. So a household whose younger member reaches State Pension
age in November is paid **fifty-two weeks** of Guarantee Credit in that year, where the entitlement
is about eight.

Card 0036 made it worse in the same year, for a good reason. The State Pension is now correctly
part-paid in the year it starts, so the household's **assessable income** in that year is a part
year of pension divided across a whole year of weeks. A lower weekly assessable income against an
unchanged weekly applicable amount raises the weekly award, and that raised award is then paid for
fifty-two weeks. Both errors run the household's way, in the transition year card 0036 was raised
about because that is where an affordability cliff shows.

A third instance sits in the same block and runs the other way:
`notionalDeferredStatePensionNominal` counts a **whole** year of notional undeferred State Pension
in the deferral window, including the year State Pension age is reached, where only the part after
that date is income the claimant could have been receiving. It is small and cautious, but it is the
same fault and it should move with the rest.

`initialState` now keeps `spaMonth` per person, so all three have the month they need.

## Links

**Relates to**
- `0036` - prorated the State Pension, the DB pension and National Insurance in the transition year,
  and left the means test alone: its acceptance named three incomes and this is a fourth surface.

## Not this card
The State Pension figure itself, and the National Insurance beside it. Both are card 0036's and are
built.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a household reaches Pension Credit qualifying age part way through a year, THE APP SHALL award only the part of the year after that date. proves: `test_pension_credit_is_awarded_only_from_the_qualifying_age_date`
- [ ] WHEN the assessable income of that year is a part year of State Pension, THE APP SHALL test it against the matching part of the year, not against fifty-two weeks. proves: `test_the_qualifying_year_is_means_tested_on_the_weeks_it_covers`
- [ ] WHEN a deferred claimant reaches State Pension age part way through a year, THE APP SHALL count only the notional pension of the part after that date. proves: `test_the_notional_deferred_pension_is_prorated_in_the_year_it_would_have_started`
<!-- AC:END -->

## Tasks
- [ ] Decide the shape first: whether the qualifying year is modelled as a shorter award period or
      as a full-year assessment scaled at the end. The second is a smaller change and gives the same
      annual figure while both the applicable amount and the assessable income are whole-year rates;
      the first is the honest one if any weekly figure ever reaches a screen.
- [ ] Apply the same fraction to `notionalDeferredStatePensionNominal`.
- [ ] Check what a household of two with different State Pension ages should get: today the gate is
      the LATER of the two, which stays right, but the fraction must come from that same person.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`: a plan that draws Pension Credit at all and has a
      member reaching State Pension age inside its horizon banks less in that year under the fix.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. Both the gate and the notional figure are in
`packages/finance-engine/src/Forecast/PathProjector.php` (`meansTestedBenefitNominal` and
`notionalDeferredStatePensionNominal`); the month is already in projector state as
`$state['spaMonth']`, and `startFraction` in the same file is the convention to reuse. The fixtures
are `packages/finance-engine/tests/Forecast/PathProjectorTest.php` (the Pension Credit cases) and
`StatePensionDeferralTest.php`. Run `php artisan test --testsuite=Engine`, then the full suite.

## Comments
