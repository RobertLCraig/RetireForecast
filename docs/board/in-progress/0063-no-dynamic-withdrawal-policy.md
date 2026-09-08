# The household spends the same in real terms whatever happens

## Why
From the expert panel, 2026-08-19 (adviser finding 7). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`. The adviser-parity plan lists this as backlog.

Every path is scored against a fixed real spending target. `SpendPath` supports a planned
age-banded decline, but nothing is **state-dependent**. The household never cuts back after a bad
run of returns and never spends more after a good one.

That has two effects, both real.

A constant-real-spend simulation **overstates** the chance of running out, because no household
actually carries on spending into insolvency.

And it hides the cheapest mitigation there is. A guardrail that trims discretionary spend after a
bad run costs nothing and buys a lot of survival probability - **unless the household has no
discretionary spend left**, in which case that is itself the finding, and the tool cannot currently
state it.

One rule is enough. A funded-ratio trigger is the cheapest honest version: if projected usable
wealth falls below a multiple of remaining essential spend, cut discretionary spend by a set
percentage until it recovers. Published rules exist and are citable.

Reporting matters as much as the rule: show how often and how deeply it bit, not just the improved
probability.

## Not this card
Withdrawal sequencing across wrappers, which is card 0007.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL offer a spending guardrail that reduces discretionary spend when wealth falls below a trigger, and restores it on recovery.
- [x] #2 THE APP SHALL expose the trigger and the reduction as editable inputs with a sourced default.
- [x] #3 WHEN a guardrail is in use, THE APP SHALL report how many years it bit and by how much.
- [x] #4 WHEN a household has no discretionary spend left to cut, THE APP SHALL say so.
<!-- AC:END -->

## Tasks
- [x] Implement one funded-ratio guardrail in the projector, sourced and dated
- [x] Report the bite count and depth alongside the success probability
- [x] State the no-flexibility-left case explicitly

## Comments

**2026-09-08**
RESULT: done
TESTS: +15 new, all green
TOUCHED: packages/finance-engine/src/Dto/SpendingGuardrail.php
TOUCHED: packages/finance-engine/src/Dto/ExpenseProfile.php
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: packages/finance-engine/src/Forecast/YearResult.php
TOUCHED: packages/finance-engine/src/Support/WarningCode.php
TOUCHED: packages/finance-engine/tests/Forecast/SpendingGuardrailTest.php
TOUCHED: app/Forecast/HouseholdAssembler.php
TOUCHED: app/Forecast/ResultPresenter.php
TOUCHED: app/Livewire/ScenarioBuilder.php
TOUCHED: resources/views/livewire/scenario-builder.blade.php
TOUCHED: tests/Feature/Forecast/SpendingGuardrailNoticeTest.php
TOUCHED: tests/Feature/Livewire/ScenarioBuilderTest.php
TOUCHED: docs/spec/ASSUMPTIONS.md
TOUCHED: docs/board/todo/0138-source-the-spending-guardrail-trigger-and-cut.md
OUT-OF-SCOPE: 0138

`Dto\SpendingGuardrail` is the one home of the rule and of both of its figures. Usable wealth is
liquid plus pension, the same definition the forecast already reports as terminal usable wealth, so
the home a household lives in is never counted as money it can spend. It is divided by the
essential spend still to be funded: this year's essential floor times
`PathProjector::yearsRemaining()`, which reads the same death ages the projection loop ends on, so
the rule and the plan can never describe different lifespans. Below the trigger, the cut comes off
the DISCRETIONARY part alone and the essential floor is never touched.

The test is re-run every year off that year's OPENING wealth, before the drawdown that funds the
year. That is what makes recovery free: there is no latch to get stuck, so a plan whose ratio
climbs back through the trigger simply spends its full target again. `test_the_cut_is_put_back_when
_the_plan_recovers` pins that with a household whose income covers its spend, so its wealth holds
while the essential spend still to fund shrinks year by year.

**The guardrail is opt-in and no stored scenario has it, so every figure is byte-identical, there
is no `ENGINE_VERSION` bump and no stored re-run is owed.** `MonteCarlo\GoldenMasterTest` did not
redden and needs no re-pin. Opt-in is also the adverse choice on Rob's standing rule: a guardrail
is the one setting in this tool that makes a plan look better, so the default is the one that does
not have it.

Reporting rides `ResultPresenter::inputNotes()` rather than a new results section, so the results
page and the PDF carry the same words off the same figures with no `$sections` entry to keep in
step. The note counts the biting years, the total trimmed and the deepest single year off the
projection itself, and says in as many words that the odds above are the odds of surviving on a
smaller life. The no-flexibility case is a separate note on its own warning code
(`GUARDRAIL_NO_FLEXIBILITY`), because a rule that was asked to work and had nothing to work with is
a finding, and "it trimmed £0" would read as a rule nobody ever called.

Assumed: **both default figures are STATED, not verified** (no web in an unattended session). The
funded-ratio trigger of 1.00 is argued from the actuarial definition of a fully funded plan and the
10% cut is attributed to the Guyton-Klinger capital-preservation rule from memory of the
literature; the paper has not been read here. Written up at docs/spec/ASSUMPTIONS.md section 34 and
carded as **0138**, which also carries the question this session could not settle: whether the
published rules trigger on a funded ratio at all, or on the withdrawal rate, which is a different
test that bites at different times.

Built in a worktree, so the three new builder controls and the two new result notes **have not been
seen in a browser**.
