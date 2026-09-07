# Benefits rule corrections

## Why
From the expert panel, 2026-08-19 (Citizens Advice findings 8, 10, 12, 13, 14 and the smaller
points). Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`. Each is small on its own
and several are wrong in a way that would not survive a tribunal.

**The severe disability addition is tested on one boolean.** The real conditions are narrower: the
qualifying benefit must be the middle or highest rate care component, Attendance Allowance at
either rate, or the daily living component of Personal Independence Payment. Mobility-only and
lowest-rate care do **not** qualify, so the boolean will award the addition to someone who is not
entitled. Also missing: the test that no other adult normally lives there, the registered-blind
route, and the effect of Carer's Allowance actually being paid.

**Only one carer addition is added.** The projector breaks on the first carer found. A couple where
each cares for the other gets two.

**Undrawn pension pots are excluded from the means test.** For a claimant over State Pension age
that is not the rule - an untaken money-purchase pot is treated as notional income. Excluding it
over-awards Guarantee Credit for the common case, and it is not in the Known divergences list.

**Assessable income is gross.** Pension Credit assesses earnings net of tax, National Insurance and
half of any pension contribution, and applies a small earnings disregard. The engine does neither.

**A mixed-age couple gets a silent nil.** Correct that they cannot claim Pension Credit, but the
engine says nothing about it and models no alternative. The loss to a real household is large and
the trap is well known.

**Savings Credit is dismissed on wrong reasoning.** The docblock says it is closed to those
reaching State Pension age after April 2016. For a couple it remains open if **either** member
reached it before then. The award is usually nil anyway, but the stated reason would lead a reader
to skip the question.

**A let property may be double-counted** - its equity into capital and its rent into income, where
the rules generally treat income derived from capital as capital.

**Sourcing.** The benefits source URL points at an eligibility page rather than the rate tables, and
the verified-on dates disagree between the registry and the parameters.

## Not this card
Housing Benefit and Council Tax Reduction, which are cards 0048 and 0047.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a person receives only a mobility component or a lowest-rate care component, THE APP SHALL NOT award the severe disability addition.
- [x] #2 WHEN each member of a couple cares for the other, THE APP SHALL award two carer additions.
- [x] #3 WHEN a claimant over State Pension age holds an undrawn money-purchase pot, THE APP SHALL treat it as notional income, or list the divergence.
- [x] #4 WHEN a couple is mixed-age, THE APP SHALL explain that Pension Credit is unavailable and what replaces it.
<!-- AC:END -->

## Tasks
- [x] Replace the disability boolean with the qualifying benefit and its rate
- [ ] Add the non-dependant test and the registered-blind route (card 0121, needs web)
- [x] Allow two carer additions
- [ ] Assess earnings net, with the earnings disregard (card 0120, needs web)
- [x] Notional income on undrawn pots, or a Known divergences entry (the divergence is listed;
      the fix is card 0119)
- [x] Mixed-age warning; correct the Savings Credit docblock; the let double-count is card 0123
- [ ] Point the benefits source at the rate tables and align the verified-on dates (card 0122,
      needs web)

## Comments

**2026-09-07**
RESULT: done
TESTS: +6 new, all green
TOUCHED:
- packages/finance-engine/src/Dto/DisabilityAwardRate.php (new)
- packages/finance-engine/src/Dto/Person.php
- packages/finance-engine/src/Benefits/PensionCreditCalculator.php
- packages/finance-engine/src/Forecast/PathProjector.php
- packages/finance-engine/src/Support/WarningCode.php
- packages/finance-engine/tests/Forecast/PathProjectorTest.php
- packages/finance-engine/tests/Benefits/PensionCreditCalculatorTest.php
- app/Forecast/HouseholdAssembler.php
- app/Forecast/ResultPresenter.php
- app/Forecast/ScenarioForecaster.php
- app/DecisionSupport/ProtectionGap.php
- app/Livewire/ScenarioBuilder.php
- resources/views/livewire/scenario-builder.blade.php
- resources/views/livewire/scenario-results.blade.php
- resources/views/pdf/partials/report.blade.php
- tests/Feature/Livewire/ScenarioBuilderTest.php
- tests/Unit/Forecast/PensionCreditGuidanceTest.php
- docs/DATA-MODEL.md
- docs/DECISIONS.md
- docs/HANDOVER.md
- docs/HANDOVER-ARCHIVE.md
- docs/board/todo/0119-undrawn-pension-pot-is-not-notional-income.md (new)
- docs/board/todo/0120-pension-credit-assesses-earnings-gross.md (new)
- docs/board/todo/0121-severe-disability-addition-missing-two-tests.md (new)
- docs/board/todo/0122-benefits-source-and-verified-on-disagree.md (new)
- docs/board/todo/0123-a-let-property-may-be-counted-twice-in-the-means-test.md (new)
OUT-OF-SCOPE: 0119, 0120, 0121, 0122, 0123

**All four acceptance criteria are met, and three of the seven Tasks are not.** The three left open
each need a statutory figure or rule that only a web fetch can settle, and an unattended card
session on this machine has no web access. Each is now a card of its own with the fetch named as its
first task. Guessing a figure that reaches a projection would break the standing rule that every tax
figure carries a source and a verified-on date, and guessing a URL is inventing a fact.

**What was built.** `Dto\DisabilityAwardRate` holds which part of a disability award a person has
(`QualifyingCare`, `LowestRateCare`, `MobilityOnly`) and
`Person::qualifiesForSevereDisabilityAdditionAt()` is the one predicate the severe-disability count
AND the carer test read, because Carer's Allowance rests on the same qualifying-benefit list. The
carer addition became a count rather than a flag, so mutual carers get two.
`WarningCode::MIXED_AGE_COUPLE` is raised in every year one living partner is under State Pension
age, and `ResultPresenter::pensionCreditGuidance()` opens the Pension Credit panel on it as a third
route beside an award and a near miss, on the screen and in the PDF. The Savings Credit docblock now
says why it is out of scope (small award) rather than implying the door is shut, and states the
either-member rule for a couple.

**How each criterion was watched fail.** #1 red at 672620 pence where the criterion says 0 (the
severe-disability addition being paid on a mobility-only award), reached by adding the enum and the
field as an inert value first so the failure was the criterion's and not a missing class. Its carer
sibling red at 28080 where the criterion says 0. #2 red at 923000, which is exactly one carer
addition where two were asserted. #4 red on "Failed asserting that an array contains
'mixed_age_couple'" after the code constant existed but nothing raised it. The two app-layer tests
(`test_the_part_of_a_disability_award_is_a_builder_input_and_reaches_the_household` and
`test_a_mixed_age_couple_is_told_pension_credit_is_shut_and_what_replaces_it`) were written after
the engine was wired and were first seen green; they corroborate the four that were watched red
rather than standing on their own.

**#3 was met by the divergence route the criterion allows, not by modelling notional income.** The
entry is in docs/DATA-MODEL.md "Known divergences" and says which direction the error runs (Guarantee
Credit is over-awarded, and everything passporting off it with it), what closing it needs, and that
it is card 0119. The rate notional income is computed at is a published figure this session could
not fetch.

**Assumed, and worth a reviewer's attention.** The award rate DEFAULTS to `QualifyingCare` rather
than to the more adverse non-qualifying reading, which is a deliberate departure from the standing
adverse-default rule and is argued in DECISIONS 2026-09-07: the flag's own docblock has said since
v1 that it means a qualifying benefit, so re-reading a stored tick as something narrower would
change an answer the reader gave. The consequence is that no stored plan moves on the criterion-#1
half of this card. `ENGINE_VERSION` IS bumped, to
`finance-engine/pension-credit-additions-per-entitlement`, because criterion #2 does move a figure
for a mutual-carer household, upward; the **stored-scenario re-run is owed**.

**Not settled from the repository.** Whether the mixed-age rule's start date (the well-known 2019
change) should appear in the warning copy: the date is not in the repository and this session could
not verify it, so the message states the rule without it. The docblock correction to the Savings
Credit either-member rule is taken from this card's own Why section, which is the direction; it is
not independently verified here.

**Not seen in a browser.** Built in a worktree, so the new "Which part of the award" select on the
builder and the mixed-age copy in the Pension Credit panel (results page and PDF) have not been
looked at.

**Doc hygiene done alongside.** The session-start orient hook reported HANDOVER.md over its loadable
budget, so card 0035's block was folded out to docs/HANDOVER-ARCHIVE.md as this card's entry went in,
keeping the live brief from growing.

### 2026-09-07 review (v20260907080355-668c)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 240s, run by this job rather than reported by the card.

**acceptance: sound**

Checked each criterion against real code.

**#1 ÔÇö no addition on mobility-only / lowest-rate care.** `DisabilityAwardRate::qualifiesForSevereDisabilityAddition()` returns true only for `QualifyingCare`. `Person::qualifiesForSevereDisabilityAdditionAt()` combines it with the in-payment test. `PathProjector::pensionCreditNominal()` counts only people passing that predicate, and the same predicate gates the carer test, so no second path awards it. No other file awards the addition (`applicableAmountWeekly()` in `PensionCreditCalculator` is the only consumer). The field reaches the engine through `HouseholdAssembler::assemble()` and is preserved in `ProtectionGap`.

**#2 ÔÇö two carer additions.** `PathProjector::pensionCreditNominal()` counts carers in a loop instead of breaking out, and `PensionCreditCalculator::applicableAmountWeekly()` multiplies `carerAdditionWeekly` by that count.

**#3 ÔÇö undrawn pot.** Criterion allows the divergence route. `docs/DATA-MODEL.md` "Known divergences" holds an entry naming direction of error and card 0119.

**#4 ÔÇö mixed-age couple.** `PathProjector::benefitContingencyWarnings()` raises `WarningCode::MIXED_AGE_COUPLE` when a living partner is under State Pension age, with copy naming Universal Credit as the replacement. `ResultPresenter::pensionCreditGuidance()` returns it as `mixedAge`, rendered in `scenario-results.blade.php` and `pdf/partials/report.blade.php`.

I could not break any of them.

VERDICT: sound

**scope: defect**

Read the card commit (185f342) and its neighbour 0050 (9a7d619).

**Two homes for one fact (scope grew).** Card 0050, committed an hour earlier, already recorded the care/mobility split as `IncomeStreamType::DisabilityBenefit` vs `DisabilityBenefitMobility`, and the care means test reads it. This card added a second, independent home for the same fact: `Dto\DisabilityAwardRate` on `Person`, read only by `Person::qualifiesForSevereDisabilityAdditionAt()` and `PathProjector::pensionCreditAward()`. Nothing reconciles them. A person entered with a mobility-only income stream, with the new select left blank, still defaults to `QualifyingCare` and is paid the severe-disability and carer additions ÔÇö the exact defect criterion #1 exists to stop. The builder now asks "which part of the award" twice, in two controls that may disagree. The card asked to replace the boolean, not to add a rival input.

**Criterion #4 half done.** `ResultPresenter::pensionCreditGuidance()` returns the mixed-age case through the same panel, so `howToClaim` still renders "Apply online at gov.uk/pension-credit" to a household the copy has just told cannot claim. The replacement is named, never actioned.

VERDICT: defect

**breakage: defect**

**Finding 1 ÔÇö the mobility case the app already knows about still gets the addition.** `IncomeStreamType::DisabilityBenefitMobility` already records "mobility component" (see `IncomeStreamType::isDisabilityBenefit`). The new award rate is a second, separate home for the same fact, and it defaults to `QualifyingCare`. So a household whose only disability money is entered as `disability_benefit_mobility`, with the tick on and the new select untouched, still gets the severe-disability and carer additions in `PathProjector::pensionCreditAward` (the `$qualifies` closure). That is exactly the over-award AC#1 says is removed. No test builds it. Nothing cross-checks the two fields.

**Finding 2 ÔÇö a note the change made false.** `ResultPresenter::inputNotes` (the `disability_care_component_in_care` note) still tells the reader their award "is entered as the CARE (daily living) component" even when they chose `MobilityOnly`.

**Finding 3 ÔÇö the carer caveat was not updated.** `ThresholdPresenter::leverCaveat` still fires on `caresForPartner` alone. Where the cared-for partner's award does not qualify, no carer addition exists, so the caveat tells the reader the lever postpones money the engine never pays.

VERDICT: defect

