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
- [x] #1 WHEN a plan misses its full spending target in some years, THE APP SHALL report the fraction of years met, not only an all-or-nothing flag.
- [x] #2 WHEN a one-off capital cost cannot be funded, THE APP SHALL raise a distinct warning naming that cost, rather than only depressing a spending probability.
- [x] #3 WHEN the essentials and full-spend probabilities are reported together, THE APP SHALL not let them diverge by more than the years actually unfunded.
<!-- AC:END -->

## Tasks
- [x] Add `fullSpendYearsMetFraction` to `ForecastResult`, plus a "met in 95% or more of years" probability
- [x] Separate one-off capital events from recurring spend in the success test
- [x] New `WarningCode` for an unfunded purchase, surfaced on the year
- [x] Test in `packages/finance-engine/tests/Housing/`
- [x] Re-check card 0023 against the fix

## Comments

**2026-08-29** Built the split, the fraction and the warning.

**What changed.** `PathProjector` now keeps the year's one-off CAPITAL lumps as a labelled list
(`oneOffCostsNominal()` returns `label` + nominal amount; a mortgage redeemed from capital joins
it) instead of one anonymous total. After the shortfall is funded, the unmet amount is charged
against those lumps FIRST, which is the same funding order `essentialsMet` already assumed: a
household eats and heats itself before it completes a purchase. The remainder is the recurring
budget that genuinely went short, and `YearResult::fullSpendMet()` now judges that. The lump is
not forgiven: it stays inside `unmetSpend` (so the net-position fan, `scenarios:audit` check 6 and
the year-0 charge are all untouched) and the year carries a new
`WarningCode::UNFUNDED_ONE_OFF_COST` naming the cost and the unfunded amount.

`ForecastResult` gained `fullSpendYearsMetFraction()` and `essentialsYearsMetFraction()` as DERIVED
methods, not stored fields, so they cannot drift from `$years` or from the all-or-nothing flags.
`SimulationResult` gained `successProbabilityFullSpendMostYears` with the threshold as a named
const (`FULL_SPEND_MOST_YEARS_THRESHOLD = 0.95`), which the presenter and both blades read rather
than restate. Old stored runs have no key and rehydrate to `null`, shown as a dash, never 0.0.

**Where a reader sees it.** The results page and the PDF headline tile now show "met every year"
beside "met in 95%+ of years". The affordability verdict states in how many of the plan's years the
full budget was met. The unfunded lump reaches the reader through the existing input-notes panel
(kind `unfunded_one_off`), which renders on both the results page and the PDF, quoting the engine's
own sentence so the figure cannot drift from the one the projection charged.

**Card 0023, re-checked.** Projected #51 and #53 deterministically against the fix:

    #51 (buy_outright) essYears=1.000 fullYears=1.000 unmetOneOff=£1
      2026: Unfunded purchase shortfall: £1.37 ...
    #53 (buy_outright) essYears=1.000 fullYears=1.000 unmetOneOff=£0

So 0023's suspicion is confirmed, and the residual is smaller than anyone expected: #51's
completion gap is not £46,412, it is **£1.37** — a rounding-scale remainder. Under the old
all-or-nothing test that £1.37 landed identically on all 10,000 paths and took the full-spend
probability to exactly 0.000 while essentials read 76.4%. Both measures now agree. I have not
re-run the 10,000-path Monte Carlo, and I did not touch card 0023 (the scheduler owns it) — its
AC #3 (the stale SCENARIO-V2.local.md warning) and AC #4 remain for whoever picks it up.

**Assumed.** That recurring spend is funded before a one-off lump. It is the order `essentialsMet`
already implied, and the alternative (charging the lump first) would have a purchase gap starve the
food bill, which is not what a household does.

**Not settled from the repository.** `scenarios:audit` exits non-zero both before and after this
change, on ~200 "run carries no integrity stamp (it predates the column)" lines — the known
consequence of card 0018's migration not yet being applied (HANDOVER records it). No other problem
class appears, so the audit shows nothing new from this change, but it cannot be used as a clean
gate until that migration runs.

**Still needs a browser check.** This worktree is not what Herd serves, so the two blade changes
(the new headline row / PDF tile note, and the unfunded-cost input note) have been proven by the
suite and by a direct projection, but nobody has looked at them on a screen.
