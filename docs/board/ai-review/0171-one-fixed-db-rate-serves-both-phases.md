# One fixed DB rate serves both deferment and payment

## Why
Found by the 2026-09-05 scope review of card 0035, repeated by the 2026-09-28 manager pass, and
still true on 2026-10-06. `DbPension` carries one `fixedEscalationRate`. `PathProjector` flattens it
to one `fixedRate` per scheme and `escalateDbPensions()` passes it to both phases. The builder shows
one box when either dropdown says Fixed. So a scheme that revalues at a fixed rate in deferment and
escalates at a different fixed rate in payment cannot be entered.

## Links

**Relates to**
- `0035` - separated the two bases but not the rate.
- `0095` - sources the default fixed rate.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a scheme is set to Fixed in both phases with two different rates, THE APP SHALL revalue at the deferment rate until normal retirement age and escalate at the in-payment rate after it. proves: `test_a_fixed_db_scheme_uses_its_own_rate_in_each_phase`
<!-- AC:END -->

## Plan
Split `fixedEscalationRate` into a revaluation rate and an in-payment rate on `DbPension`, the
builder and `HouseholdAssembler`, and pass the phase's own rate in `escalateDbPensions()`. A new
builder field moves four things together (blank default, validation, load backfill,
`BuilderStateFixture::full`). Each blank rate stays disclosed as an assumed figure.

## Comments

**2026-10-22** RESULT: done
TESTS: +1 new (test_a_fixed_db_scheme_uses_its_own_rate_in_each_phase), plus assembler assertions; all green
TOUCHED: app/Forecast/HouseholdAssembler.php
app/Livewire/ScenarioBuilder.php
packages/finance-engine/src/Dto/DbPension.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/DbEscalationTest.php
resources/views/livewire/scenario-builder.blade.php
tests/Support/BuilderStateFixture.php
tests/Unit/Forecast/HouseholdAssemblerTest.php
OUT-OF-SCOPE: none
DbPension gains fixedRevaluationRate; fixedEscalationRate is now the in-payment rate. escalateDbPensions passes each phase its own rate. Watched red first: deferment compounded at 5% (2986307p) instead of 2% (2234823p).
Builder: one box per Fixed dropdown; blank default, validation, loadState backfill and BuilderStateFixture::full moved together.
Assumed: a scheme saved before the split has no revaluation key, so the assembler and loadState fall back to its single rate. No stored figure moves, so no ENGINE_VERSION bump and no re-run owed.
Disclosure: fixedEscalationIsAssumed now checks each Fixed phase's own rate, so a blank revaluation rate beside an entered in-payment rate is disclosed.
The two builder boxes are NOT seen in a browser (built in a worktree).
