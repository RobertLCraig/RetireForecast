# The capital-cliff note tells a working-age household about pension-age benefits

## Why
Card 0046 made `PathProjector::benefitContingencyWarnings()` collect the capital-cliff warning that
`CapitalAssessment::assess()` builds. It runs in every year, at every age. So an ordinary working
household with more than £16,000 in cash is told, from its first projected year, that "Housing
Benefit and Council Tax Support are not payable".

That sentence is about the wrong benefits for that household. Before State Pension age, new claims
for help with rent go to Universal Credit, not Housing Benefit, and the Pension Credit that the
rest of this warning's logic turns on cannot be paid at all. The note is a disclosure about
pension-age means-tested help, and it reaches people who are not of pension age.

The tell is in 0046's own diff: `InputNotesTest::test_a_sensible_household_raises_no_notes` had its
spending raised to £38,000 so that the note stopped firing on a working household with savings.

## Links

**Relates to**
- `0046` - collected the warning and raised this as its reviewer's scope finding.
- `0048` - awarding Housing Benefit, whose own age gate this card should agree with.

## Not this card
Modelling Universal Credit. Changing the £16,000 limit or the Guarantee Credit carve-out.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHILE nobody alive in the household has reached State Pension age, THE APP SHALL NOT warn that capital ends Housing Benefit and Council Tax Support. proves: `test_a_working_age_household_with_savings_is_not_warned_about_the_capital_cliff`
- [x] #2 WHEN assessable capital is over the limit in a year someone has reached State Pension age, THE APP SHALL still warn on that year. proves: `test_the_capital_cliff_is_warned_on_the_year_capital_crosses_the_limit`
<!-- AC:END -->

## Tasks
- [ ] Gate the cliff warning in `benefitContingencyWarnings()` on someone alive being over State Pension age (the `$overPensionAge` count it already makes)
- [ ] Decide whether `test_a_sensible_household_raises_no_notes` goes back to its old spending

## Comments
**2026-10-07** Raised from card 0046's rework. Its reviewer found this as "grew"; the manager pass
asked for it to be judged. It is a real fault, but it is about who the warning reaches, not about
how Pension Credit is reported, which is all 0046 #1 covers.

**2026-10-23** RESULT: done
TESTS: +1 new (and 1 extended), all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/PensionCreditContingencyTest.php
docs/spec/METHODOLOGY.md
OUT-OF-SCOPE: none

`benefitContingencyWarnings()` now counts ages first and collects the cliff warning only when `$overPensionAge > 0`, as the task asked. The docblock that said "every year" and the METHODOLOGY cliff bullet now say the opposite.
#1: the new test (a single person born 1980 with £200,000) was watched failing on 2026 before the gate. It also checks that the warning appears in 2048, which is SPA 68 for that birth year.
#2: the existing test was already green, because the old code warned in every year. I added a mixed-age couple case to it, and watched it fail against a wrong gate (everyone over SPA). So it now guards the edge the gate opens.
The task asked whether `test_a_sensible_household_raises_no_notes` should go back to its old spending. I left it at £38,000. Jo (born 1963) is over SPA from 2030, so the old banked savings would still fire the cliff note then. That note would be correct, so the old figure is still the wrong fixture.
Not settled from the repo: card 0048 awards Housing Benefit only when EVERY member is over SPA, but this gate warns a mixed-age couple. The card's own task chose `overPensionAge > 0`. Whether pension-age CTR still applies to a mixed-age couple is a rules question I could not research here (no web).
No ENGINE_VERSION bump: only a warning changed, no figure moved. A stored result may still show the old note until it is re-run. Not seen in a browser.
