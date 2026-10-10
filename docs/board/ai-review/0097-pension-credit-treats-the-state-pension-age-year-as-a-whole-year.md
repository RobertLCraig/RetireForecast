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
- [x] WHEN a household reaches Pension Credit qualifying age part way through a year, THE APP SHALL award only the part of the year after that date. proves: `test_pension_credit_is_awarded_only_from_the_qualifying_age_date`
- [x] WHEN the assessable income of that year is a part year of State Pension, THE APP SHALL test it against the matching part of the year, not against fifty-two weeks. proves: `test_the_qualifying_year_is_means_tested_on_the_weeks_it_covers`
- [x] WHEN a deferred claimant reaches State Pension age part way through a year, THE APP SHALL count only the notional pension of the part after that date. proves: `test_the_notional_deferred_pension_is_prorated_in_the_year_it_would_have_started`
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

**2026-10-10** RESULT: done
TESTS: +3 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/PathProjectorTest.php
packages/finance-engine/tests/Forecast/StatePensionDeferralTest.php
app/Forecast/ScenarioForecaster.php
docs/DECISIONS.md
docs/HANDOVER.md
docs/board/todo/0194-housing-support-is-awarded-for-the-whole-qualifying-year.md
docs/board/todo/0195-the-deferred-claim-year-counts-no-notional-pension.md
OUT-OF-SCOPE: 0194, 0195

Shape (task 1): a shorter award period, not a whole-year figure scaled at the end. New `pensionCreditAwardPeriod` is the part of the year after the LATEST living member's State Pension age date, on `startFraction` (task 3: the fraction comes from the same person the gate does). In that year the State Pension, paid or notional, is read over the period and divided by it into a weekly rate; other income keeps its annual rate over 52 weeks. The award is paid for weeksPerYear x period. With period 1 (every other year) the arithmetic is the old one, so those years are byte-identical; GoldenMasterTest did not move. `ENGINE_VERSION` is `finance-engine/pension-credit-from-the-qualifying-date` (task 4). DECISIONS 2026-10-10 and a HANDOVER line record it.

Each test was watched failing first: #1 paid 4x the expected (a whole year where a quarter is due), #2 paid 1,163,656 where 188,487 is due (part-year pension spread over 52 weeks), #3 paid exactly the whole-year twin's award (a whole year of notional pension against a whole year). #3 also fails if only #1 is built, but its exact figure is what pins the notional to the part after the date: prorating the notional AND keeping it over 52 weeks would over-award and fail it too. A December date gives a nil period and a nil award, by the house month convention.

Assumed: income other than the State Pension is spread evenly through the qualifying year. Left as it was, raised instead: Housing Benefit and Council Tax Reduction still pay the whole qualifying year (0194); the year a deferred claim starts still counts no notional pension (0195). Engine only, nothing on a screen moved, so no browser check is owed beyond the stored-scenario re-run.
