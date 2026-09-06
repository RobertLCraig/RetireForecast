# A disability benefit claimed later in life cannot be modelled at all

## Why
From the expert panel, 2026-08-19 (Citizens Advice finding 2, adviser finding 10). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

`Person::$receivesDisabilityBenefit` is a static boolean with no start age. So you cannot model the
single most likely favourable event in a long survivor period: a person claiming Attendance
Allowance once their own health declines.

That event is large. Attendance Allowance is tax-free and disregarded from the means test, and it
opens the severe disability addition inside Pension Credit, which in turn passports Support for
Mortgage Interest, Council Tax Reduction, the Warm Home Discount, a free TV licence, Cold Weather
Payments and help with NHS costs. The caseworker's arithmetic makes the package worth more per year
than the survivor shortfall the tool is trying to close.

`Person::caresForPartner` exists in the engine but is not a builder input, so the carer addition
wired in July 2026 is dead code as far as the app is concerned.

There is also an interaction the sweep should show: underlying entitlement to Carer's Allowance has
an earnings limit, so a lever that says "work longer" also postpones the carer addition.

## Not this card
Support for Mortgage Interest itself, which is card 0045.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL let a disability benefit start at a chosen age rather than being on or off for life. proves: `test_a_disability_benefit_can_start_at_a_chosen_age`, `test_a_disability_benefit_start_age_is_a_builder_input_and_reaches_the_household`
- [x] #2 THE APP SHALL offer claiming Attendance Allowance later in life as a what-if, showing the benefit and everything it passports. proves: `test_the_attendance_allowance_preset_claims_it_later_in_life_with_its_own_money`, `test_a_disability_benefit_note_names_the_start_age_and_everything_it_passports`
- [x] #3 THE APP SHALL expose whether a person cares for their partner as a builder input. proves: `test_caring_for_a_partner_is_a_builder_input_and_reaches_the_household`
- [x] #4 WHEN a lever extends working life, THE APP SHALL flag that earnings above the carer earnings limit block underlying entitlement to Carer's Allowance. proves: `test_the_working_longer_lever_shows_the_carer_earnings_limit_on_the_page`
<!-- AC:END -->

## Tasks
- [x] Give the disability flag a start age, or replace it with a dated award
- [x] Add an Attendance Allowance what-if lever using the sourced rate
- [x] Expose `caresForPartner` in the builder and the assembler
- [x] Warn on the carer earnings-limit interaction in the working-longer lever

## Comments

**2026-09-06**
RESULT: done
TESTS: +10 new, all green (1354 tests, the one long-standing skip unchanged)
TOUCHED:
  packages/finance-engine/src/Dto/Person.php
  packages/finance-engine/src/Forecast/PathProjector.php
  packages/finance-engine/src/TaxYear/BenefitsParameters.php
  packages/finance-engine/src/TaxYear/TaxYearRegistry.php
  packages/finance-engine/tests/Forecast/PathProjectorTest.php
  app/Forecast/HouseholdAssembler.php
  app/Forecast/QuickWhatIf.php
  app/Forecast/ResultPresenter.php
  app/Livewire/ScenarioBuilder.php
  app/Livewire/ThresholdExplorer.php
  app/DecisionSupport/ThresholdPresenter.php
  app/DecisionSupport/ProtectionGap.php
  app/Filament/Pages/TaxYearAudit.php
  resources/views/livewire/scenario-builder.blade.php
  resources/views/livewire/threshold-explorer.blade.php
  tests/Feature/Livewire/ScenarioBuilderTest.php
  tests/Feature/Forecast/QuickWhatIfTest.php
  tests/Feature/DecisionSupport/ThresholdPresenterTest.php
  tests/Feature/DecisionSupport/ThresholdExplorerTest.php
  tests/Unit/Forecast/InputNotesTest.php
  docs/DECISIONS.md
  docs/DATA-MODEL.md
  docs/spec/ASSUMPTIONS.md
  docs/HANDOVER.md
  docs/HANDOVER-ARCHIVE.md
  docs/board/todo/0106-pin-the-attendance-allowance-and-carer-earnings-figures.md
  docs/board/todo/0107-person-is-rebuilt-by-hand-with-no-guard.md
  docs/board/todo/0108-the-carer-addition-ignores-the-earnings-limit.md
