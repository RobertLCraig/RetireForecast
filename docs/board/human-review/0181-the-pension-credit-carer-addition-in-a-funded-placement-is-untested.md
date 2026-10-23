---
no_outward_effect: "published" is the government's own rule, read and cited; nothing here is released
---
# The Pension Credit carer addition in a funded care placement has no test and no source

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
Card 0050 stopped the disability care component in a care placement the local authority funds.
While building it, `PathProjector::pensionCreditAward()` was also changed to drop the Pension Credit
**carer** addition for a partner when the disabled person is in a funded placement, through the
same `$inFundedCarePlacement` list. The card only asked for the severe disability addition.

The 2026-09-07 review and the 2026-09-28 manager pass on 0050 both noted it. Nothing tests the
carer change as its own case, and the rule carries no `source` or `verified_on`. It moves a
partner's Pension Credit award in every funded care year.

## Links

**Relates to**
- `0050` - made the change.
- `0118` - the same sourcing gap for the 0050 rules; one research pass can settle both.

## Not this card
The severe disability addition and the 28-day stop, which are 0050's and 0118's.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN the disabled member of a couple is in a local-authority-funded care placement, THE APP SHALL stop the partner's Pension Credit carer addition only as the published rule says. proves: `test_the_carer_addition_stops_when_the_cared_for_partner_is_in_a_funded_placement`
- [ ] THE APP SHALL carry a source URL and a verified_on date for the carer-addition rule in docs/spec/ASSUMPTIONS.md section 25, or record there that the search found none. proves: manual
<!-- AC:END -->

## Tasks
- [ ] Confirm when Carer's Allowance, and so the carer addition, stops once the cared-for person's
      Attendance Allowance or DLA care component stops in a funded placement.
- [ ] Write the test against a couple on Guarantee Credit, one disabled and in a funded placement.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if the rule moves.

## Plan
Needs a session with web access for the source. The code is in
`packages/finance-engine/src/Forecast/PathProjector.php`, `pensionCreditAward()`, the `$qualifies`
closure. Tests sit beside the 0050 ones in
`packages/finance-engine/tests/Forecast/CareMeansTestedChargeTest.php`.

## Comments

**2026-10-23** RESULT: partial
TESTS: +1 new, all green
TOUCHED: packages/finance-engine/tests/Forecast/CareMeansTestedChargeTest.php
TOUCHED: docs/spec/ASSUMPTIONS.md
TOUCHED: docs/board/todo/0226-pension-credit-still-assesses-a-couple-when-one-is-in-care.md
OUT-OF-SCOPE: 0226
WebFetch was refused in this session, so no published rule was read. Neither criterion is claimed.
#1: test_the_carer_addition_stops_when_the_cared_for_partner_is_in_a_funded_placement pins the stop in a FULL funded year (2047) and the addition paid before care (2045). Watched red with the carer loop reading the bare qualifying-benefit predicate. Not met: 'only as the published rule says' needs the source, and the first funded year (whole-year drop vs 28 days plus any run-on of the addition after Carer's Allowance ends) is untested on purpose.
#2: section 25 now records the carer rule as STATED, not verified, naming SPC Regs 2002 Sch I Part II. No source, no verified_on, and it says plainly that no search ran. Not met.
No rule moved, so ENGINE_VERSION is unchanged. A session with web access can finish both criteria together with 0118.
0226: Pension Credit still assesses a couple as one household when one partner is permanently in care (stated from memory, needs the source).

**2026-10-23** RESULT: blocked
TESTS: +0 new, all green
TOUCHED: none
OUT-OF-SCOPE: 0226
Second take. WebSearch and WebFetch were both refused in this unattended session, so the published rule could not be read. Nothing new was built.
The first take's work stands: test_the_carer_addition_stops_when_the_cared_for_partner_is_in_a_funded_placement still passes, and ASSUMPTIONS section 25 records the carer rule as STATED, not verified.
Both criteria need the source (SPC Regs 2002 Sch I Part II, and when Carer's Allowance stops after the cared-for person's AA/DLA care stops in a funded placement). Only a session with web access can finish this card, best together with 0118. Another unattended take will block the same way.

**2026-10-23** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.
