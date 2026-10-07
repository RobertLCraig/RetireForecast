# The Pension Credit carer addition in a funded care placement has no test and no source

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
