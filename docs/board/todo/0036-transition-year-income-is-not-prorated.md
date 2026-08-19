# Retirement year pays a part-year salary and a full year of pension

## Why
From the expert panel, 2026-08-19 (engineer finding F2). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Three asymmetries in the same year, all favouring the household:

- `statePensionIncome()` pays the **full** annual amount from the claim year, so a State Pension
  starting in November pays about ten months too much.
- `dbIncome()` pays the **full** annual amount in the year the member reaches normal retirement
  age.
- `niForPerson()` switches National Insurance off for the **whole** calendar year in which State
  Pension age is reached, including the months before it.

Meanwhile `workFraction()` correctly prorates salary, per the 2026-06-30 decision that salary
would not be paid for a full calendar year if you leave in July. The reasoning that produced that
fix applies word for word to the income replacing it, and was not carried across.

The transition year is exactly where an affordability cliff would show, and all three errors point
the same way.

Two of the three fixes need the State Pension age **month**, which `initialState()` already
computes and then discards.

## Not this card
The salary proration, which is already correct.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a State Pension starts part way through a year, THE APP SHALL pay only the part of the year after the entitlement date.
- [ ] #2 WHEN a defined-benefit pension starts at normal retirement age, THE APP SHALL pay only the part of the year after that birthday.
- [ ] #3 WHEN a person reaches State Pension age part way through a year, THE APP SHALL charge National Insurance on the earnings before that date.
<!-- AC:END -->

## Tasks
- [ ] Add a `startFraction` helper beside `workFraction`
- [ ] Keep the State Pension age month in projector state
- [ ] Apply the fraction in `statePensionIncome`, `dbIncome` and `niForPerson`
- [ ] Tests for a birthday in each quarter
- [ ] Re-run every stored scenario
