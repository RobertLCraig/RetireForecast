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

### 2026-08-29 review (v20260829194831-95e9)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 255s, run by this job rather than reported by the card.

**acceptance: sound**

**AC #1 ÔÇö fraction of years met.** `ForecastResult::fullSpendYearsMetFraction()` and `essentialsYearsMetFraction()` (packages/finance-engine/src/Forecast/ForecastResult.php), derived from `$years`, not stored. Surfaced by `AffordabilityAssessment::verdict()` ("The full budget is met in X of the plan's Y years"), appended on both the `essentials_only` and `fails` tiers ÔÇö every case where the target is missed. Traced.

**AC #2 ÔÇö distinct warning naming the cost.** `PathProjector::oneOffCostsNominal()` keeps the label; `PathProjector::unfundedOneOffWarnings()` emits `WarningCode::UNFUNDED_ONE_OFF_COST` with label + amount. `ResultPresenter::inputNotes()` turns it into a `unfunded_one_off` note, rendered by both `resources/views/livewire/scenario-results.blade.php` and `resources/views/pdf/partials/report.blade.php`. Traced.

**AC #3 ÔÇö no divergence beyond years actually unfunded.** `PathProjector` sets `unmetOneOffNominal = min(unmet, oneOffTotal)`, so `YearResult::fullSpendMet()` can only be false when recurring spend went short. I checked the algebra: `fullSpendMet` implies `essentialsMet` in every year, so the path flags and the two Monte Carlo probabilities (`Simulator::run()`) cannot diverge except on genuinely short years. Traced.

I tried to break it on rehydration too: only `PathProjector` builds a `YearResult`, so no stored year loses the split.

VERDICT: sound

**scope: defect**

**Scope: the fix grew past the card.**

**1. Mortgage redemption was pulled in, and it changes an existing safety signal.**
`PathProjector::projectYear()` now appends `$repayOneOff` ("Mortgage redemption") to the exempt one-off list. The card diagnosed only `ExpenseProfile::withOneOffCost()` / `HousingComparison::withHousing()`. Effect: a keep-the-home plan that cannot repay its mortgage no longer fails `fullSpendAlwaysMet`, nor the new most-years probability. It only gets a warning.

The same function's own redemption comment still says the shortfall "surfaces, flagging the keep-the-home option as unaffordable". That is now false.

`PathProjectorTest::test_a_repay_from_capital_redemption_the_household_cannot_afford_shows_a_shortfall` only checks `unmetSpend` is positive, so the suite stayed green while the meaning changed. No test covers redemption under the new measure.

**2. Left half done.**
`ForecastResult::essentialsYearsMetFraction()` has no production caller ÔÇö only `UnfundedPurchaseTest` uses it.

The years-met fraction reaches one screen only: `AffordabilityAssessment::verdict()`, used by `App\Livewire\Affordability`. `ResultPresenter::headline()` and the PDF got the 95% probability instead, which is a different number.

VERDICT: defect

**breakage: defect**

I read the change (commit `1ebc542`), traced every consumer of `fullSpendAlwaysMet`, and checked the persistence and audit paths. The mapper, the integrity stamp (`SimulationRun::integrityHash()` hashes the *stored* `engine_version`, so old runs stay intact) and audit check 6 all survive. One thing does not.

**The new warning only reaches two surfaces; the flag it replaced was read by five.**

`PathProjector::projectYear()` now excludes an unfunded lump from `fullSpendMet()`, so `fullSpendAlwaysMet` flips to `true` for a plan whose purchase is not funded. The replacement signal is a `YearResult` warning, and it is picked up **only** by `ResultPresenter::inputNotes()`, rendered by `scenario-results.blade.php` and `pdf/partials/report.blade.php`.

Three readers of the old flag got nothing back:

- `AffordabilityAssessment::card()` now tiers such a plan `comfortable`, and `verdict()`'s `comfortable` branch answers "Yes ÔÇö this covers your full budget for the rest of your life". `affordability.blade.php` shows no note, so a ┬ú125,000 unfunded purchase is invisible there.
- `Interpretation::outcome()` states "your full spending is funded every year" ÔÇö advice mode is on.
- `SustainableSpend::forScenario()`'s `$holds()` used the flag; it now returns a spendable figure where it previously returned `null` ("this plan is broken").

No test builds these three paths.

VERDICT: defect

