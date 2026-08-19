# Pension Credit is counted as guaranteed income, and the claim prompt never shows

## Why
From the expert panel, 2026-08-19 (Citizens Advice findings 4 and 5, adviser finding 1). Detail in
the gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

**It is treated as secure.** `ResultPresenter::SECURE_SOURCES` lists `means_tested_benefit`
alongside the State Pension, so it sits inside the guaranteed floor that "essentials are covered"
is measured against. It is not secure. It moves with income, with capital, with a change of
circumstances and with a review. Around a third of eligible pensioner households never claim it at
all, and the engine credits it whether or not anyone has applied.

**The claim prompt is backwards.** `pensionCreditGuidance()` returns nothing unless some year
carries a positive award. So a household sitting just above the line - the exact case where a
caseworker most wants a nil claim on file - is shown nothing.

**A promised warning does not exist.** `CapitalAssessment::assess()` builds a capital-cliff warning
and the only consumer reads the tariff income and discards the object. Nothing in `PathProjector`
collects it. Yet METHODOLOGY.md tells the user that the loss of Housing Benefit and Council Tax
Support above the capital limit **is** flagged. It is the mirror image of the no-invisible-figures
rule: a disclosure the docs promise and the app never makes. Every sell-and-rent plan parks a large
sum and none of them shows the cliff.

The rule as coded is also wrong at the edge. Capital above the limit does not end that help for
someone receiving Guarantee Credit, who is fully passported with no upper capital limit.

## Not this card
Awarding Housing Benefit, which is card 0048.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL report Pension Credit as a contingent income line, outside the guaranteed floor.
- [ ] #2 WHEN a household comes within a small margin of the Pension Credit line in any year, THE APP SHALL prompt them to claim, and state the backdating limit.
- [ ] #3 WHEN assessable capital crosses the limit in any year, THE APP SHALL surface the capital-cliff warning on that year.
- [ ] #4 WHEN a household receives Guarantee Credit, THE APP SHALL NOT warn that capital ends their means-tested help.
<!-- AC:END -->

## Tasks
- [ ] Remove `means_tested_benefit` from `SECURE_SOURCES`; report it separately
- [ ] Trigger `pensionCreditGuidance()` on proximity, not on a positive award
- [ ] Collect and surface the capital-cliff warning from `PathProjector`
- [ ] Correct the Guarantee Credit carve-out in `CapitalAssessment`
