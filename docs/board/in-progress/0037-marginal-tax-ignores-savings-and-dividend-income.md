# Drawing from a pension is taxed as if there were no savings or dividends

## Why
From the expert panel, 2026-08-19 (engineer finding F7). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

The year's main tax pass is correctly combined - non-savings, savings and dividends in one
computation. The drawdown gross-up is not: `marginalTax()` and `grossUpPension()` build their
income from `TaxableIncome::ofNonSavings()`.

Savings and dividend income stacks **on top of** non-savings income. So an extra pension
withdrawal pushes it across band boundaries and shrinks the personal savings allowance. Computing
the marginal cost without it understates the tax on the withdrawal.

The year's reported total tax is then a correct pre-drawdown figure plus a too-small increment, so
the household is left holding cash it would not really have. Nothing reconciles the incremental
tax against a recomputed full-year liability, so it is silent.

This bites exactly the households the tool is for: a couple funding retirement from a mix of
drawdown and invested money.

## Not this card
The combined income-tax pass itself, which is correct.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN extra pension income is drawn, THE APP SHALL compute its tax against the person's full income including savings and dividends.
- [ ] #2 THE APP SHALL reconcile each year's total tax against a full recomputation from final taxable income, to the penny.
<!-- AC:END -->

## Tasks
- [ ] Thread savings and dividend income into the `fundShortfall` draw closure
- [ ] Change `marginalTax` and `grossUpPension` to take a full `TaxableIncome`
- [ ] Add a year-level tax reconciliation test; it is missing and would have caught this
- [ ] Re-run every stored scenario
