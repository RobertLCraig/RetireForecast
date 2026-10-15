---
no_outward_effect: "published" names the legislation somebody else printed, read here; nothing is published by us
---

# Pin the disability-benefit rules in a care placement to a published source

## Why
Card 0050 split a disability award into its care and mobility components and treated the two apart
in a care placement. Two statutory rules do that work, and neither was read off the legislation:

- **The 28-day stop.** Attendance Allowance and the DLA care component stop after 28 days in a care
  home the local authority funds, and the mobility component does not
  (`Benefits\DisabilityBenefitInCare::PAYMENT_STOP_DAYS`).
- **The assessment treatment.** The care component counts as income in a local-authority financial
  assessment while it is in payment, and the mobility component is disregarded. That rule has no
  constant of its own: it is the shape of the care leg in `Forecast\PathProjector`, which adds the
  care component to `assessableAnnualIncome` and never adds the mobility one.

The RULES are stated in each docblock with the SI named. The 28 and the disregard are the building
session's own knowledge of that legislation, and the citation in
[docs/spec/ASSUMPTIONS.md](../../spec/ASSUMPTIONS.md) section 25 is an unvisited URL.

What it costs: both reach a projection, and they pull in opposite directions on the same household.
Getting the stop wrong changes the household's largest tax-free income for every year of a funded
spell; getting the disregard wrong changes what a self-funder is charged in the crossing year, which
is the year that decides how much capital survives care at all.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0050 could ship and disclose the rules but could not go and check them.

## Links

**Relates to**
- `0050` - built the split, and introduced both rules with the disclosure that reads them.
- `0106`, `0109`, `0111`, `0113` - the same shape of gap, from the same missing web access. Whoever
  picks one up can settle several in one research pass.

## Not this card
The mechanism. Recording the two components, assessing the care one and stopping it in a funded
placement are card 0050's and are built.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for both rules in docs/spec/ASSUMPTIONS.md section 25, or record there that the search found none. proves: manual
- [ ] WHEN a published rule differs from the shipped one, THE APP SHALL use the published one, and the income paid, the care charge and the result note SHALL all move with it. proves: `test_a_local_authority_funded_placement_stops_the_care_component_after_the_statutory_period`
<!-- AC:END -->

## Tasks
- [ ] Read regulation 8 of the Social Security (Attendance Allowance) Regulations 1991 and
      regulation 9 of the Social Security (Disability Living Allowance) Regulations 1991, and
      confirm the 28 days and that the mobility component is not caught.
- [ ] Confirm in the Care and Support (Charging and Assessment of Resources) Regulations 2014 that
      the care component is assessable income and the mobility component is disregarded.
- [ ] Check the linked-period rule: what counts as a break in residence that restarts the 28 days,
      and whether a hospital stay does. The engine restarts on any year out of care.
- [ ] Delete the "SOURCING GAP" block on `DisabilityBenefitInCare` once pinned, and move
      ASSUMPTIONS section 25 out of the sourcing-gap list.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if either rule moves, and note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. One file holds the
figure: `packages/finance-engine/src/Benefits/DisabilityBenefitInCare.php`. Nothing restates it: the
projector, the result note and both tests read the constants.

Run `php artisan test` after. `CareMeansTestedChargeTest` holds the two behaviour tests and
`InputNotesTest` asserts the stop period in the note text, reading the constant, so a corrected
figure moves all of them together.

## Comments
