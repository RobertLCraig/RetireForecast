---
not_for_the_loop: the private captures live only in Rob's own checkout, which an unattended worktree session may not write
---
# The park-home site names survive only in git history

## Why
Raised by card 0043. That card took the named park-home sites and their asking prices out of
`docs/build/PLAN-park-home.md`, because the names locate the household. The gitignored captures
did not hold them, and the card's session could not write to them. They are still in git history,
so a history purge under card 0012 would lose them for good.

## Links
**Relates to**
- `0043` - the scrub.
- `0012` - owns the history question.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL keep the park-home site listings in `docs/SCENARIO-V2.local.md`. proves: none
<!-- AC:END -->

## Tasks
- [ ] Copy the listings table and the above-budget line from `git show 28c5800:docs/build/PLAN-park-home.md`
      into the park-home section of `docs/SCENARIO-V2.local.md`
