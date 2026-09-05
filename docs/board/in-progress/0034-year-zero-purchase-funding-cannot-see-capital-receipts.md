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
- [x] #1 WHEN a purchase is funded in a year that also carries a capital receipt, THE APP SHALL use the receipt before borrowing.
- [x] #2 WHEN borrowing is still required after available funds are used, THE APP SHALL charge only the shortfall.
<!-- AC:END -->

## Tasks
- [x] Credit same-year capital receipts before the purchase funding waterfall runs
- [x] Test: a receipt equal to the gap results in no borrowing
- [ ] Re-run every scenario that carries a receipt, then `php artisan scenarios:audit`

## Comments

**2026-09-05**
RESULT: done
TESTS: +6 new, all green
TOUCHED:
packages/finance-engine/src/Housing/HousingComparison.php
packages/finance-engine/src/Housing/HousingPurchase.php
packages/finance-engine/tests/Housing/PurchaseReceiptFundingTest.php
packages/finance-engine/tests/Housing/HousingProceedsReconciliationTest.php
tests/Unit/Forecast/SaleExplainerTest.php
app/Forecast/ScenarioForecaster.php
app/Forecast/ResultPresenter.php
app/Livewire/ScenarioResults.php
app/Livewire/ScenarioCompare.php
app/Console/Commands/AuditScenarios.php
app/Export/ScenarioReport.php
app/Assistant/AssistantTurnRunner.php
app/Assistant/ScenarioContext.php
resources/views/livewire/scenario-results.blade.php
resources/views/pdf/partials/report.blade.php
docs/HANDOVER.md
docs/DECISIONS.md
docs/DATA-MODEL.md
OUT-OF-SCOPE: none

The waterfall in `HousingComparison::fundingFor` is now receipt, savings, mortgage, unfunded gap.
`spendReceipts()` is the new private rule: it spends the receipts dated the base year, in
declaration order, and returns both what was spent and the receipts that survive. Both criteria
were watched failing first, on the fault itself and not on a missing symbol: with a receipt equal
to the whole gap the engine borrowed £73,000 (the gap less the savings) instead of £0, and with a
receipt covering all but £13,000 it borrowed £113,000 instead of £13,000.

**Receipt ahead of savings, not behind it.** The criteria only say "before borrowing", so the
position against savings was mine to choose. Spending money that is arriving anyway realises no
gain; drawing a GIA to the same value realises its pro-rata slice and pays CGT nobody owes. A
household holding both would spend the cheque, so savings-first would charge a tax that is not due.

**The spent part is consumed, not copied.** The buy variant carries the receipts reduced by exactly
what the purchase spent: fully spent is dropped, partly spent keeps its unspent remainder, another
year's is untouched. Without that the same pound would both buy the home and arrive as that year's
income, which is the mirror image of the defect being fixed, so it has its own projection-level
test reading the year-0 `capital_receipt` income source. Only the buy variant is affected; stay-put
and rent buy nothing and keep their receipts exactly as entered.

`buyOutcome()` takes the base year as a REQUIRED argument rather than an optional one, because a
caller that could omit it would surface a mortgage the projected plan never takes.

**What I found in the tree, and did not write.** The app-layer half of this card was already
present as uncommitted work when this session started (the sale-waterfall receipt line on screen,
in the PDF, on Compare and in the assistant facts, the `ENGINE_VERSION` bump, and the DECISIONS
and DATA-MODEL entries), but the engine half it called into did not exist, so nothing compiled. I
built the engine half to the API that work already assumed and checked the DECISIONS entry against
what I built line by line. I did not watch the presenter half fail: it was written before this
session. The new `SaleExplainerTest` case is coverage over it, not a watched failure.

**The third task is open and cannot be closed here.** Re-running the stored scenarios and
`php artisan scenarios:audit` both hit Rob's live Postgres database through the hard-linked `.env`,
which a worktree session must not write to. The re-run is owed, as it is for cards 0028 to 0033.
The results page and the PDF have not been seen in a browser for the same reason: Herd serves the
main checkout, not this directory.
