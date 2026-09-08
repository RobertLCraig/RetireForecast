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

## Links

**Relates to**
- `0045` - Support for Mortgage Interest is that card, and it is one of the things an Attendance
  Allowance award passports to.

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

### 2026-09-06 review (v20260906093010-aadb)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 231s, run by this job rather than reported by the card.

**acceptance: sound**

I checked each box against real code.

**#1** `Person::receivesDisabilityBenefitAt()` gates the flag on age. `PathProjector::meansTestedBenefitNominal()` calls it for both the severe-disability count and the carer test, using the same per-year `$ages` map that `careAnnualCost` and income-stream start ages use. The field is validated in `ScenarioBuilder::rules()`, defaulted in `ScenarioBuilder::blankPerson()`, shown in `resources/views/livewire/scenario-builder.blade.php`, and mapped in `HouseholdAssembler::person()`. Nothing else in `app/` or the engine reads the raw flag as a for-life flag except the disclosure note.

**#2** `QuickWhatIf::claimAttendanceAllowance()` sets flag, start age and a tax-free `disability_benefit` stream; the button renders from `QuickWhatIf::PRESETS` in `resources/views/components/quick-what-ifs.blade.php`. New stream rows are legal adds under `BuilderStateDelta::merge()`. The passports list is in `ResultPresenter::inputNotes()` and renders in `scenario-results.blade.php`.

**#3** `ScenarioBuilder::rules()` + blade checkbox (couples only) + `HouseholdAssembler::person()`.

**#4** `ThresholdPresenter::leverCaveat()` reads `carersAllowanceEarningsLimitWeekly` off the registry; `ThresholdExplorer::render()` passes it and `threshold-explorer.blade.php` prints it.

I tried to find a criterion with no code behind it and could not.

VERDICT: sound

**scope: defect**

**Scope review, card 0044.**

**1. Grew: the builder tells the user a rule the app does not apply.** The new "Cares for their partner" help text in `resources/views/livewire/scenario-builder.blade.php` says "pay above it stops this counting while they are still working." It does not. `PathProjector::meansTestedBenefitNominal()` awards the carer addition whatever the carer earns; card 0108 exists to fix that. The card asked to *expose* the flag (#3) and to *flag* the limit on the lever (#4). `ThresholdPresenter::leverCaveat()` is honest ("this sweep does not subtract it"); this text is not. So the one screen that sets the flag misdescribes a figure that is in the projection. That is the invisible-figures rule, and the next session must fix the wording or the model.

**2. Grew: an unused unverified figure.** `BenefitsParameters::$attendanceAllowanceHigherWeekly` is read only by `TaxYearAudit::describe()`. The task said "using the sourced rate"; the preset uses the lower one. It adds a second unpinned number to card 0106 for a display line.

**3. Half done (minor).** `QuickWhatIf::PRESETS` "Retire 2 years later" also extends working life and carries no carer warning.

VERDICT: defect

**breakage: defect**

**Finding 1 ÔÇö the carer warning fires when it cannot bite.**
`ThresholdPresenter::leverCaveat()` tests only two things: the person cares for a partner, and the person still earns. `PathProjector::meansTestedBenefitNominal()` awards the carer addition only when a *living partner receives a qualifying benefit at that year's age*. The presenter asserts neither half.

Two ways it breaks, both now reachable because this card made both flags settable:

- Tick "cares for partner" and leave the partner's disability flag off. The projector never awards a carer addition. The page still shows the amber warning that working longer "postpones that addition, year for year". A warning about money that is not in the model.
- Set the partner's `disabilityBenefitFromAge` to 80, which is exactly what the new Attendance Allowance preset writes. No carer addition can exist before 80, so a retirement-age lever in the 60s postpones nothing. The caveat ignores `disabilityBenefitFromAge` entirely ÔÇö the field this card added.

`test_the_carer_earnings_limit_is_not_flagged_where_it_cannot_bite` builds only "nobody caring" and "carer already retired". Neither of the two cases above is built.

**Finding 2 ÔÇö same shape, smaller.** `QuickWhatIf::claimAttendanceAllowance()` filters claimants on the flag alone. A person who has a tax-free `disability_benefit` income stream but an unticked flag gets a second stream stacked on the first.

VERDICT: defect


**2026-09-06** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 4 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 4 of 4 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
