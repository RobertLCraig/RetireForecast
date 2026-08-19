# The pension escalation dropdown does nothing

## Why
From the expert panel, 2026-08-19 (engineer finding F1). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`PathProjector::dbEscalation()` returns the inflation rate and nothing else. Every defined-benefit
pension escalates at full CPI for ever, whatever the user chose.

The field is stored, validated in `ScenarioBuilder`, rendered as a select in the builder view, and
mapped onto the DTO by `HouseholdAssembler`. It is never read. `AnnuityPurchase::escalation` **is**
honoured, so this is an omission rather than a deliberate simplification.

The consequence is not small. Pre-1997 accrual commonly has no statutory escalation at all, so a
user modelling that honestly is handed a forecast in which it rises with inflation for thirty
years.

`revaluationBasis` has the same problem. `dbIncome()` applies the single in-payment factor from the
base year regardless of age, so deferred revaluation and in-payment escalation are
indistinguishable.

This is worse than a magic number. A control that does nothing tells the user they said something
the model never heard.

## Not this card
Annuity escalation, which is already honoured.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a defined-benefit pension is set to no escalation in payment, THE APP SHALL hold it flat in nominal terms for the whole projection.
- [ ] #2 WHEN a capped escalation basis is chosen, THE APP SHALL apply inflation up to the cap and no more.
- [ ] #3 WHEN a pension is deferred, THE APP SHALL revalue it on its revaluation basis until normal retirement age, then escalate it on its in-payment basis.
<!-- AC:END -->

## Tasks
- [ ] Make the escalation factor per-pension in projector state, not one household factor
- [ ] Implement each `PensionEscalationBasis` case, adding a capped-at-2.5% variant
- [ ] Add a `fixedEscalationRate` to `DbPension` for the fixed case, disclosed as an assumed figure
- [ ] Source an RPI-over-CPI wedge for the RPI case, with `source` and `verified_on`
- [ ] Separate the deferred revaluation factor from the in-payment factor
- [ ] Test all five bases produce different year-20 incomes
