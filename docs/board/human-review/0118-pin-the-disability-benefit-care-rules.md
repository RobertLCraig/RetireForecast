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

**2026-10-15** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card needs the web and this session has none. WebSearch and WebFetch were both refused (permission not granted), the same gap the card's own Why names. So I could not read AA Regs 1991 reg 7, DLA Regs 1991 reg 9, or the Care and Support (Charging and Assessment of Resources) Regs 2014 Sch 1.

Criterion 1 not met: no source URL or verified_on date can be honestly written into ASSUMPTIONS section 25, and 'the search found none' would be false because no search ran.
Criterion 2 not met: it turns on whether the published rule differs from the shipped 28 days and mobility disregard, which only the read can say. The proves test test_a_local_authority_funded_placement_stops_the_care_component_after_the_statutory_period already exists (packages/finance-engine/tests/Forecast/CareMeansTestedChargeTest.php:407) and reads the constant, so a corrected figure moves it with no test edit.

Next step: run this card in an attended session with WebFetch allowed, or mark it not_for_the_loop. Cards 0106, 0109, 0111, 0113 share the same block and can be settled in one research pass. The SOURCING GAP block on DisabilityBenefitInCare.php:26 stays until then.

**2026-10-15** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block. WebFetch and WebSearch were both refused again (permission not granted). I did not route round the refusal with curl. No local copy of the legislation exists: docs/research/RESEARCH-0106 does not cover the care-home stop or the mobility disregard, and the only mentions of the SIs are this card and ASSUMPTIONS section 25.

Criterion 1 not met: no source URL or verified_on date can be written honestly, and 'the search found none' would be false because no search ran.
Criterion 2 not met: it depends on whether the published rule differs from the shipped 28 days and mobility disregard, which only the read can settle. Its proves test already exists (CareMeansTestedChargeTest.php:407) and reads the constant, so a corrected figure moves it with no test edit.

This card cannot advance in the unattended loop. Next step: run it attended with WebFetch allowed, or mark it not_for_the_loop. Cards 0106, 0109, 0111, 0113 share the block and fit one research pass.

**2026-10-15** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.
