# Housing Benefit is paid on the whole rent, with no Local Housing Allowance cap

## Why
Card 0048 awarded a pension-age renter Housing Benefit, and treated the WHOLE rent as eligible
rent. In life a private tenant's eligible rent is capped at the Local Housing Allowance rate for
their broad rental market area and their household size, and rent above that rate is met by nobody.

So wherever the modelled rent is above the local cap, this engine pays more Housing Benefit than
DWP would. It is the OPTIMISTIC direction, against the standing rule that where several figures are
plausible the model defaults to the most adverse. It flatters exactly the leg card 0048 set out to
stop flattering the other way, and the size of the error is unbounded: a household modelling a
£25,000 rent in an area whose cap is £12,000 is credited with roughly twice the help it would get.

The reason it shipped that way is that the engine holds no LHA table and cannot derive one. There
is one rate per broad rental market area per bedroom entitlement, re-set every April, and the
unattended session that built card 0048 had **no web access**.

## Links

**Relates to**
- `0048` - awarded the benefit, and flagged this limit in `Benefits\HousingBenefit`.
- `0113` - pins the taper and the sale-costs figures. Same file, same missing web access.

## Not this card
The taper, the capital limit and the Guarantee Credit passport, which are built. Ineligible service
charges inside a rent and non-dependant deductions, which are the two smaller v1 limits flagged
beside this one and are not carded.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a modelled rent is above the household's Local Housing Allowance rate, THE APP SHALL cap the eligible rent at that rate before the award is worked out. proves: `test_eligible_rent_is_capped_at_the_local_housing_allowance_rate`
- [ ] THE APP SHALL state on the plan which LHA rate was applied and where it came from, or that none was and the award is therefore the optimistic end. proves: `test_a_rent_plan_states_what_housing_benefit_does_for_it`
<!-- AC:END -->

## Tasks
- [ ] Decide between the builder input and the shipped table, and record the choice in DECISIONS.md.
- [ ] Cap eligible rent in `Benefits\HousingBenefit::annualAward`.
- [ ] Update the `housing_benefit` result note, which currently names this as a gap.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`: every capped rent plan moves.

## Plan
**Two shapes of answer, and choosing between them is Task 1.**

1. **A builder input.** The reader looks their LHA rate up on gov.uk and enters it, and the engine
   caps eligible rent at it. Cheap, exact for the household that does the lookup, and consistent
   with how every other local figure (the council tax bill, the service charge) reaches this tool.
   A blank input then has to mean something, and the adverse reading is to cap at nothing entered.
2. **A shipped table.** Accurate with no lookup, and stale within a year unless somebody re-fetches
   190-odd areas every April.

Stand in `C:\Dev\RetireForecast` on `master`. One engine file holds the award
(`packages/finance-engine/src/Benefits/HousingBenefit.php`) and one call site charges it
(`PathProjector::housingBenefitNominal`). The note is in `ResultPresenter::inputNotes`, marked
`(c4d)`.

If the answer is a builder input, the four things that move together for a new builder field are
the blank default, the validation, the `loadState` backfill and `BuilderStateFixture::full`. Leave
the default BLANK and treat a blank as no cap only if that is written down as a deliberate call:
the adverse reading is the other way.

Run `php artisan test` after. `HousingBenefitTest` is where the capping case belongs.

## Comments
