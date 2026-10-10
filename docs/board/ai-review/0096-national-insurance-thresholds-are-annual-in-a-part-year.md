# A part year of work is charged National Insurance against a whole year's threshold

## Why
Employee National Insurance is assessed **per pay period** in the real world: a monthly-paid earner
gets one twelfth of the primary threshold each month, and the year's liability is the sum of those
months. `PathProjector::niForPerson` charges it **annually** instead: it works out the year's
earnings, then hands the whole figure to `NationalInsuranceCalculator::onEmploymentEarnings`, which
applies the full annual primary threshold and upper earnings limit.

That is right for a whole year of work and wrong for a part year, and the projection now has two
part years:

- the **retirement year**, where salary is prorated by `workFraction` (the 2026-06-30 decision), and
- the **State Pension age year**, where card 0036 made NI due on the earnings before that date.

The under-charge is large, because the threshold is the whole of the shortfall. A £60,000 earner
who reaches State Pension age at the end of March is charged NI on £15,000 against a £12,570
threshold: about £194. Assessed as it really would be, three months of pay against three months of
threshold, it is about £948. The error always runs the household's way, and it lands in the same
transition year card 0036 was raised about.

Nothing here is visible to the reader: the year's NI is a component of one total-tax figure.

## Links

**Relates to**
- `0036` - made the State Pension age year part liable, which is what turned a single latent
  approximation into one that bites in two places. It left this deliberately, as its own scope was
  the three whole-year incomes.

## Not this card
Which months are liable. Card 0036 settled that, and its `startFraction` / `workFraction` pair is
the convention to keep.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN a person works only part of a year, THE APP SHALL charge National Insurance against the matching part of the annual thresholds. proves: `test_a_part_year_of_work_is_charged_against_a_part_year_of_thresholds`
- [x] WHEN a person works a whole year, THE APP SHALL charge exactly the National Insurance it charges today. proves: `test_a_whole_year_of_work_is_unchanged`
<!-- AC:END -->

## Tasks
- [ ] Scale the liable fraction through the calculator: charging the fraction of the NI due on the
      annualised rate of pay is the same arithmetic as prorating both thresholds, and needs no new
      calculator API.
- [ ] Check the same question for the employer-side and self-employment paths before assuming only
      the primary contribution is affected.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`: every plan with a working member who retires or
      reaches State Pension age inside its horizon pays more tax under the fix.
- [ ] Remove the v1-limit note `niForPerson` now carries pointing at this card.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The charge is `niForPerson` in
`packages/finance-engine/src/Forecast/PathProjector.php`; the calculator is
`packages/finance-engine/src/Tax/NationalInsuranceCalculator.php` and its own thresholds are the
sourced annual figures, which should not move. The fixtures are
`packages/finance-engine/tests/Forecast/TransitionYearProrationTest.php` and
`NiCategoryForecastTest.php`. Run `php artisan test --testsuite=Engine`, then the full suite.

## Comments

**2026-10-10** RESULT: done
TESTS: +2 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: packages/finance-engine/tests/Forecast/TransitionYearProrationTest.php
TOUCHED: app/Forecast/ScenarioForecaster.php
OUT-OF-SCOPE: none

niForPerson now charges the liable fraction of the NI due on the annual rate of pay. That is the same as prorating both the primary threshold and the upper earnings limit, and the calculator API is unchanged. I removed the v1-limit note that pointed at this card. ENGINE_VERSION is now finance-engine/part-year-ni-thresholds.

The new part-year test failed first for the reason the card gives: it got 19440 (GBP 194.40, the annual threshold) where it expected 80265. It checks both part years: the State Pension age year and the retirement year.

Correction to the card's figure: GBP 60,000 for 3 months is GBP 802.65, not about GBP 948. The card prorated only the primary threshold. Per-period payroll prorates the upper earnings limit too, so the GBP 15,000 is (12,567.50 - 3,142.50) x 8% + (15,000 - 12,567.50) x 2%. The test pins 802.65, worked by hand from the 2026-27 figures.

The whole-year test passed before the fix and after it. It is a regression guard: the criterion is 'unchanged', so there was no failing case to watch.

The existing test_national_insurance_is_charged_on_the_earnings_before_state_pension_age expected the old annual-threshold figure for 2032. I updated that expectation to the per-period figure because the change was deliberate. I did not loosen it.

Task 2: PathProjector has no employer (secondary) NI and no self-employment NI. Its only NI call is the primary Class 1 call in niForPerson, so the change covers every NI path. MonteCarlo GoldenMasterTest stayed green: its fixture has no working member in a part year, so I did not re-pin it.

Not done: a browser check (Herd serves C:\Dev\RetireForecast, not this worktree). After the merge, re-run the stored scenarios and php artisan scenarios:audit, because the engine version changed.