OUT-OF-SCOPE: 0106, 0107, 0108

**No `ENGINE_VERSION` bump and no stored re-run are owed.** `disabilityBenefitFromAge` defaults to
null, which `Person::receivesDisabilityBenefitAt()` reads as "the whole projection", so every stored
scenario reproduces byte-identically. Nothing else in the projector's arithmetic moved.

**#1.** `Person` gains `disabilityBenefitFromAge: ?int` beside the existing flag, and one accessor,
`receivesDisabilityBenefitAt($age)`, is the only place the two are read together. Considered and
rejected: replacing the boolean with a dated award holding a start, an end and a rate. The award's
END and RATE are already expressible as the benefit's own `IncomeStream`, so an award object would
have been a second home for two figures that already have one. Rationale in DECISIONS 2026-09-06.
`PathProjector::meansTestedBenefitNominal()` now takes the year's `$ages` and uses the accessor for
both the severe-disability count and the carer test, so a partner's benefit starting later also
delays the carer addition. The builder input only appears once the flag is ticked.

**#2.** `QuickWhatIf` gains a `claim_attendance_allowance` preset, which needs no new button because
the component renders `PRESETS`. It claims for every member who does not already receive a benefit,
from age 80, and adds the benefit's own money as a tax-free `disability_benefit` income stream from
the same age. Both halves move together on purpose: the flag opens the Pension Credit addition, the
stream is the cash, and a couple needs BOTH members on a qualifying benefit before the addition
applies at all, which is why it claims for everyone rather than one. Age 80 and the LOWER rate are
both the cautious end, per the standing adverse-default rule, and both are ordinary editable inputs
on the child afterwards. The passports are a new `disability_benefit_passports` input note on the
result, naming what IS in the figures (the benefit, the severe-disability addition, the carer
addition) and what is not (Support for Mortgage Interest, Council Tax Reduction, the Warm Home
Discount, the free TV licence, Cold Weather Payments, help with NHS costs). Naming Support for
Mortgage Interest as unmodelled is disclosure, not the modelling that card 0045 owns.

**#3.** Checkbox, validation, `blankPerson` default and assembler. The question is only asked of a
two-person household, since there is no partner to care for otherwise.

**#4.** `ThresholdPresenter::leverCaveat()` returns the warning for the retirement-age lever when a
member both cares for a partner and is still earning, and the explorer renders it beside the lever.
It reads `carersAllowanceEarningsLimitWeekly` off the tax-year config rather than restating it.

**What I could not settle from the repository.** Three benefit figures were needed and this session
has no web access. The 2025/26 Attendance Allowance pair (£73.90 / £110.40) is the published one,
written from build knowledge and not re-fetched. The 2026/27 pair (£76.70 / £114.60) is DERIVED by
the same +3.8% and 5p rounding this file's own 2026/27 Pension Credit additions were derived by. The
Carer's Allowance earnings limit (£196.00, then £203.36) applies the government's stated rule of 16
hours at the National Living Wage; the published rounding may differ. All of it is flagged in a
warning block on `BenefitsParameters`, written up in ASSUMPTIONS section 20, and carded as **0106**.
Neither figure enters a projection: one seeds an editable input, the other is quoted in a warning.

**Two faults found and not fixed here.** **0107**: `Person` is rebuilt by hand in three places with
no reflection guard, unlike `Household`, `ExpenseProfile` and the asset DTOs. The one in
`ProtectionGap::withoutEmployerCover()` would have silently dropped the new start age; that single
line is fixed here because I introduced the field, but the missing guard is that card. **0108**: the
projector awards the carer addition even while the carer earns far above the earnings limit, which
is now reachable because this card made the flag settable. This card's acceptance asked for a flag,
and suppressing the award moves stored plans, so it is carded rather than built.

**Not seen in a browser.** Built in a worktree, so the two new builder inputs, the new quick what-if
button, the passports note and the lever warning have not been looked at on a screen. That check is
still owed.
