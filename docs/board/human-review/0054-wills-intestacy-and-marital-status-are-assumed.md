# The model assumes a marriage and a will that nobody was asked about

## Why
From the expert panel, 2026-08-19 (estate planner findings 2 and 3). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

**Marital status defaults to married.** It is the default in the DTO, in the builder and in the
assembler fallback, and it is not disclosed as an assumed figure. It is the one input where the
wrong value is catastrophic: no spouse exemption, no transferable nil-rate band, no transferable
residence band, no State Pension inheritance, and most defined-benefit schemes pay a survivor's
pension only to a spouse or civil partner. The engine handles cohabitation correctly once told. The
defect is that it is never asked, and the default flatters the result - the opposite of the
standing "adverse default, user-editable" rule.

**A will is assumed too.** The first death is granted full spouse exemption and the home is assumed
to pass to descendants. There is no "is there a will?" input, no intestacy path, and no mention of
wills, intestacy or probate anywhere in the codebase. Under intestacy a spouse does **not** take
everything: they take the chattels, a statutory legacy and half the residue, and the children take
the rest.

**Spouse exemption is unlimited.** There is no domicile or long-term-residence input, and the
exemption is capped where the recipient spouse is not UK long-term resident unless an election is
made.

The date of marriage matters too, because it drives the State Pension inheritance rules.

## Not this card
The relationship-status mechanics, which the panel found correct and well tested.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL require marital status to be chosen, with no default, and disclose it as an assumed figure if one is ever supplied. proves: `test_a_couple_must_choose_a_relationship_status`, `test_a_relationship_status_nobody_gave_is_disclosed_as_an_assumed_figure`
- [x] #2 THE APP SHALL ask whether each person has a current will, defaulting to no will. proves: `test_a_will_is_not_assumed_when_nobody_answered`, `test_a_will_is_never_assumed_and_costs_the_estate_when_there_is_none`
- [x] #3 WHEN no will exists, THE APP SHALL apply the intestacy rules rather than granting full spouse exemption. proves: `test_no_will_splits_the_estate_under_the_intestacy_rules`, `test_a_married_first_death_with_no_will_is_not_fully_spouse_exempt`
- [x] #4 THE APP SHALL capture the date of marriage or civil partnership, and the residence position of the recipient spouse. proves: `test_a_will_the_marriage_date_and_the_residence_position_are_builder_inputs`
<!-- AC:END -->

## Tasks
- [x] Remove the marital-status default; add it to `assumedFigures()`
- [x] Add a will input and an intestacy path in `InheritanceTaxCalculator`
- [ ] Source the statutory legacy figure, with `source` and `verified_on` (no web in an unattended session; carded as 0127)
- [x] Add the marriage date and a long-term-residence flag

## Comments

**2026-09-07**
RESULT: done
TESTS: +9 new, all green
TOUCHED:
- packages/finance-engine/src/Dto/Household.php
- packages/finance-engine/src/Dto/Person.php
- packages/finance-engine/src/Iht/InheritanceTaxCalculator.php
- packages/finance-engine/src/Forecast/PathProjector.php
- packages/finance-engine/src/Support/WarningCode.php
- packages/finance-engine/tests/Iht/IntestacyTest.php (new)
- packages/finance-engine/tests/Forecast/InheritanceTaxForecastTest.php
- app/Forecast/HouseholdAssembler.php
- app/Forecast/ResultPresenter.php
- app/Forecast/ScenarioForecaster.php
- app/Forecast/WhatIfChanges.php
- app/DecisionSupport/ProtectionGap.php
- app/Livewire/ScenarioBuilder.php
- resources/views/livewire/scenario-builder.blade.php
- tests/Support/BuilderStateFixture.php
- tests/Support/HouseholdFixture.php
- tests/Unit/Forecast/HouseholdAssemblerTest.php
- tests/Unit/Forecast/InputNotesTest.php
- tests/Feature/Livewire/ScenarioBuilderTest.php
- docs/DECISIONS.md
- docs/spec/ASSUMPTIONS.md
- docs/board/todo/0127-intestacy-and-spouse-exemption-figures-are-stated-not-verified.md (new)
- docs/board/todo/0128-the-marriage-date-is-captured-but-nothing-reads-it.md (new)
- docs/HANDOVER.md, docs/HANDOVER-ARCHIVE.md
OUT-OF-SCOPE: 0127, 0128

**What was built.** `Household::$relationshipStatus` is nullable with no default and is read through
`relationshipStatus()`; `relationshipStatusIsAssumed()` gates a new `assumedFigures()` note that
says, in its own words, that the fallback is the flattering way round. The builder select opens on
"Please choose" and validation requires it for a two-person household only. `Person::$hasWill`
(default false) and `Person::$ukLongTermResident` (default null, "not asked") are new, with a
checkbox and a select each. `InheritanceTaxCalculator::computeFirstDeath()` is the one home of what
the survivor takes: the whole estate with a will, the statutory legacy plus half the residue under
intestacy, and either capped at the nil-rate band where the survivor is not a UK long-term resident.
`compute()` takes `$nilRateBandUsedAtFirstDeath` so only the UNUSED band transfers, which is what
makes intestacy bite on an ordinary estate rather than being a no-op. `Household::$marriageDate` is
captured, stored sparsely, and read by nothing.

