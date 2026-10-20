# The cheapest order can now be one the reader cannot pick

## Why
Card 0075 closed the gap where the page named a cheaper draw order the reader had no way to run:
`DrawdownStrategy` became a builder control, so all three named orders can be chosen.

Card 0078 then widened the search with GENERATED candidates ("keep each person's taxable income
under £X a year", tried at the personal allowance and at the basic-rate ceiling). Those are not
`DrawdownStrategy` cases and there is no builder control for the target, so the panel can once again
say "the cheapest is keeping each person's taxable income under £50,270 a year, it pays £2,992 less
tax across your plan" and offer no way to model it. It is measurably reachable: the criterion-1 test
in `tests/Feature/Forecast/ScenarioForecasterTest.php` pins a household where a generated order wins.

The target already travels to the projector on `ForecastSettings::$taxableIncomeTargetPence`, so what
is missing is the input route: a builder field, its validation, its `loadState` backfill and its
`BuilderStateFixture::full()` entry (the four things a new builder field moves together), plus the
sparse `assumptionOverrides` read that keeps a scenario stored earlier byte-identical.

## Links

**Relates to**
- `0075` - made the named orders pickable; this is the same gap re-opened for the generated ones.
- `0078` - built the generated candidates and left this open on purpose.

## Not this card
**Widening the candidate set further.** The bound is 4 to 6 forecasts a page render and it is at 5.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the results page names a generated order as the cheapest, THE APP SHALL let the reader
      run their own forecast on that order.
      proves: `test_a_taxable_income_target_can_be_chosen_and_reaches_the_forecast`
- [ ] #2 THE APP SHALL leave a scenario that states no target byte-identical to what it forecasts
      today. proves: `test_a_scenario_without_a_taxable_income_target_is_unchanged`
<!-- AC:END -->

## Tasks
- [ ] Carry the target through `AssumptionOverrides` as a sparse key, like `drawdownStrategy`
- [ ] Add the builder field, its validation, its `loadState` backfill and its
      `BuilderStateFixture::full()` entry together
- [ ] Disclose it in `ResultPresenter::assumedFigures()` where it is the engine's and not the reader's

## Comments
