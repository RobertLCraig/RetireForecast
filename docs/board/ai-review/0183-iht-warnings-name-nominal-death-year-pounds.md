# Inheritance Tax warnings name nominal death-year pounds beside real figures

## Why
`PathProjector::deflateIht()` turns every money field of an `IhtResult` into today's money, but
carries the `warnings` array through unchanged. The warnings were written by
`InheritanceTaxCalculator::compute()` in NOMINAL pounds at the death year. So the
`IHT_DOWNSIZING_ADDITION` and `IHT_PENSIONS_IN_ESTATE` messages (and the beneficiary income tax
warning) name an amount that does not match the real figure the panel shows beside them. Twenty
years of inflation makes the two differ by a third or more.

Found by the card 0053 review (breakage lens, "same root, smaller"); not fixed there because
criterion #4 is about the reported addition, not the warning units.

## Links

**Relates to**
- `0053` - added the downsizing-addition warning.

## Acceptance
<!-- AC:BEGIN -->
- [x] THE APP SHALL state every Inheritance Tax warning amount in the same real money as the figures it sits beside. proves: `test_iht_warning_amounts_are_in_real_money`
<!-- AC:END -->

## Plan
Either build the warnings after deflation (from the deflated result) or have `deflateIht()`
regenerate them. Test through `DeterministicForecaster` with non-zero inflation and a late death.

## Comments

**2026-10-23** RESULT: done
TESTS: +1 new, all green
TOUCHED: packages/finance-engine/src/Iht/InheritanceTaxCalculator.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Iht/IhtWarningRealMoneyTest.php
OUT-OF-SCOPE: none
The calculator now takes a realFactor (default 1.0) on compute() and computeFirstDeath(); the projector passes the same 1/cumInflation it deflates by, so warnings are written in real money and deflateIht carries them through. Rounding matches deflateIht, so the warning string equals the panel figure exactly.
test_iht_warning_amounts_are_in_real_money was first seen red through DeterministicForecaster (single person, death at 100 in 2046): the downsizing warning said £175,000.00 beside a real £115,460.77.
Assumed: a sum fixed in law (the £322,000 statutory legacy, the £325,000 capped spouse exemption) keeps its legal figure, with its real value in brackets beside it, rather than being restated as if the statute said the smaller number. The test proves that form on the calculator directly; Rob may prefer plain real figures.
No figure moves, so no ENGINE_VERSION bump; stored results keep the old warning text until re-run. Not seen in a browser.
