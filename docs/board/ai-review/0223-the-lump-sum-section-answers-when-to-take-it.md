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
- [x] #1 THE APP SHALL show the tax on the withdrawal taken now, after retiring, and spread over 2 and 3 tax years. proves: `test_the_tax_shock_compares_withdrawal_timings`
- [x] #2 THE APP SHALL compute each timing through `LumpSumTaxShock` with that year's income. proves: `test_each_timing_uses_that_years_income`
<!-- AC:END -->

## Comments

**2026-10-22** RESULT: built
TESTS: +2 new in `LumpSumTaxShockTest`; `ScenarioResultsTest`'s shock test also checks the table is on the page
TOUCHED: `LumpSumTaxShock::timings()` (carried on the shock panel as `timings`), the shock section on screen and in the PDF
OUT-OF-SCOPE: State Pension and DB income in the other-income column. The panel above takes salary only, and the timings use the same rule, so the two agree; layering pensions in is one change for both, and a card of its own.

Four rows: all this tax year; all in the first tax year after the owner retires (only if they still work); spread evenly over 2 and over 3 tax years from then. Each tax year goes through `FlexibleWithdrawalAssessor`, the engine code behind the panel, with that year's other income and what is left of the Lump Sum Allowance. Every row uses the base year's tax rules (thresholds are frozen to 2031, so this matters only for a long wait). The reclaimable column is the first payment's over-deduction. The "this tax year" row reproduces HMRC worked example A to the penny. **Not seen in a browser.**
