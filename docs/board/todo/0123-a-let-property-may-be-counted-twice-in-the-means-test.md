# A let property may be counted twice in the Pension Credit means test

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
A let home is assessed twice over, once as an asset and once as what the asset earns.

`PathProjector::meansTestAssessableCapital` adds a let property's equity to assessable capital, so it
generates tariff income (`Property::$isLet`, added 2026-07-01, and correct on its own: a let home is
not the exempt main residence). Separately, the rent is an ordinary taxable income stream, so it
lands in `$taxablePerPerson` and goes straight into the Pension Credit assessable income.

The means-test rules generally treat income derived from an asset that is already assessed as
capital as capital, not as a second stream of income, precisely to stop this. If that reading holds,
the engine is charging the household twice for one property and **under-awarding** Guarantee Credit
on every plan that lets a home, which is one of the two headline strategies this tool compares.

It is written as "may" because the rule needs a citable source before anything is changed. Getting it
backwards would over-award instead, and the whole point of the means-test work is that these figures
survive scrutiny.

## Links

**Relates to**
- `0051` - found it in the expert-panel review and could not settle it without web access.
- `0048` - property capital is already valued net of the costs of selling; this is the same asset,
  assessed on the income side.

## Not this card
The capital treatment of the equity itself, which is settled.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL assess a let property once: either its equity as capital or its rent as income, not both. proves: `test_a_let_property_is_not_assessed_as_capital_and_income_at_once`
- [ ] THE APP SHALL record which treatment it applies, with its source. proves: none
<!-- AC:END -->

## Tasks
- [ ] Settle the rule from a citable source. **Needs web, so not an unattended card.**
- [ ] Apply one treatment in `pensionCreditAward`, excluding the rent from assessable income the way
      a death-in-service lump sum is already excluded (`$excludedFromAssessable`), if capital wins.
- [ ] Bump `ENGINE_VERSION` and re-run every stored scenario: any plan with a let home in a Pension
      Credit year moves.

## Plan
`packages/finance-engine/src/Forecast/PathProjector.php`: `meansTestAssessableCapital` holds the
capital half and `pensionCreditAward` the income half, and the mechanism for taking a receipt out of
assessable income while leaving it taxable already exists there. The existing guard is
`test_a_let_home_counts_as_assessable_capital_and_erodes_pension_credit` in
`packages/finance-engine/tests/Forecast/PathProjectorTest.php`.

## Comments

**2026-10-16** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

The card's first task is to settle the rule from a citable source, and the card itself says it needs the web. In this unattended session WebSearch and WebFetch were both refused (permission not granted). The repository holds no source for the rule either: a grep for the capital-versus-income treatment found only the original note on card 0051, which states the reading without a citation.

I did not write the failing test or change pensionCreditAward. Which way the test should assert (rent excluded from assessable income, or equity excluded from assessable capital) is the very rule the card says must be sourced first, and guessing it risks an over-award, which the card warns against.

Where to look next, as a lead and not a finding: the State Pension Credit Regulations 2002 (SI 2002/1792), regulation 15 and the capital rules near it, on legislation.gov.uk, and the DWP Decision Makers' Guide chapter on Pension Credit capital. Once a session with web access cites the rule, the build is small: add the let property's rent to $excludedFromAssessable in pensionCreditAward if capital wins, bump ENGINE_VERSION, and re-run stored scenarios.

This card should run in an attended session, or with web permission granted.

**2026-10-17** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block. The first task is to settle the rule from a citable source. This session asked for WebSearch and WebFetch, and both were refused (permission not granted). This is the second unattended take to stop at the same place.

I searched the repository again for a source. There is still none for Pension Credit. DECISIONS.md (the care means-test entry) says income from capital is treated as capital, but it cites the care charging regulations. The care rules are a different scheme, so I did not use them for Pension Credit. Card 0051 states the same reading with no citation.

I did not write the failing test. The direction of the assertion (take the rent out of assessable income, or take the equity out of assessable capital) is the rule that must be sourced first. A guess could over-award Guarantee Credit.

Lead, not a finding: State Pension Credit Regulations 2002 (SI 2002/1792), regulations 15 and 17 and Schedule IV, and the DWP Decision Makers' Guide chapter on Pension Credit capital and income from capital. When that is cited, the build is small: put the let rent in $excludedFromAssessable in pensionCreditAward if capital wins, add the test named on criterion 1, bump ENGINE_VERSION, and re-run the stored scenarios.

Do not put this card back in the unattended loop. It needs an attended session, or a run with web permission granted. A further unattended take will stop here again.

**2026-10-17** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-17 manager (m20261017085057-a754)

**outcome: rob**

The rule must come from a citable legal source, and only a session with web access can get it. Unattended takes have no web, so another take will block again.

**what the session said**

The code matches what the card says. `PathProjector::meansTestAssessableCapital` counts the let property's equity as capital. `PathProjector::pensionCreditAward` already has the `$excludedFromAssessable` hook, so the fix will be small.

Neither criterion is ticked. No review finding disproves anything. The only blocker is that the card needs the web. Two unattended takes asked for WebSearch and WebFetch, and both requests were refused. If you move the card back to `todo/`, the next take will stop at the same place.

The pick-up lead is the State Pension Credit Regulations 2002 (SI 2002/1792), regs 15 and 17 and Schedule IV, plus the DWP Decision Makers' Guide chapter on Pension Credit capital.

What you do now (pick one):
1. Run card 0123 yourself in an attended session that has web access. I recommend this one.
2. Give the loop web permission, then move the card back to `todo/`.

WHY: The rule must come from a citable legal source, and only a session with web access can get it. Unattended takes have no web, so another take will block again.

OUTCOME: rob


**2026-10-25** **Research, 2026-10-25 (attended session, web)**
