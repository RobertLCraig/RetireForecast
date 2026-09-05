# A purchase borrows money while the cash to buy it arrives the same year

## Why
From the expert panel, 2026-08-19 (property finding 8). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`SavingsFunding::draw()` reads `$household->accounts` only. A `CapitalReceipt` is credited by the
projector during the year, so a year-0 purchase cannot see it.

The result is a plan that takes out a lifetime mortgage to cover a funding gap while the money to
close that gap is sitting beside it as a receipt. No household would do that, and it charges the
plan interest for the rest of the projection.

The direction of the error is pessimistic, so it does not overstate a plan. It is still wrong, and
correcting it moves a plan up the ranking rather than down - which matters, because the ranking is
what card 0022 turns on.

## Not this card
Whether a particular receipt is realistic. That is a scenario input question.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a purchase is funded in a year that also carries a capital receipt, THE APP SHALL use the receipt before borrowing.
- [ ] #2 WHEN borrowing is still required after available funds are used, THE APP SHALL charge only the shortfall.
<!-- AC:END -->

## Tasks
- [ ] Credit same-year capital receipts before the purchase funding waterfall runs
- [ ] Test: a receipt equal to the gap results in no borrowing
- [ ] Re-run every scenario that carries a receipt, then `php artisan scenarios:audit`
