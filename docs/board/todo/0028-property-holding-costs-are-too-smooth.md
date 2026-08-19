# Property holding costs rise smoothly and never arrive in a lump

## Why
From the expert panel, 2026-08-19 (property findings 2 and 10). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Two related problems with how a flat's running costs behave over a projection.

**No lumpy capital item.** Service charges escalate smoothly and there is no major-works event
anywhere. A Section 20 demand on a block is legally enforceable, cannot be deferred, and lands as
one bill. That is exactly the shape of liability a survivor on a thin margin cannot absorb, and no
scenario carries one. The machinery already exists - `oneOffCosts`, used for the park-home re-roof.

**The escalator is too benign.** Block insurance, building-safety compliance and energy inside a
service charge have all compounded faster than CPI since 2019. A service charge that includes water
and electricity is exposed to energy prices that no CPI escalator captures. The property reviewer
would model CPI plus 3% real, with CPI plus 1.5% as the optimistic sensitivity.

Over a long projection that difference is thousands a year of real spend, concentrated in the
survivor years. It can flip a verdict on its own.

## Not this card
Utilities and insurance categorisation, which is card 0033.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL let a user enter dated major-works costs against a property, and charge them in the year given.
- [ ] #2 WHEN a leasehold property has no explicit cost-growth rate, THE APP SHALL apply a sourced default above CPI and disclose it as an assumed figure.
- [ ] #3 THE APP SHALL expose the property cost-growth rate as an editable input with its sourced alternatives.
<!-- AC:END -->

## Tasks
- [ ] Surface `oneOffCosts` against a property in the builder, for major works
- [ ] Re-source the leasehold default for `propertyCostsRealGrowth`, with `source` and `verified_on`
- [ ] Disclose it via `assumedFigures()`, reading the constant that owns it
- [ ] Re-run every stored scenario