**What was assumed, and it moves every stored plan.** No will is the default, so every stored
scenario that models Inheritance Tax now pays tax on the first death and carries a smaller band into
the second, until a reader ticks the will boxes. `ENGINE_VERSION` is
`finance-engine/intestacy-on-the-first-death` and the **stored-scenario re-run is owed**.

The engine holds no list of children, so `homeToDescendants` is what says there are issue to take a
share under intestacy: a plan leaving nothing to descendants has a spouse who takes the whole
intestate estate, which is the statutory answer where there is no issue. Four further v1
simplifications are in `ASSUMPTIONS.md` §27 and flagged in code: chattels are not modelled, the
legacy is frozen rather than uprated, Northern Ireland's own figure differs from the England and
Wales one the engine uses for both, and the residence nil-rate band is not claimed at the first
death even where the children take part of the home. All four err in the cautious direction.

**What could not be settled here.** Every figure and rule is **STATED, not verified**: this session
had no web, so the £322,000 statutory legacy, its 26 July 2023 start date, the split itself, the
Northern Ireland figure and the s.18(2) cap all carry no `verified_on` date. That is card **0127**,
which is the card's own third Task and the one thing left open. The marriage date is captured but
nothing reads it, because which State Pension a survivor inherits needs two sourced sets of figures
an unattended session cannot fetch: card **0128**.

Built in a worktree, so the new relationship select, the marriage-date input, the will checkbox, the
residence select and the two new results notes **have not been seen in a browser**.

### 2026-09-07 review (v20260907142032-a2c8)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 234s, run by this job rather than reported by the card.

**acceptance: sound**

I traced each criterion to real code.

**AC1 ÔÇö status must be chosen, disclosed if assumed.** `ScenarioBuilder::rules()` makes `relationshipStatus` `requiredIf` two people, and the blank option is the first in `scenario-builder.blade.php`. `Household::$relationshipStatus` is nullable with no default; `Household::relationshipStatusIsAssumed()` gates the disclosure written in `ResultPresenter::assumedFigures()`, which reads the status back out of the household rather than restating it.

**AC2 ÔÇö a will per person, default no.** `Person::$hasWill` defaults false, a checkbox binds it in the builder view, and `ScenarioBuilder::loadState()` backfills `hasWill`/`ukLongTermResident` for older scenarios.

**AC3 ÔÇö intestacy instead of full exemption.** `InheritanceTaxCalculator::computeFirstDeath()` builds `$passingToSpouse` from `intestacySpouseShare()` when there is no will, caps it at the nil-rate band for a non-resident survivor, and returns `nilRateBandUsed`. `PathProjector::recordFirstDeathIht()` passes `$deceased->hasWill` and stores that used band; `recordFinalDeathIht()` subtracts it, and `compute()` deducts it from the doubled band.

**AC4 ÔÇö marriage date and residence.** Both are builder inputs and round-trip: `HouseholdAssembler` maps `marriageDate` and `ukLongTermResident`, and `loadState()` restores them.

I could not break any of the four.

VERDICT: sound

**scope: defect**

**Scope check on card 0054.**

**Grew past what the card asked.** `PathProjector::recordFirstDeathIht()` passes `issueTakeUnderIntestacy: $settings->homeToDescendants`. That takes an existing user control, "Leave home to descendants", and quietly gives it a second meaning: "there are children". The card asked for a will input, not a new meaning for an old input. It also opens a back door around acceptance #3: a couple with no will who untick that box get the whole estate spouse-exempt again, and `InheritanceTaxCalculator::computeFirstDeath()` then raises no `IHT_INTESTACY` warning, so nothing on the page says a will was still assumed away. The box is not labelled as a children question.

**Left half done.**
- `InheritanceTaxCalculator::STATUTORY_LEGACY_PENCE` ships with `verified_on: NOT VERIFIED`. It moves real money. Card task 3 is unticked (carded 0127).
- `Household::$marriageDate` is captured and read by nothing (carded 0128).
- `ResultPresenter::assumedFigures()` discloses the relationship status and the residence position, but not the no-will default.

The fenced-off relationship-status mechanics were not disturbed.

VERDICT: defect

**breakage: defect**

Two things break.

**1. Contradictory, false intestacy warning on a small estate.** In `InheritanceTaxCalculator::computeFirstDeath`, the `IHT_INTESTACY` warning is gated only on `$intestate`, not on the children actually taking anything. `intestacySpouseShare` gives the spouse the whole estate when it is at or below the statutory legacy, so a ┬ú300,000 intestate estate emits BOTH the spouse-exemption warning ("no Inheritance Tax is due") and the intestacy warning, which tells the reader "the children take the other half ÔÇö ┬ú0.00 hereÔÇª that half is taxed and it uses up part of the allowance". Nothing is taxed and no band is spent. `IntestacyTest::test_an_intestate_estate_below_the_statutory_legacy_still_passes_wholly_to_the_spouse` builds exactly this estate but asserts only tax and band, never the warnings, so the suite cannot see it. The sibling test `test_no_children_means_the_spouse_takes_the_whole_intestate_estate` does assert the warning is absent, so the rule is asserted in one place and not the other.

**2. A caller drops the new input.** `HousingComparison::withHousing` rebuilds `new Household(...)` field by field and does not pass `marriageDate`, so every buy/rent/stay-put variant silently loses it. Harmless today only because nothing reads it (card 0128).

VERDICT: defect


**2026-09-07** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 4 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 4 of 4 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
