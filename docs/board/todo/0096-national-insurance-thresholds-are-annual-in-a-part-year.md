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
- [ ] WHEN a person works only part of a year, THE APP SHALL charge National Insurance against the matching part of the annual thresholds. proves: `test_a_part_year_of_work_is_charged_against_a_part_year_of_thresholds`
- [ ] WHEN a person works a whole year, THE APP SHALL charge exactly the National Insurance it charges today. proves: `test_a_whole_year_of_work_is_unchanged`
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
