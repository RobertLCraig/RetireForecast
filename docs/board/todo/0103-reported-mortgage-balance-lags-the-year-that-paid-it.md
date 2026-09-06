# The mortgage balance on a year's row is a year out of date

## Why
Every year of the forecast reports how much is still owed on the home, and net wealth is that year's
home value minus that figure. The figure shown is the balance at the START of the year, while the
liquid wealth beside it is the balance AFTER that year's mortgage payments have left the bank. So
the row charges the household for the capital it repaid and does not credit it with the debt that
capital cleared.

On a repayment mortgage the household is short by one year of capital repayment in every row: on a
£100,000 loan over 25 years that is roughly £2,000 to £4,000 of net wealth understated, every year,
for the whole term. On a lifetime mortgage the error runs the other way, because the roll-up
interest that accrued during the year is not on the row either, so wealth is overstated by it. Both
land on `YearResult::totalWealth`, which is the one number the buy-versus-rent comparison, the
depletion year and the capacity-for-loss reading are all built on.

It arises from the order of one loop. `PathProjector::project()` builds the year's `YearResult` from
`state['mortgageOutstanding']`, and only then calls `growState()`, which reads the next year's
opening balance off the amortisation schedule (or rolls the lifetime balance up). Nothing decided
that the debt should be a year behind the payments; the balance simply moves in the step after the
row is written.

Found while building card 0041, which fixed a different reading of the same state key (a forced sale
redeeming the balance as originally entered). Not fixed there: that card's scope was the sale, and
this moves a figure on every row of every mortgaged plan.

## Links

**Relates to**
- `0041` - the forced-sale fix that exposed this, and the source of the year-by-year balance the
  test below compares against.

## Not this card
The instalment itself, which is charged correctly as spend. The lifetime-mortgage overpayment, which
is applied to the household's share of the balance rather than the whole. The no-negative-equity cap.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a year of a repayment mortgage is reported, THE APP SHALL show the balance left after that year's instalments, not the balance it opened with. proves: `test_a_reported_year_shows_the_mortgage_balance_after_that_years_payments`
- [ ] #2 WHEN a year of a lifetime mortgage is reported, THE APP SHALL show the balance after that year's roll-up. proves: `test_a_reported_year_shows_the_rolled_up_lifetime_mortgage_balance`
- [ ] #3 WHEN the mortgage reporting point moves, THE APP SHALL keep every stored figure reconciling, so the sum of the parts still equals the reported total wealth. proves: `test_total_wealth_still_reconciles_to_liquid_plus_pension_plus_equity`
<!-- AC:END -->

## Tasks
- [ ] Decide the reporting point ONCE and write it into `YearResult`'s docblock: a row reports the
  position at the END of its year, which is what the liquid and pension figures already do.
- [ ] Move the balance the `YearResult` is built from, or move `growState`'s mortgage step ahead of
  the row, whichever leaves the rest of `PathProjector`'s year order untouched. Read that year order
  before editing it; it is the file's stated contention point.
- [ ] Expect `MonteCarlo\GoldenMasterTest` red if its frozen household carries a mortgage. Re-pin it,
  bump `PIN_REVISION`, and add the `DECISIONS.md` entry its companion test then demands.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` and note the stored re-run is owed: every mortgaged
  plan's wealth, depletion year and success odds move.

## Plan
Work in `C:\Dev\RetireForecast` (or a worktree of it), on a branch off `master`. Run everything from
the project root through PowerShell, because PHP comes from Laravel Herd and is not on the Git Bash
path.

```powershell
php artisan test --testsuite=Engine   # the fast loop while editing the projector
php artisan test                      # must be green before committing
vendor/bin/pint --dirty
```

The code is `packages/finance-engine/src/Forecast/PathProjector.php`: the year loop in `project()`,
and the mortgage steps at the end of `growState()`. The reported figure is
`YearResult::$mortgageBalance`, and `YearResult::homeEquity()` is what subtracts it from the home
value.

For the test, `packages/finance-engine/tests/Forecast/ForcedSaleTest.php` already builds a household
with each mortgage shape (`assertSaleRedeemsTheYearsBalance` and `movingBalanceHousehold`); copy that
construction rather than inventing one, and read the expected balances off
`Property\AmortisationSchedule::openingBalanceIn()` for the repayment case.

"It worked" is the whole suite green plus, on a household with a £100,000 repayment mortgage, the
year 2026 row reporting a balance BELOW £100,000.
