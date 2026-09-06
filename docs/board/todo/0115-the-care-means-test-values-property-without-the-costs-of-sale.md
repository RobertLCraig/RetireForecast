# The care means test values a property with no deduction for the costs of sale

## Why
Card 0048 added the notional costs of sale to the pension-age BENEFITS means test:
`Benefits\CapitalAssessment::propertyCapital` values property at market value, less 10% for the
costs of selling it, then less anything secured on it. That is the rule the Housing Benefit and
Pension Credit capital regulations set.

The CARE financial assessment values property in its own place and did not move.
`PathProjector::careAssessableCapital` still adds the resident's share of the home at value less
mortgage and nothing else. The two are now different answers to the same question in one codebase,
which is the shape of fault this project has a standing rule against: one quantity, one definition.

The Care Act 2014 statutory guidance annex on the treatment of capital carries the same 10%
deduction where a sale would incur costs. **That is this session's recollection and was not read
off the guidance** (the session had no web access), so the first task is to confirm it rather than
to apply it.

What it costs if it is right: a resident is assessed on capital about 10% of the home's value too
high, which brings forward the year the local authority starts contributing and overstates what the
household bears. That reaches every plan where a resident lives alone in care or the home is let,
which is the plan the care modelling exists for.

## Links

**Relates to**
- `0048` - added the deduction on the benefits side and deliberately did not touch the care side.
- `0113` - pins the 10% figure itself. Settle that first, or this card applies an unpinned number
  in a second place.

## Not this card
The benefits means test, which is built. The 12-week property disregard and deferred payment
agreements, which are a documented v1 simplification in `careAssessableCapital` and are not carded.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL confirm from the Care Act 2014 statutory guidance whether the notional costs of sale apply to the care financial assessment, and record the answer with its source in docs/spec/ASSUMPTIONS.md. proves: manual
- [ ] IF they apply, THE APP SHALL value property capital for the care assessment through the one definition the benefits assessment already uses. proves: `test_the_care_assessment_deducts_the_notional_costs_of_sale`
<!-- AC:END -->

## Tasks
- [ ] Read the Care and Support Statutory Guidance, Annex B (treatment of capital), on property
      valuation and the 10% deduction.
- [ ] If it applies, call `CapitalAssessment::propertyCapital` from
      `PathProjector::careAssessableCapital` instead of subtracting the mortgage by hand.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`: every plan that models care and holds property
      moves.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The care valuation is
`PathProjector::careAssessableCapital`; the benefits one it should match is
`CapitalAssessment::propertyCapital`. Note the resident's share: the care assessment splits a
jointly held home equally between the members, and the deduction has to come off the whole value
before the split or it will not reconcile.

`CareMeansTest` covers the charge. The probe to read the answer through is the care charge itself,
because `ForecastResult` reports household totals and nothing else says which person holds an
asset.

Run `php artisan test` after.

## Comments
