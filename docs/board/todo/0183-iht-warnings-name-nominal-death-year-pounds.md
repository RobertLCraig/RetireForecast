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
- [ ] THE APP SHALL state every Inheritance Tax warning amount in the same real money as the figures it sits beside. proves: `test_iht_warning_amounts_are_in_real_money`
<!-- AC:END -->

## Plan
Either build the warnings after deflation (from the deflated result) or have `deflateIht()`
regenerate them. Test through `DeterministicForecaster` with non-zero inflation and a late death.

## Comments
