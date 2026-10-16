# The severe-disability addition still skips the non-dependant test and the registered-blind route

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
Card 0051 fixed the qualifying-benefit half of the severe-disability addition: the award now has to
be the care side (`Dto\DisabilityAwardRate`), so a mobility-only or lowest-rate-care award no longer
buys an addition nobody is entitled to. Two conditions named in the same expert-panel finding were
left open, both because settling them needs a source this project does not yet hold.

**The non-dependant test.** The addition requires that no other adult "normally resides" with the
claimant, apart from ones the rules excuse. The engine tests the PARTNER only, so a household with
an adult child living at home is awarded an addition it would not get. The model has no concept of a
non-dependant adult at all, so this is a data-shape question first: something has to hold the fact.

**The registered-blind route.** The panel listed it as a missing route into the addition. The
unattended card that would have built it had no web access and could not check whether registration
as severely sight impaired is in fact a route for the pension-age severe-disability addition, or
only for the working-age disability premium it is more often quoted against. Getting that wrong in
either direction moves a real award, so it was left rather than guessed.

## Links

**Relates to**
- `0051` - fixed the qualifying-rate half and left these two.
- `0044` - added the disability start age the addition already reads.

## Not this card
The `paid`-Carer's-Allowance-removes-the-addition interaction, which the engine models as underlying
entitlement on purpose (DECISIONS 2026-07-29).

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN another non-excused adult normally lives in the household, THE APP SHALL award no severe-disability addition. proves: `test_a_non_dependant_adult_blocks_the_severe_disability_addition`
- [ ] THE APP SHALL settle, from a cited source, whether registration as severely sight impaired is a route into the pension-age addition, and either model it or record why not. proves: none
<!-- AC:END -->

## Tasks
- [ ] Check the registered-blind rule against a citable source. **Needs web, so not an unattended
      card.**
- [ ] Decide where a non-dependant adult lives in the data shape. A count on the household is the
      smallest thing that answers the question; anything richer needs a reason.
- [ ] Wire the test into `PathProjector::pensionCreditAward` beside the qualifying-rate gate.
- [ ] Say in the results note what is and is not tested, as the disability note already does.

## Plan
`packages/finance-engine/src/Forecast/PathProjector.php` (`pensionCreditAward`),
`packages/finance-engine/src/Dto/Household.php` and the builder if a new input is needed. A new
builder field moves four things together: the blank default, the validation rule, the load-state
backfill and `Tests\Support\BuilderStateFixture`. Default it to "nobody else lives here", which is
what every stored scenario means today, so nothing stored moves.

## Comments

**2026-10-15** RESULT: partial
TESTS: +1 new, all green
TOUCHED: packages/finance-engine/src/Dto/Household.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Housing/HousingComparison.php
packages/finance-engine/tests/Forecast/PathProjectorTest.php
app/Forecast/HouseholdAssembler.php
app/Forecast/ResultPresenter.php
app/Forecast/WhatIfChanges.php
app/Livewire/ScenarioBuilder.php
resources/views/livewire/scenario-builder.blade.php
docs/HANDOVER.md
docs/board/todo/0204-the-non-dependant-adults-the-severe-disability-rules-excuse-are-not-sourced.md
OUT-OF-SCOPE: 0204

Criterion 1: Household::$nonDependantAdults, an int count defaulting to 0, gates the severe-disability addition in PathProjector::pensionCreditAward beside the qualifying-rate test. The carer addition is untouched. The builder field (step 2, Pension Credit fieldset) is stored only when above 0, so no stored scenario moves, no ENGINE_VERSION bump and no re-run is owed. The loadState backfill is the generic property_exists loop, and BuilderStateFixture needs no change because the key is sparse, as with pensionCreditClaimed (card 0110). test_a_non_dependant_adult_blocks_the_severe_disability_addition was watched failing first: 'Failed asserting that 672620 is identical to 0', which means the addition was still paid with an adult child at home. The disability_benefit_passports note now says whether other adults were entered and that the registered-blind route is not modelled.

Criterion 2 is NOT met. WebFetch was refused in this session, so I could not read the State Pension Credit Regulations 2002, Schedule I. It still needs a session with web.

Not settled from the repository: which adults the rules excuse from the count. The help text does not list them. It tells the reader to check with a benefits adviser. Card 0204 carries this.

The new builder field and the note text still need a browser check. Herd serves the main tree, not this worktree.

**2026-10-16** RESULT: partial
TESTS: +0 new, all green
TOUCHED: none
OUT-OF-SCOPE: none

Criterion 1 still holds from the previous take: test_a_non_dependant_adult_blocks_the_severe_disability_addition passes on this branch. No code changed this take.

Criterion 2 is NOT met, and this take could not advance it. WebFetch and WebSearch were both refused again, so I could not read the State Pension Credit Regulations 2002, Schedule I (legislation.gov.uk/uksi/2002/1792/schedule/I). The repository holds no primary source for the rule. The only citations it has (DECISIONS 2026-07-29: Turn2us and entitledto, verified 2026-07-29) describe the severe disability premium, which is the working-age premium. They say a couple qualifies when the other partner is registered blind. That is exactly the working-age reading this card doubts, so I did not use it to settle the pension-age rule. This criterion needs a session with web access. Running it unattended again will stop at the same point.

The step-2 field from the previous take still needs a browser check. Herd serves the main tree, not this worktree.

**2026-10-16** RESULT: partial
TESTS: +0 new, all green
TOUCHED: none
OUT-OF-SCOPE: none

Criterion 1 still holds: test_a_non_dependant_adult_blocks_the_severe_disability_addition passes on this branch. No code changed this take.

Criterion 2 is NOT met. WebFetch was refused again in this unattended session, so the State Pension Credit Regulations 2002, Schedule I (legislation.gov.uk/uksi/2002/1792/schedule/I) could not be read. This is the third take to stop at the same point. The card's own task says it needs web and is not an unattended card. Please run criterion 2 in an attended session with web access, or take it off the loop.

The step-2 field from the first take still needs a browser check. Herd serves the main tree, not this worktree.

**2026-10-16** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 1 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-16 manager (m20261016113825-4070)

**outcome: rob**

The last open criterion needs web access that the loop does not have, and the new field still needs a check in a real browser.

**what the session said**

Criterion 1 is done in the code. `Household::$nonDependantAdults` holds the count. `PathProjector::pensionCreditAward` stops the addition when that count is above 0. `ScenarioBuilder` stores the field, and the note in `ResultPresenter` tells you about it.

Criterion 2 is still open. It needs one source: the State Pension Credit Regulations 2002, Schedule I. The loop cannot get to the web, so three takes stopped at the same point. If I reopen the card, it comes back again for the same reason.

You must do two things:
1. Run criterion 2 in a session you start yourself, with web access. That session reads Schedule I, cites it, and then models the registered-blind route or records why not.
2. Look at the new "other adults living in the home" field on step 2 in a browser. Check that it shows correctly. No take could do this, because Herd serves the main tree and not the worktree.

WHY: The last open criterion needs web access that the loop does not have, and the new field still needs a check in a real browser.

OUTCOME: rob

