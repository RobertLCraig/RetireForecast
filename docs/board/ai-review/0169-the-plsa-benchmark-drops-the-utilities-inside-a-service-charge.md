# The PLSA benchmark drops the utilities inside a service charge

## Why
Found by the 2026-09-05 scope review of card 0033, repeated by the 2026-09-28 manager pass, and
still true on 2026-10-05. `ResultPresenter::plsaBenchmark()` takes the whole `propertyCosts()` off
the spend it compares with the PLSA Retirement Living Standards. The part a reader marked as
utilities (`ExpenseProfile::propertyCostsUtilities()`) is water and energy, which the PLSA basket
includes. So a plan whose service charge buys utilities shows comparable spend that is too low by
that amount, and can fall a PLSA tier.

## Links

**Relates to**
- `0033` - added the utilities figure.
- `0047` - the same benchmark also loses the council tax bill.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a service charge carries a utilities figure, THE APP SHALL keep that figure in the spend the PLSA benchmark compares. proves: `test_the_plsa_benchmark_keeps_the_utilities_inside_a_service_charge`
<!-- AC:END -->

## Plan
Subtract `propertyCosts()` less `propertyCostsUtilities()` in `plsaBenchmark()`.

## Comments

**2026-10-22** RESULT: done
TESTS: +1 new, all green
TOUCHED: app/Forecast/ResultPresenter.php
tests/Unit/Forecast/PlsaBenchmarkTest.php
OUT-OF-SCOPE: none

plsaBenchmark() now subtracts ExpenseProfile::escalatingPropertyCosts() (the property-costs bucket less its clamped utilities, already defined there) instead of propertyCosts(), as the Plan says. The new test (a £4,000 service charge carrying £1,500 utilities beside £20,000 groceries) was watched failing at £20,000 before the fix and passes at £21,500 after. Benchmark is presentation only: no ENGINE_VERSION bump and no re-run owed. The PLSA panel on the results page still needs a browser check; this worktree is not what Herd serves.
