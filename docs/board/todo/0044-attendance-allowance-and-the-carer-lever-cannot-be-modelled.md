# A disability benefit claimed later in life cannot be modelled at all

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 2, adviser finding 10). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

`Person::$receivesDisabilityBenefit` is a static boolean with no start age. So you cannot model the
single most likely favourable event in a long survivor period: a person claiming Attendance
Allowance once their own health declines.

That event is large. Attendance Allowance is tax-free and disregarded from the means test, and it
opens the severe disability addition inside Pension Credit, which in turn passports Support for
Mortgage Interest, Council Tax Reduction, the Warm Home Discount, a free TV licence, Cold Weather
Payments and help with NHS costs. The caseworker's arithmetic makes the package worth more per year
than the survivor shortfall the tool is trying to close.

`Person::caresForPartner` exists in the engine but is not a builder input, so the carer addition
wired in July 2026 is dead code as far as the app is concerned.

There is also an interaction the sweep should show: underlying entitlement to Carer's Allowance has
an earnings limit, so a lever that says "work longer" also postpones the carer addition.

## Not this card
Support for Mortgage Interest itself, which is card 0045.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL let a disability benefit start at a chosen age rather than being on or off for life.
- [ ] #2 THE APP SHALL offer claiming Attendance Allowance later in life as a what-if, showing the benefit and everything it passports.
- [ ] #3 THE APP SHALL expose whether a person cares for their partner as a builder input.
- [ ] #4 WHEN a lever extends working life, THE APP SHALL flag that earnings above the carer earnings limit block underlying entitlement to Carer's Allowance.
<!-- AC:END -->

## Tasks
- [ ] Give the disability flag a start age, or replace it with a dated award
- [ ] Add an Attendance Allowance what-if lever using the sourced rate
- [ ] Expose `caresForPartner` in the builder and the assembler
- [ ] Warn on the carer earnings-limit interaction in the working-longer lever
