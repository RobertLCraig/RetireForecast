---
needs: 0212, 0213
---
# The lump-sum section answers "when should I take it?"

## Why
Found by the full review on 2026-10-21. `sec-shock` (L457-527) prices the first withdrawal only. The
couple's real question is timing: take it now while still earning, after retiring, or spread over
tax years.

## Direction
A small table under the tax shock: the tax on the same withdrawal taken (a) this tax year,
(b) in the first tax year after the earner retires, (c) spread evenly over 2 and 3 tax years.
Use `LumpSumTaxShock` for each; compute nothing new in the view. Show the saving against (a). Keep the
emergency-tax overpayment line for each.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL show the tax on the withdrawal taken now, after retiring, and spread over 2 and 3 tax years. proves: `test_the_tax_shock_compares_withdrawal_timings`
- [ ] #2 THE APP SHALL compute each timing through `LumpSumTaxShock` with that year's income. proves: `test_each_timing_uses_that_years_income`
<!-- AC:END -->
