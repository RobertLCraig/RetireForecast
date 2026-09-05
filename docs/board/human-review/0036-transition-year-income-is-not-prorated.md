# Retirement year pays a part-year salary and a full year of pension

## Why
From the expert panel, 2026-08-19 (engineer finding F2). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Three asymmetries in the same year, all favouring the household:

- `statePensionIncome()` pays the **full** annual amount from the claim year, so a State Pension
  starting in November pays about ten months too much.
- `dbIncome()` pays the **full** annual amount in the year the member reaches normal retirement
  age.
- `niForPerson()` switches National Insurance off for the **whole** calendar year in which State
  Pension age is reached, including the months before it.

Meanwhile `workFraction()` correctly prorates salary, per the 2026-06-30 decision that salary
would not be paid for a full calendar year if you leave in July. The reasoning that produced that
fix applies word for word to the income replacing it, and was not carried across.

The transition year is exactly where an affordability cliff would show, and all three errors point
the same way.

Two of the three fixes need the State Pension age **month**, which `initialState()` already
computes and then discards.

## Not this card
The salary proration, which is already correct.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a State Pension starts part way through a year, THE APP SHALL pay only the part of the year after the entitlement date.
- [x] #2 WHEN a defined-benefit pension starts at normal retirement age, THE APP SHALL pay only the part of the year after that birthday.
- [x] #3 WHEN a person reaches State Pension age part way through a year, THE APP SHALL charge National Insurance on the earnings before that date.
<!-- AC:END -->

## Tasks
- [x] Add a `startFraction` helper beside `workFraction`
- [x] Keep the State Pension age month in projector state
- [x] Apply the fraction in `statePensionIncome`, `dbIncome` and `niForPerson`
- [x] Tests for a birthday in each quarter
- [ ] Re-run every stored scenario

## Comments

**2026-09-05**
RESULT: done
TESTS: +3 new, all green
TOUCHED:
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/TransitionYearProrationTest.php
packages/finance-engine/tests/Forecast/DbCommutationTest.php
packages/finance-engine/tests/Forecast/DbEscalationTest.php
packages/finance-engine/tests/Forecast/StatePensionDeferralTest.php
app/Forecast/ScenarioForecaster.php
docs/spec/METHODOLOGY.md
docs/board/todo/0096-national-insurance-thresholds-are-annual-in-a-part-year.md
docs/board/todo/0097-pension-credit-treats-the-state-pension-age-year-as-a-whole-year.md
OUT-OF-SCOPE: 0096, 0097

`initialState` now keeps `spaMonth` beside `spaYear`, and a `startFraction($month)` helper sits
beside `workFraction` as the exact complement of it: month n divides the year at the end of that
month, salary takes n/12 and the income replacing it takes (12 - n)/12. `statePensionIncome` applies
it in the claim year, `dbIncome` in the year `age === normalRetirementAge`, and `niForPerson` charges
`min(workFraction, spaMonth/12)` of the salary with the calculator's own State Pension age switch off,
because the slice handed to it already excludes everything after that date.

The three tests each run a birthday in February, May, August and November. The State Pension case
needs no restatement of the triple-lock rule: a control person reaching State Pension age in December
2031 gives the whole-year figure for 2032 out of the same run, so the four part years are asserted
against it. The DB case pins a scheme with no increase in either phase, so the nominal figure is the
accrued pension itself. The NI case reads the difference between an ordinary earner's total tax and
the same earner in category X, which cancels income tax and leaves NI alone; the salary is £120,000
so that even a February birthday's two twelfths clears the primary threshold and the assertion is not
a trivial zero. All three were watched failing first, on the right numbers: a whole year of State
Pension where ten twelfths were due, a whole DB pension where ten twelfths were due, and £0 of NI
where the months before the State Pension age date were liable.

Six existing tests drifted on the deliberate change, all of them reading a figure in the transition
year itself, and all were moved to the first whole year or given the part-year expectation:
`DbCommutationTest` x3 (2031 to 2032), `DbEscalationTest` x2 (2036 kept, times 11/12) and
`StatePensionDeferralTest`'s uplift ratio (2027 to 2028). None was weakened; each still asserts what
it was written to assert.

`ENGINE_VERSION` is `finance-engine/transition-year-proration`. Every stored plan with a retirement
inside its horizon banked too much income and too little NI in that year, so its wealth, depletion
year and success odds are too FAVOURABLE; a plan whose members are all past State Pension age and
normal retirement age in the base year is byte-identical. Built in a worktree, so the
**stored-scenario re-run is owed**. `scenarios:audit` was run read-only from here and reports 120
problems, every one of them the pre-existing "run N carries no integrity stamp" line and no other
class, which is the baseline the handover already records. No new UI control ships, so there is
nothing new to look at in a browser, but the figures on every results page move.

