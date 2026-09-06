# Disability benefits are handled wrongly in both directions during care

## Why
From the expert panel, 2026-08-19. The estate planner and the Citizens Advice caseworker reached
the same split independently. Detail in the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

**For a self-funder, the engine leaves money out.** `CareMeansTest::annualCharge()` is passed only
taxable income, so tax-free disability benefits are excluded. In a local-authority financial
assessment, Attendance Allowance and the **care** component of Disability Living Allowance are
taken into account. Only the **mobility** component is disregarded. So the engine understates what
a self-funding resident contributes.

**For a local-authority-funded resident, the engine leaves money in.** Attendance Allowance and the
care component actually **do** stop after 28 days in a placement the authority funds. The engine
never stops them, so a disability award keeps paying into household income right through a modelled
care spell - mobility component and all.

Care is the stress that decides plan rankings, and the model gets the household's largest tax-free
income wrong on both sides of the assessment.

The fix needs the award split into its care and mobility parts at input. Both rates are normally
known separately.

## Not this card
The property disregard and deferred payments, which are card 0055.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL record a disability award as separate care and mobility components.
- [x] #2 WHEN a resident self-funds their care, THE APP SHALL include the care component in assessable income and disregard the mobility component.
- [x] #3 WHEN a placement is funded by the local authority, THE APP SHALL stop the care component after the statutory period and keep the mobility component running.
<!-- AC:END -->

## Tasks
- [x] Split the disability income line into care and mobility at input and in the DTO
- [x] Feed the care component into `assessableAnnualIncome`
- [x] Suspend it 28 days into a local-authority-funded residential spell
- [x] Clear `receivesDisabilityBenefit` for the severe disability addition while suspended
- [ ] Source each rule, with `source` and `verified_on`

## Comments

**2026-09-06**
RESULT: done
TESTS: +6 new, all green
TOUCHED:
packages/finance-engine/src/Benefits/DisabilityBenefitInCare.php (new)
packages/finance-engine/src/Dto/IncomeStreamType.php
packages/finance-engine/src/Care/CareMeansTest.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/CareMeansTestedChargeTest.php
app/Forecast/ScenarioForecaster.php
app/Forecast/ResultPresenter.php
app/Forecast/WhatIfChanges.php
app/Livewire/ScenarioBuilder.php
resources/views/livewire/scenario-builder.blade.php
tests/Unit/Forecast/HouseholdAssemblerTest.php
tests/Unit/Forecast/InputNotesTest.php
tests/Feature/Livewire/ScenarioBuilderTest.php
docs/spec/ASSUMPTIONS.md
docs/board/todo/0118-pin-the-disability-benefit-care-rules.md (new)
OUT-OF-SCOPE: 0118

The award is now two income-stream types rather than one. `IncomeStreamType::DisabilityBenefit` is
the CARE (daily living) component and keeps the stored value `disability_benefit` it has always
had, so every award entered before the split reads as care: the adverse reading on both sides of
the assessment. `DisabilityBenefitMobility` is the new sibling, forced tax-free by the same
assembler line, and offered as its own option in the builder's type select.

`Benefits\DisabilityBenefitInCare` is the one home for the statutory rule and for the 28 days.
`PathProjector` settles funding status ONCE a year, before any income is assembled, in
`disabilityCareComponentFractions()`; three places then read that one answer (the tax-free income
the household banks, the Pension Credit severe-disability and carer additions, and the care
charge), so they cannot disagree about whether the benefit was in payment.

Assumed, because the repository does not settle it. Funding status is taken from the same
`CareMeansTest::assess()` self-funder line the charge is built on, i.e. from the resident's own
assessable capital as the year OPENS. The alternative was to derive it from the charge itself
(charge below the gross fee means the authority pays the balance), which is circular: the charge
depends on the assessable income, which now depends on whether the benefit stopped. Reading the
capital breaks the circle and reuses a rule the engine already owns. The consequence is that the
crossing year, where the formula pays capital down to the upper limit, counts as SELF-funding for
the benefit's purposes.

The severe-disability addition is dropped for the whole of a funded care year, although the first
one keeps 28 days of the benefit itself. An annual grid cannot pay a part-year addition, and
dropping it is the adverse of the two roundings.

`ENGINE_VERSION` is `finance-engine/disability-award-split-in-care` and the **stored-scenario
re-run is owed**: any plan holding a tax-free disability income AND a modelled care spell moves,
in either direction depending on which side of the assessment it lands. A plan with no disability
income, or whose paths never reach care, is byte-identical, which is why `GoldenMasterTest` did
not redden and needs no re-pin.

The last Task is left OPEN and is card **0118**. Both rules are STATED, not verified: an unattended
card session has no web access (`WebSearch` and `WebFetch` are refused), so the 28 days and the
care-versus-mobility disregard are this session's own knowledge of the legislation, disclosed as a
sourcing gap on the class and written up at docs/spec/ASSUMPTIONS.md section 25. The acceptance
criteria do not depend on it, so they are ticked and the Task is not.

Built in a worktree, so the new builder option, the new type label and the new result note **have
not been seen in a browser**.
