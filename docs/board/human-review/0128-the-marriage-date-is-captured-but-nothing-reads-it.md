# The marriage date is captured and nothing reads it

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
Card 0054 added the date of the marriage or civil partnership to the builder, because it decides
which State Pension inheritance rules a survivor falls under: the rules turn on whether the couple
were married before 6 April 2016, and the old and new schemes give a survivor materially different
amounts. That card's criterion was to CAPTURE it, and it does.

Nothing consumes it. The forecast still models no State Pension inheritance for anybody: each
person's State Pension stops on their death, which is stated in the cohabiting-couple note but is
just as wrong for a married one. For a couple where one has a large protected payment or a
pre-2016 additional State Pension, the survivor's secure income is understated for the whole of
their remaining life, and the survivor's years are exactly where a plan runs short.

The field, the builder input and the disclosure exist, so the work is the rule and its sourcing.

## Links

**Relates to**
- `0054` - captured the date this card would read.

## Not this card
The cohabiting case. A cohabiting partner cannot inherit State Pension at all, and the results page
already says so.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a married person dies, THE APP SHALL pay the survivor whatever State Pension they can inherit under the scheme the marriage date puts them in. proves: `test_a_survivor_inherits_state_pension_under_the_pre_2016_rules`
- [ ] THE APP SHALL state on the results page which scheme it applied and why. proves: `test_the_state_pension_inheritance_note_names_the_scheme`
<!-- AC:END -->

## Tasks
- [ ] **Needs web to source the two inheritance schemes, so probably not an unattended card.**
- [ ] Read `Household::$marriageDate` in the projector's survivor settlement.
- [ ] Every figure with a `source` URL and a `verified_on` date.

## Comments

**2026-10-18** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Blocked on sourcing, as the card's own first Task predicted. WebFetch of the GOV.UK page (https://www.gov.uk/new-state-pension/inheriting-or-increasing-state-pension-from-a-spouse-or-civil-partner) was refused in this unattended session, so neither inheritance scheme can be given a source URL and a verified_on date. The repository holds no sourced figures for either scheme: docs/ASSUMPTIONS, docs/research and DECISIONS have nothing on State Pension inheritance beyond DECISIONS 2026-09-07 Decision 4, which already says the same thing.

A second gap, found while reading the engine: there is no input for the part of a State Pension a survivor can inherit. packages/finance-engine/src has no protected payment, additional State Pension (SERPS / S2P) or pre-2016 basic-pension field anywhere. Each person carries one State Pension amount. So even with sourced rules, the projector has nothing to apply an inheritance percentage to. Building criterion 1 means adding that input as well (DTO field, builder field, assembler mapping, loadState backfill, BuilderStateFixture::full), and the card's Why says 'the field, the builder input and the disclosure exist', which is true only of the marriage date.

What the next take needs: a session with web (an interactive one, or this card marked not_for_the_loop) to source the inherited protected payment share, the inherited additional State Pension shares by date of death, and the marriage-before-6-April-2016 and survivor-SPA conditions. Rob may also want to decide whether the new per-person 'inheritable State Pension' input belongs on this card or a card of its own before it is built. I did not guess any figure.

**2026-10-18** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block as the first. WebFetch of https://www.gov.uk/new-state-pension/inheriting-or-increasing-state-pension-from-a-spouse-or-civil-partner was refused again in this unattended session, so neither inheritance scheme can carry a source URL and verified_on date. I did not guess any figure.

Re-checked the engine: packages/finance-engine/src still has no protected payment, additional State Pension (SERPS/S2P) or other inheritable-amount input; each person has one State Pension amount. So criterion 1 also needs a new per-person input (DTO, builder field, assembler, loadState backfill, BuilderStateFixture::full), which the card's Why does not account for.

This card cannot advance in the loop. It needs either (a) not_for_the_loop: in its frontmatter and an interactive session with web to source the rules, or (b) Rob to split the new inheritable-State-Pension input into its own card first. Recommend (a), with the input built on this card since criterion 1 cannot be met without it.

**2026-10-18** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.
