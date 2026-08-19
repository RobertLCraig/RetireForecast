# An interest-only mortgage payment rises with inflation and shrinks when someone dies

## Why
From the expert panel, 2026-08-19 (adviser finding 2). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

For any mortgage that is not a `RepaymentMortgageTerms` schedule - interest-only, RIO,
buy-to-let, an interest-serviced lifetime mortgage - the payment sits in
`ExpenseProfile::$mortgageCosts` inside essential spend. `PathProjector` then multiplies it by
`spendFactor` (cumulative CPI) and by the survivor factor.

Both are wrong. Interest on a fixed balance at a fixed rate is fixed in cash terms, so
CPI-indexing it holds it flat in real terms when its real cost should fall. And a lender does
not reduce the payment when a borrower dies.

The repayment instalment was fixed for exactly this in July 2026 - the comment at `PathProjector`
line 1068 states the correct treatment, and rent is correctly added after the survivor multiply -
but the fix was never carried to the other three product shapes.

This is not a neutral error. It removes the inflation hedge on a nominal debt, so every borrowing
route is penalised against selling. That is the choice card 0022 is waiting on.

## Not this card
The repayment amortisation schedule, which is already correct and pinned to a lender illustration.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a mortgage payment is charged from `ExpenseProfile::$mortgageCosts`, THE APP SHALL hold it fixed in nominal terms rather than escalating it with CPI.
- [ ] #2 WHEN a household member dies, THE APP SHALL charge the survivor the full mortgage payment, unreduced by the survivor factor.
- [ ] #3 WHEN a let property's finance cost is computed for the Section 24 credit, THE APP SHALL use nominal interest rather than a CPI-inflated figure.
<!-- AC:END -->

## Tasks
- [ ] Move `mortgageCosts` out of the CPI and survivor multiply in `PathProjector`
- [ ] Fix `financeCost` (around line 924) the same way
- [ ] Test: a fixed-rate interest-only payment is the same pence in year 1 and year 20
- [ ] Test: the payment does not change at the first death
- [ ] Re-run every stored scenario, then `php artisan scenarios:audit`
