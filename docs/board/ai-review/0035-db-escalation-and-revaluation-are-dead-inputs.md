# The pension escalation dropdown does nothing

## Why
From the expert panel, 2026-08-19 (engineer finding F1). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`PathProjector::dbEscalation()` returns the inflation rate and nothing else. Every defined-benefit
pension escalates at full CPI for ever, whatever the user chose.

The field is stored, validated in `ScenarioBuilder`, rendered as a select in the builder view, and
mapped onto the DTO by `HouseholdAssembler`. It is never read. `AnnuityPurchase::escalation` **is**
honoured, so this is an omission rather than a deliberate simplification.

The consequence is not small. Pre-1997 accrual commonly has no statutory escalation at all, so a
user modelling that honestly is handed a forecast in which it rises with inflation for thirty
years.

`revaluationBasis` has the same problem. `dbIncome()` applies the single in-payment factor from the
base year regardless of age, so deferred revaluation and in-payment escalation are
indistinguishable.

This is worse than a magic number. A control that does nothing tells the user they said something
the model never heard.

## Not this card
Annuity escalation, which is already honoured.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a defined-benefit pension is set to no escalation in payment, THE APP SHALL hold it flat in nominal terms for the whole projection.
- [x] #2 WHEN a capped escalation basis is chosen, THE APP SHALL apply inflation up to the cap and no more.
- [x] #3 WHEN a pension is deferred, THE APP SHALL revalue it on its revaluation basis until normal retirement age, then escalate it on its in-payment basis.
<!-- AC:END -->

## Tasks
- [x] Make the escalation factor per-pension in projector state, not one household factor
- [x] Implement each `PensionEscalationBasis` case, adding a capped-at-2.5% variant
- [x] Add a `fixedEscalationRate` to `DbPension` for the fixed case, disclosed as an assumed figure
- [ ] Source an RPI-over-CPI wedge for the RPI case, with `source` and `verified_on`
- [x] Separate the deferred revaluation factor from the in-payment factor
- [ ] Test all five bases produce different year-20 incomes

## Comments

**2026-09-05**
RESULT: done
TESTS: +12 new, all green
TOUCHED:
packages/finance-engine/src/Dto/PensionEscalationBasis.php
packages/finance-engine/src/Dto/DbPension.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/DbEscalationTest.php
app/Forecast/HouseholdAssembler.php
app/Forecast/ResultPresenter.php
app/Forecast/ScenarioForecaster.php
app/Livewire/ScenarioBuilder.php
resources/views/livewire/scenario-builder.blade.php
tests/Support/BuilderStateFixture.php
tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
tests/Unit/Forecast/HouseholdAssemblerTest.php
docs/spec/ASSUMPTIONS.md
docs/HANDOVER.md
docs/board/todo/0095-source-the-db-escalation-figures.md
OUT-OF-SCOPE: 0095

All three acceptance criteria are met and were watched failing first, against the projector as it
stood: every basis returned the identical CPI-escalated figure, and a deferred pension arrived at
normal retirement age carrying ten years of increases its revaluation basis never granted.

What was built. `PathProjector` no longer carries one household `dbFactor`. It carries
`state['dbFactors']`, one running factor per Defined Benefit scheme keyed by the pension's position
in `Household::$pensions`, alongside `state['dbSchemes']`, which flattens each scheme's owner,
normal retirement age, two bases and fixed rate so `growState()` can escalate without the Household
in hand. `escalateDbPensions()` chooses the basis by phase: the bump landing on a year at or before
normal retirement age is deferment and uses the revaluation basis, every later bump uses the
in-payment basis. `dbIncome()`, `commutationLumpSumNominal()` and `survivorDbIncomeNominal()` now
take the factor map and pair each scheme with its own.

The rule itself lives on `PensionEscalationBasis::increase()`, which is the single home for it, with
`capBasisPoints()` owning the two statutory limited-price ceilings. A `cpi_capped_2_5` case is added
for post-2005 accrual. Both capped cases are FLOORED at zero as well as capped, because limited
price indexation is defined that way and a scheme does not cut a pension in payment when prices
fall; the uncapped CPI case is left as it was. `label()` sits beside them so the builder select
renders from the enum and cannot offer a basis the engine does not model.

`DbPension` gains `fixedEscalationRate`, a builder input shown only when either dropdown is set to
Fixed, with `fixedEscalationRate()` and `fixedEscalationIsAssumed()` mirroring the
`propertyCostsRealGrowth` pattern. Blank takes `DEFAULT_FIXED_ESCALATION_BPS` (3%), which is
disclosed on the results page reading the constant. The new builder key defaults blank and is
backfilled on load, and `BuilderStateFixture::full()` carries it, so no what-if child records a
delta for it (this is the gotcha card 0031 hit; `ScenarioChildTest` caught it here too).

`ENGINE_VERSION` is bumped to `finance-engine/db-escalation-per-scheme`. A stored plan whose scheme
is on plain CPI in both phases is byte-identical; anything else moves, and a frozen or capped
pension was previously banking income nobody promised it, so its wealth, depletion year and success
odds were too favourable. **The stored-scenario re-run is owed.** Built in a worktree, so **the two
new builder controls have not been seen in a browser** and neither has the results-page disclosure.

What I could not settle from the repository. There is no sourced RPI-over-CPI wedge here, and an
unattended session has no web access (WebSearch is refused), so I did not invent one.
`RPI_OVER_CPI_WEDGE_BPS` ships at **zero**: RPI is being aligned with CPIH from February 2030, so a
plan of this length spends nearly all of its years past the point the two agree, and zero is the
adverse reading for income the household receives. That reasoning is this session's own knowledge
and is NOT verified against a primary source, which the constant's docblock says out loud. The
consequence is that the RPI option still lands on the same figure as CPI. It is no longer SILENT
about it, which was the card's actual complaint: a reader who picks RPI is told, in an
`assumed_figure` note, that the model escalates it at CPI and why. The 3% fixed default is judgement
in the same way. Both are written up in ASSUMPTIONS.md §18 as the sixth sourcing gap and raised as
card **0095**.

That is why the last task is left open. `test_each_escalation_basis_reaches_a_different_twenty_year_income`
proves five bases reach five different twenty-year incomes and pins their order, but the five are
None, CPI, both caps and Fixed. RPI is the sixth and is asserted separately, in
`test_rpi_escalates_at_cpi_because_no_wedge_is_modelled`, which reads the wedge constant and so
fails the moment somebody introduces a wedge without moving the disclosure with it. The task as
written asked for five DIFFERENT bases including RPI, and that is not true today.

One test note. The escalation assertions read the year's NOMINAL twin (`YearResult::$nominal`), not
its real figures. Read in real terms, a CPI-escalated pension and a frozen one are told apart only
by the deflator, which is the thing under test wearing a disguise; the first draft of these tests
was green on a flat 20,000 for every basis for exactly that reason.

Two guards were added rather than assumed. `test_every_db_escalation_basis_and_its_fixed_rate_reach_the_dto`
in `HouseholdAssemblerTest` is the completeness guard for the builder path, since the whole defect
was a field that was stored, validated, rendered and mapped and then read by nothing; it was proved
red by temporarily dropping the assembler line. The two new disclosure cases in
`AssumedFiguresDisclosureTest` were watched failing before the presenter was touched.
