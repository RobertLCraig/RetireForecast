# CI and data-hygiene remainder

## Why
The freshness guardrails already run monthly in CI (the `data-freshness` workflow, which takes
effect on GitHub once pushed). What is left is low-value hardening.

## Not this card
The freshness guardrails themselves, which are done.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL record a tamper-evident hash for each forecast run.
- [ ] #2 THE APP SHALL cache forecasts such that an unchanged scenario does not recompute.
<!-- AC:END -->

## Tasks
- [ ] Push so the `data-freshness` workflow takes effect on GitHub
- [ ] Tamper-evident run hash
- [ ] Forecast caching
