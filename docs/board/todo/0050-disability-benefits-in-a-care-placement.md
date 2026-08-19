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
- [ ] #1 THE APP SHALL record a disability award as separate care and mobility components.
- [ ] #2 WHEN a resident self-funds their care, THE APP SHALL include the care component in assessable income and disregard the mobility component.
- [ ] #3 WHEN a placement is funded by the local authority, THE APP SHALL stop the care component after the statutory period and keep the mobility component running.
<!-- AC:END -->

## Tasks
- [ ] Split the disability income line into care and mobility at input and in the DTO
- [ ] Feed the care component into `assessableAnnualIncome`
- [ ] Suspend it 28 days into a local-authority-funded residential spell
- [ ] Clear `receivesDisabilityBenefit` for the severe disability addition while suspended
- [ ] Source each rule, with `source` and `verified_on`
