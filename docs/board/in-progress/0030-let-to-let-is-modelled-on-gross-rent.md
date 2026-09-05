# Letting a property is modelled on gross rent with no costs

## Why
From the expert panel, 2026-08-19 (property finding 5). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

A let-to-let scenario takes rent in gross. There is no agent fee, no void, no repairs, no
compliance and no licensing. The caveat exists only in a docblock, not on the result.

Realistic deductions on a fully managed single let are roughly 12% management including VAT, 8%
void, and about 5% for repairs, inventory, gas safety and electrical checks - around a quarter of
gross rent before tax.

The error also runs the other way: the service charge on a let flat is a deductible letting
expense, and the model treats it as household spend while taxing the full gross rent as profit.
The property reviewer's arithmetic turns a modelled positive contribution into a real cash loss.

Card 0021 is chasing a buy-to-let interest rate. The rate is not what is wrong with the plan.

Two further items that are real and unmodelled: minimum energy efficiency standards, where a
proposed EPC C requirement by 2030 puts a five-figure retrofit or an exemption application in
scope; and the lease itself, which usually requires freeholder consent to sublet and may prohibit
it outright.

## Not this card
Section 24 finance-cost relief, which the projector already models correctly.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a property is let, THE APP SHALL deduct management, void and maintenance costs from gross rent, each a disclosed sourced default and each editable.
- [ ] #2 WHEN a let property carries a service charge, THE APP SHALL treat it as a letting expense rather than household spend.
- [ ] #3 WHEN a let plan is displayed, THE APP SHALL show the letting caveats on the result, not only in code comments.
<!-- AC:END -->

## Tasks
- [ ] Add management, void and maintenance rates to the let-property inputs
- [ ] Reclassify a let property's service charge as a letting expense
- [ ] Surface the caveats through `ResultPresenter`
- [ ] Re-run the let scenarios, then `php artisan scenarios:audit`
