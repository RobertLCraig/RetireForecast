# The household spends the same in real terms whatever happens

## Why
From the expert panel, 2026-08-19 (adviser finding 7). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`. The adviser-parity plan lists this as backlog.

Every path is scored against a fixed real spending target. `SpendPath` supports a planned
age-banded decline, but nothing is **state-dependent**. The household never cuts back after a bad
run of returns and never spends more after a good one.

That has two effects, both real.

A constant-real-spend simulation **overstates** the chance of running out, because no household
actually carries on spending into insolvency.

And it hides the cheapest mitigation there is. A guardrail that trims discretionary spend after a
bad run costs nothing and buys a lot of survival probability - **unless the household has no
discretionary spend left**, in which case that is itself the finding, and the tool cannot currently
state it.

One rule is enough. A funded-ratio trigger is the cheapest honest version: if projected usable
wealth falls below a multiple of remaining essential spend, cut discretionary spend by a set
percentage until it recovers. Published rules exist and are citable.

Reporting matters as much as the rule: show how often and how deeply it bit, not just the improved
probability.

## Not this card
Withdrawal sequencing across wrappers, which is card 0007.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL offer a spending guardrail that reduces discretionary spend when wealth falls below a trigger, and restores it on recovery.
- [ ] #2 THE APP SHALL expose the trigger and the reduction as editable inputs with a sourced default.
- [ ] #3 WHEN a guardrail is in use, THE APP SHALL report how many years it bit and by how much.
- [ ] #4 WHEN a household has no discretionary spend left to cut, THE APP SHALL say so.
<!-- AC:END -->

## Tasks
- [ ] Implement one funded-ratio guardrail in the projector, sourced and dated
- [ ] Report the bite count and depth alongside the success probability
- [ ] State the no-flexibility-left case explicitly
