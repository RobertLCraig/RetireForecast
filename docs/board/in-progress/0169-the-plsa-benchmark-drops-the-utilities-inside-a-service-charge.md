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
- [ ] #1 WHEN a service charge carries a utilities figure, THE APP SHALL keep that figure in the spend the PLSA benchmark compares. proves: `test_the_plsa_benchmark_keeps_the_utilities_inside_a_service_charge`
<!-- AC:END -->

## Plan
Subtract `propertyCosts()` less `propertyCostsUtilities()` in `plsaBenchmark()`.

## Comments
