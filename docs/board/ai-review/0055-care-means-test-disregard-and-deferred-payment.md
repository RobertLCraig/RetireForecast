# The care means test can charge a bill the model has no way to pay

## Why
From the expert panel, 2026-08-19 (estate planner finding 6). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

**The property disregard is too narrow.** The home is disregarded only where one person remains.
The statutory disregard is **mandatory** where the property is occupied by the resident's spouse or
civil partner, a **relative aged 60 or over**, an incapacitated relative, or a child under 18, and
discretionary for a carer who gave up their own home. Any household with a resident older relative
gets the wrong answer.

**A charge with no funding route becomes a false plan failure.** The twelve-week disregard and
deferred payment agreements are dismissed in a comment as the same outcome. They are not.
`fundShortfall()` draws from cash, general investments, ISAs and pensions - **never the home**. So
an assessable home produces a care charge the projector cannot fund, the year is marked as failing
essentials, and the plan is penalised for keeping a property.

Under a deferred payment agreement the fee becomes a debt secured on the home, accruing interest,
and is deductible from the estate at death. That is a completely different estate and solvency
outcome from a forced sale. Modelling it turns a false failure into a correct estate reduction.

## Not this card
Disability benefits in the assessment, which is card 0050.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a home is occupied by a qualifying relative, THE APP SHALL disregard it from the care means test.
- [x] #2 WHEN a care charge cannot be met from liquid assets and the home is assessable, THE APP SHALL model a deferred payment secured on the home rather than reporting an unmet essential.
- [x] #3 WHEN a deferred payment is in place, THE APP SHALL accrue interest and deduct the balance from the estate at death.
<!-- AC:END -->

## Tasks
- [x] Widen the disregard to the statutory list; add a "who else lives here" input
- [x] Model a deferred payment agreement as the default funding route, with the statutory interest rate sourced
- [x] Deduct the accrued balance from the estate
- [x] Test that a keeping-the-home plan no longer fails essentials for an unfundable charge

## Comments

**2026-09-07**
RESULT: done
TESTS: +3 new, all green
TOUCHED:
- packages/finance-engine/src/Care/DeferredPaymentAgreement.php (new)
- packages/finance-engine/src/Dto/Property.php
- packages/finance-engine/src/Forecast/PathProjector.php
- packages/finance-engine/src/Forecast/YearResult.php
- packages/finance-engine/tests/Forecast/CareMeansTestedChargeTest.php
- packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php
- app/Forecast/HouseholdAssembler.php
- app/Forecast/ResultPresenter.php
- app/Forecast/ScenarioForecaster.php
- app/Livewire/ScenarioBuilder.php
- resources/views/livewire/scenario-builder.blade.php
- tests/Support/BuilderStateFixture.php
- docs/DECISIONS.md
- docs/spec/ASSUMPTIONS.md
- docs/board/todo/0129-pin-the-deferred-payment-rate-and-the-care-disregard-list.md (new)
- docs/board/todo/0130-care-assessment-ignores-the-smi-charge-on-the-home.md (new)
OUT-OF-SCOPE: 0129, 0130

**Criterion 1.** `Dto\Property::$occupiedByQualifyingRelative` is the reader's own statement that
somebody on the mandatory-disregard list lives in the home, and
`PathProjector::careHomeAssessable()` is now the one place the question is answered (the partner
case, the flag, and a let property, which no disregard reaches). It defaults FALSE, the adverse
answer, so nothing stored moves until somebody ticks it. The builder checkbox names the four
categories in plain words and says the carer case is the council's discretion and is not assumed.

**Criteria 2 and 3.** `Care\DeferredPaymentAgreement` owns the rate and the two caps; the balance
is `state['deferredCareBalance']`, built to the same shape as the Support for Mortgage Interest
charge beside it. The unfundable part of the CARE charge alone is deferred (a year that also ran
short on groceries still reports that part as unmet), capped at the equity the security can bear.
It rolls up in `growState`, is redeemed at a forced sale, and is deducted at both deaths.
`YearResult::deferredCareBalance()` reports it and `homeEquity()` nets it, so no wealth line
escapes it.

All three tests were watched failing first, each for its own criterion: the disregarded home still
charged the full £240,000 fee, £70,000 reported as unmet spend in the first care year, and a
balance of zero.

**Assumed.** The 4.65% maximum interest rate and the four-category disregard list are STATED, not
verified: this session had no web. Both reach a projection, so both are written up in
ASSUMPTIONS.md §28 and carded as **0129**. The rate is the adverse (high) end of the range the
published rule has produced, per Rob's standing default rule.

**The estate deducts the balance as at settlement, which is one roll-up period after the last
reported year.** Death is detected at the start of the year after the last living one, by which
time `growState` has rolled every secured balance once more. That is the engine's existing
convention for the mortgage and the SMI charge, so the deferred balance follows it rather than
being special-cased; the test pins the rolled figure and says why.

**ENGINE_VERSION is `finance-engine/deferred-care-payment-on-the-home` and the stored-scenario
re-run is owed.** Every plan whose paths reach an unfundable care year moves: it survives where it
used to fail, with a smaller estate. The Monte Carlo golden master reddened exactly as that
predicts (essentials success 0.5000 to 0.5300, terminal wealth down at every percentile) and was
re-pinned with `PIN_REVISION` bumped and the DECISIONS.md entry its companion test demands.

**Not settled from the repository, and left alone.** The twelve-week property disregard, which the
card's Why mentions but its acceptance does not ask for; the disposable income allowance, which
would let a resident defer MORE than the genuinely unfundable part (so the engine is cautious here,
flagged in code); and the council's set-up and administration fees, which are named in the results
note as absent.

Built in a worktree, so the new builder checkbox and the new results note **have not been seen in a
browser**.
