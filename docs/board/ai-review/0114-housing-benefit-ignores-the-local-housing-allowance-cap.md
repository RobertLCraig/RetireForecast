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
- [x] WHEN a modelled rent is above the household's Local Housing Allowance rate, THE APP SHALL cap the eligible rent at that rate before the award is worked out. proves: `test_eligible_rent_is_capped_at_the_local_housing_allowance_rate`
- [x] THE APP SHALL state on the plan which LHA rate was applied and where it came from, or that none was and the award is therefore the optimistic end. proves: `test_a_rent_plan_states_what_housing_benefit_does_for_it`
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

**2026-10-14** RESULT: done
TESTS: +1 new (test_eligible_rent_is_capped_at_the_local_housing_allowance_rate), 1 extended (test_a_rent_plan_states_what_housing_benefit_does_for_it), all green
TOUCHED: app/Forecast/HouseholdAssembler.php
app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
app/Forecast/WhatIfChanges.php
app/Livewire/ScenarioBuilder.php
docs/DECISIONS.md
docs/spec/ASSUMPTIONS.md
packages/finance-engine/src/Benefits/HousingBenefit.php
packages/finance-engine/src/Dto/HousingAction.php
packages/finance-engine/src/Forecast/ForecastSettings.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Housing/HousingComparison.php
packages/finance-engine/tests/Forecast/HousingBenefitTest.php
resources/views/livewire/scenario-builder.blade.php
tests/Support/BuilderStateFixture.php
tests/Unit/Forecast/InputNotesTest.php
OUT-OF-SCOPE: none

Task 1 chose the builder input (DECISIONS 2026-10-14). The shipped table was not possible here: no web access to fetch rates. New field housing.rentLhaWeekly, entered WEEKLY because LHA rates are published weekly; the engine caps annual eligible rent at rate x weeksPerYear x the rent's growth factor, inside HousingBenefit::annualAward, before the passport and the taper. The four builder-field moves (blank default, validation, loadState backfill, BuilderStateFixture::full) went together. ENGINE_VERSION bumped to finance-engine/housing-benefit-lha-cap.

Watched red: criterion 1 failed with 800000 vs 520000 (the full rent paid, not the cap); criterion 2 failed on the old note text, which had no 'No Local Housing Allowance rate was entered' line.

Two calls Rob may want to revisit, both written down: (1) BLANK CAPS NOTHING. The card names the adverse reading the other way. I took no-cap because the adverse reading of a blank would award nothing and understate every stored rent plan by the whole award; AC2 itself names the 'none entered, optimistic end' state, and the note says so in words. The switch is one null check in PathProjector::housingBenefitNominal. (2) The cap grows each year with the rent factor, so the gap entered stays the gap. Real LHA rates have been frozen for long spells, which would make a flat-nominal cap the more adverse choice; the note discloses the 'grown each year with the rent' assumption.

Not checked in a browser: the new input on the housing step and the reworded note still need one (Herd serves C:\Dev\RetireForecast, not this worktree). Suite run via php artisan test (no pest.bat in this repo); pint --dirty clean.