Two adjacent faults were found and left alone, carded rather than fixed. **0096:** NI thresholds are
annual, where real NI is assessed per pay period, so a part year is charged against a whole year's
threshold. That approximation predates this card (it already applied to the retirement year), but
this card gives it a second place to bite, and it under-charges by roughly five times on a
three-month slice. It is noted as a v1 limit in `niForPerson` pointing at the card. **0097:** the
Pension Credit qualifying-age gate awards fifty-two weeks in the year State Pension age is reached,
and this card makes that worse in passing, because the now-correct part-year State Pension lowers the
assessable weekly income the award is computed from. `notionalDeferredStatePensionNominal` has the
same whole-year fault running the other way. Both are on the same card because they are one finding
about one block.

Nothing needed a figure the repository could not supply: the month comes from `StatePensionAge`,
which `initialState` was already computing and throwing away, and from the date of birth.

### 2026-09-05 review (v20260905182913-d18f)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 221s, run by this job rather than reported by the card.

**acceptance: defect**

I traced each criterion.

**#1** ÔÇö `PathProjector::statePensionIncome` multiplies the annual figure by `self::startFraction($spaMonth)` in the claim year; `PathProjector::initialState` keeps `spaMonth` from `StatePensionAge::dateReached`. Real. Covered by `TransitionYearProrationTest::test_state_pension_is_prorated_in_the_year_the_entitlement_starts`.

**#2** ÔÇö `PathProjector::dbIncome` applies `startFraction` on the birth month when `age === normalRetirementAge`. It is the only place DB income is paid. Real.

**#3** ÔÇö `PathProjector::niForPerson` charges `min(workFraction, spaMonth/12)` of salary and passes `hasReachedStatePensionAge: false`. It is the only caller of `onEmploymentEarnings` in the engine. Real.

One defect, in what the work says the code does.

`PathProjector::startFraction` divides the year at the **end** of month n, so a November State Pension pays **1/12**. But `PathProjector::statePensionIncome`'s comment says "a pension starting in November pays two months", and `docs/spec/METHODOLOGY.md` (the yearly-loop step 1) tells the reader the same: "a State Pension first paid in November counts two months in that year... counted to the nearest whole month". The engine pays half that, and does not round to nearest. The spec now describes a behaviour the engine does not have.

VERDICT: defect

**scope: defect**

**Scope check on card 0036.**

**The fence held.** `workFraction` in `packages/finance-engine/src/Forecast/PathProjector.php` is untouched. Salary proration was not re-opened.

**Nothing grew past the ask.** The code change is only the three named functions plus the helper: `startFraction`, `statePensionIncome`, `dbIncome`, `niForPerson`, and the `spaMonth` entry in `initialState`. The `dbIncome` signature change from an id to a `Person` is needed for the birth month, not extra work. `docs/spec/METHODOLOGY.md` and the `ENGINE_VERSION` docblock in `app/Forecast/ScenarioForecaster.php` are the house rules, not creep. Cards 0096 and 0097 raise adjacent faults instead of fixing them, which is correct.

**One task is left undone, and the card says so.** "Re-run every stored scenario" is unticked in `docs/board/ai-review/0036-transition-year-income-is-not-prorated.md`. `ENGINE_VERSION` in `app/Forecast/ScenarioForecaster.php` was bumped, so every stored result was made stale by this change and nothing re-ran. The build happened in a worktree, so it could not run. That is the card's own task list, unfinished.

Also noted, not counted against the card: `notionalDeferredStatePensionNominal` still counts a whole year, so one run now holds two State Pension conventions. Card 0097 owns it.

VERDICT: defect

**breakage: defect**

**Finding ÔÇö the same rule, asserted in two of three siblings.**

`PathProjector::processAnnuityPurchases` buys an annuity when the owner reaches `atAge`, which is a birthday part way through the year, exactly like the DB normal retirement age this card just fixed. `PathProjector::annuityIncomeNominal` then pays `baseIncomeNominal` in full for that whole calendar year. The pot is emptied on the birthday and twelve months of income come back. That is the third head of the same asymmetry named in the card, it runs the household's way, and it is neither fixed nor carded (0096 and 0097 cover other things).

`PathProjector::incomeStreamsNominal` has the same shape at `startAge`.

This makes the docs false, not just incomplete. `docs/spec/METHODOLOGY.md`, step 1 of "Putting it together", lists annuities among the sources and then states the general rule: "The retirement year is split on both sides... the income replacing it starts on its own date rather than on 1 January." An annuity bought at 66 does not. `PathProjector::startFraction`'s own docblock states the same general rationale.

Fix: apply `startFraction` in the purchase year, or card it and narrow the doc claim.

VERDICT: defect


**2026-09-05** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 3 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 3 of 3 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
