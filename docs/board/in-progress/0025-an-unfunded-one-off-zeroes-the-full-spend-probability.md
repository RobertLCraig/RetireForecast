# An unfunded one-off cost zeroes the full-spend probability on every path

## Why
From the expert panel, 2026-08-19 (engineer finding F3, reached independently by the property
reviewer). Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

`ForecastResult::$fullSpendAlwaysMet` is all-or-nothing across a whole path, so one unfunded pound
in one year fails a fifty-year plan.

`HousingComparison::withHousing()` charges an unfunded purchase gap through
`ExpenseProfile::withOneOffCost()`. `PathProjector::oneOffCostsNominal()` adds that to
`$spendNominal` but not to `$essentialNominal`. The gap is a year-0 constant, independent of the
sampled draws, so it produces unmet spend on 100% of paths.

So full-spend probability reads exactly 0.000 while essentials is untouched, and
`scenarios:audit` still reports the plan as never running short because it checks the essential
floor. This is the diagnosis for card 0023, and it will hit any scenario with a funding gap.

## Not this card
Whether a particular purchase gap should be funded. That is a scenario input question.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a plan misses its full spending target in some years, THE APP SHALL report the fraction of years met, not only an all-or-nothing flag.
- [ ] #2 WHEN a one-off capital cost cannot be funded, THE APP SHALL raise a distinct warning naming that cost, rather than only depressing a spending probability.
- [ ] #3 WHEN the essentials and full-spend probabilities are reported together, THE APP SHALL not let them diverge by more than the years actually unfunded.
<!-- AC:END -->

## Tasks
- [ ] Add `fullSpendYearsMetFraction` to `ForecastResult`, plus a "met in 95% or more of years" probability
- [ ] Separate one-off capital events from recurring spend in the success test
- [ ] New `WarningCode` for an unfunded purchase, surfaced on the year
- [ ] Test in `packages/finance-engine/tests/Housing/`
- [ ] Re-check card 0023 against the fix
