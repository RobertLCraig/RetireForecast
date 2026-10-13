# The SMI and deferred care balances on a row miss that year's interest

## Why
Found building card 0103. That card moved the mortgage on a year's row to the END of the year, after
its instalments or roll-up, the point the liquid and pension legs are read at. The two other balances
secured on the home were not in its scope and still report the earlier point. `smiBalance` and
`deferredCareBalance` are written onto the row in `PathProjector::projectYear()` and only rolled up
afterwards, in `growState()`, at the SMI standard rate and the deferred payment rate. So a row
carries the year's new SMI or deferred charge but not the interest that accrued on the balance during
the year. `YearResult::homeEquity()` subtracts both, so `totalWealth` on every row with either
balance is overstated by one year's interest.

Not yet decided: whether the year's newly added charge should itself bear part of a year's interest.

## Links

**Relates to**
- `0103` - fixed the same lag for the mortgage, through `PathProjector::mortgageAfterTheYear()`.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a year with a Support for Mortgage Interest balance is reported, THE APP SHALL show that balance after the year's interest. proves: `test_a_reported_year_shows_the_smi_balance_after_that_years_interest`
- [ ] #2 WHEN a year with a deferred care balance is reported, THE APP SHALL show that balance after the year's interest. proves: `test_a_reported_year_shows_the_deferred_care_balance_after_that_years_interest`
<!-- AC:END -->

## Tasks
- [ ] Read both balances for the row from the same helper `growState` uses, as 0103 did for the mortgage.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`; plans with SMI or a care spell are owed a re-run.
