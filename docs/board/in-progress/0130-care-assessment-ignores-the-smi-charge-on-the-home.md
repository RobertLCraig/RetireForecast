# The care means test assesses home equity the DWP already has a charge over

## Why
Found while building card 0055. `PathProjector::careHomeEquity` values the home for a care
financial assessment at value less the mortgage less any deferred care payment. It does NOT deduct
`state['smiBalance']`, the Support for Mortgage Interest charge that card 0045 added.

That charge is a real second debt secured on the same bricks. Every other reader of home equity
nets it: `YearResult::homeEquity()` does, `EstateValuer` does at both deaths, and the forced sale
redeems it out of the proceeds. Only the care assessment does not, so one quantity has two
definitions, which is the standing data-integrity rule this project keeps.

What it costs: a household on Guarantee Credit taking SMI has its assessable capital overstated by
the whole rolled-up charge, which can hold a resident on the wrong side of the £23,250 upper limit.
That does three things at once, all of them wrong in the same direction: it charges the household
the full self-funder fee where the authority should have been contributing, it keeps the resident
counted as a self-funder so the 28-day disability-benefit stop never fires, and it now inflates the
deferred payment the household is asked to carry against equity it does not have. The households it
hits are the poorest ones the tool models, because SMI only reaches a household on Guarantee
Credit.

## Links

**Relates to**
- `0045` - added the SMI charge and every other place that nets it.
- `0055` - added the deferred payment balance, which IS netted here, and flagged this gap in
  `careHomeEquity`'s docblock.
- `0115` - the other divergence in the same function: no deduction for the notional costs of sale.
  Both are the same shape of fault and one session could reasonably close both.

## Not this card
The notional costs of sale, which is card 0115.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a Support for Mortgage Interest charge stands against the home, THE APP SHALL value the home for the care financial assessment net of that charge. proves: `test_the_care_assessment_nets_the_support_for_mortgage_interest_charge`
<!-- AC:END -->

## Tasks
- [ ] Subtract `state['smiBalance']` in `PathProjector::careHomeEquity` and delete the FLAGGED
      paragraph in its docblock that records the divergence.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`: every plan that reaches Guarantee Credit while
      still owning a home AND reaches care moves.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The whole fix is one term in
`PathProjector::careHomeEquity`; the docblock there names the gap and points here.

The probe is the care charge itself: `ForecastResult` reports household totals only, so nothing
else says which person holds what. `SupportForMortgageInterestTest` already builds a household that
takes SMI for life, and `CareMeansTestedChargeTest` builds one that reaches care, so the fixture is
the two crossed: a lone Guarantee Credit homeowner whose SMI balance has grown enough to drag their
assessed capital below the upper limit, charged the funded contribution rather than the full fee.

Watch the test fail before the fix, or it proves nothing: with a small SMI balance the assessment
lands on the same side of the limit either way and the test is green whatever the code does.

The Monte Carlo golden master will probably redden. Re-pin it, bump `PIN_REVISION` and write the
DECISIONS.md entry, per that test's own docblock.

Run `php artisan test` after.

## Comments
