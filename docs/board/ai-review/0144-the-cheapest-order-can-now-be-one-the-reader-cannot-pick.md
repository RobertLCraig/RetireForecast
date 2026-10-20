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
- [x] #1 WHEN the results page names a generated order as the cheapest, THE APP SHALL let the reader
      run their own forecast on that order.
      proves: `test_a_taxable_income_target_can_be_chosen_and_reaches_the_forecast`
- [x] #2 THE APP SHALL leave a scenario that states no target byte-identical to what it forecasts
      today. proves: `test_a_scenario_without_a_taxable_income_target_is_unchanged`
<!-- AC:END -->

## Tasks
- [ ] Carry the target through `AssumptionOverrides` as a sparse key, like `drawdownStrategy`
- [ ] Add the builder field, its validation, its `loadState` backfill and its
      `BuilderStateFixture::full()` entry together
- [ ] Disclose it in `ResultPresenter::assumedFigures()` where it is the engine's and not the reader's

## Comments

**2026-10-20** RESULT: done
TESTS: +2 new, all green
TOUCHED: app/Forecast/AssumptionOverrides.php, app/Forecast/ScenarioForecaster.php, app/Forecast/WithdrawalStrategyComparison.php, app/Forecast/ResultPresenter.php, app/Livewire/ScenarioBuilder.php, resources/views/livewire/scenario-builder.blade.php, tests/Feature/Forecast/ScenarioForecasterTest.php
OUT-OF-SCOPE: none

Built: a sparse `taxableIncomeTarget` choice key (whole pounds) in assumptionOverrides. AssumptionOverrides::taxableIncomeTargetPence() reads it only when the chosen order is fill-the-bands. ScenarioForecaster::buildSettings() applies it to the reader's own run only; a search candidate (explicit strategy) keeps its own target or none, so the plain fill-the-bands candidate is not polluted. Builder field: integer 1 to 1,000,000, `prohibited_unless` fill-the-bands, so a target on an order that never reads it is refused rather than stored inert.

Watched fail: criterion 1 failed with `null is identical to 5027000` (target never reached settings). Criterion 2 was green before the change by nature (no key existed), so I mutated taxableIncomeTargetPence() to return a default and watched it fail (`1257000 is null`), then reverted.

Two things beyond the card's Tasks, both forced by criterion 1: (a) WithdrawalStrategyComparison now builds the current order from the settings (strategy + target). Without it, a reader who picked the winning variation got a 'your current order' tile priced on plain fill-the-bands and was still told the variation saves money. A target the search does not try is priced as one extra run (6, inside the plan's 4 to 6 ceiling). (b) The draw-order note in ResultPresenter said the variations 'cannot be picked in the builder yet'; it now says how to pick one. Its pinning test (test_the_draw_order_note_does_not_promise_the_reader_can_pick_every_order_priced) legitimately drifted and was updated to the new wording, not loosened.

Tasks not needed: no loadState backfill and no BuilderStateFixture::full() entry, because assumptionOverrides is a sparse map with no blank default (the drawdownStrategy precedent: absent = not chosen), so no spurious what-if delta. No assumedFigures() disclosure: the target is never engine-supplied for a reader's run (absent = the order's own statutory bands, already disclosed by the draw-order note).

Still needs a browser check of the new builder field and the panel from C:\Dev\RetireForecast; none was possible from the worktree.
