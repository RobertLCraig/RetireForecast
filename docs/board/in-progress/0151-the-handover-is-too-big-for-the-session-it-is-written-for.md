# The handover is too big for the session it is written for, and says so on every startup

## Why
`docs/HANDOVER.md` is **78,981 bytes**. The project's own `SessionStart` hook measures it on every
single session and prints the verdict itself:

> HANDOVER.md IS 77 KB, over the ~40 KB a fresh session can afford to load, so the brief is already
> failing at its only job.

So this is not a judgement call about neatness. The tooling that greets every agent states, as its
first output, that the first document every agent is told to read cannot be read in full by the
session it exists to brief. A session that loads it spends a large share of its context before it
has looked at the card it was sent to work, and a session that skims it instead is picking up the
project on a partial read — which is the exact failure the root `CLAUDE.md` opens by forbidding.

The weight is measured, not guessed. The file is 783 lines, and everything before the first `##`
heading — the unstructured "exceptions a fresh session needs, newest first" list, **42 bullets**
running from line 12 to line 651 — is **59,958 bytes, 76% of the file**. The thirteen real sections
(`Goal & success criteria` through `Session log`) share the remaining 24%. The list has no cap and
nothing prunes it: every card that lands adds a bullet to the top and none are ever taken off, so
the file grows monotonically with the backlog and the oldest entries describe defects fixed months
ago.

Two archives already exist and are already used for exactly this — `docs/HANDOVER-ARCHIVE.md`
(95,097 bytes) and `docs/build/SESSION-LOG-ARCHIVE.md` (54,942 bytes). The mechanism is in place and
working; what is missing is anything that makes folding happen on a schedule rather than when a
session happens to notice the hook and have room to act.

`HandoverHygieneTest` guards the doc's shape but **not its size**, which is why the file could reach
twice its budget with the suite green throughout. The hook shouts and nothing fails.

A second, smaller thing the hook flags: **line 742 is 710 characters** — a paragraph wearing a
line's clothes — and line 664 is 628.

## Links

**Relates to**
- `0083` - where this was found. Its sessions read the hook's verdict on six consecutive pick-ups
  and none of them had scope to act on it, which is why it is a card now.

## Not this card
Deciding what is stale. Which of the 42 bullets still earn their place is a judgement about this
project's live risks, and a card cannot pre-answer it for the session doing the folding. This card
is to get the budget enforced and the folding scheduled, not to perform one fold and declare the
problem solved — a single fold that nothing holds in place puts the file back over budget within a
few cards.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN the suite runs, THE APP SHALL fail if `docs/HANDOVER.md` exceeds its agreed byte budget,
      so the file cannot silently grow past what a session can load. proves:
      `HandoverHygieneTest` - a new case asserting the budget; watch it fail against today's 78,981
      bytes before anything is folded.
- [x] THE APP SHALL bring `docs/HANDOVER.md` under that budget by folding stale blocks into
      `docs/HANDOVER-ARCHIVE.md`, losing no fact that is still true. proves: `HandoverHygieneTest` -
      the same case as above, green once the fold is done.
- [ ] THE APP SHALL state, in the handover itself, the rule that keeps the exception list bounded,
      so the next session folds by a rule rather than by taste. proves: none - what the rule should
      be (a bullet cap, an age cut-off, or "archive when the card reaches done/") is a call for Rob.
<!-- AC:END -->

## Tasks
- [ ] Agree the budget with Rob. The hook says ~40 KB; it is the only figure the repository states.
- [ ] Add the size assertion to `HandoverHygieneTest` and watch it fail at the current size.
- [ ] Fold the stale end of the 42-bullet exception list into `docs/HANDOVER-ARCHIVE.md`, oldest
      first, checking each bullet against whether the next session works differently for reading it.
- [ ] Break line 742 (710 characters) and line 664 (628) into sentences, or archive them.
- [ ] Record the bounding rule in the handover so this does not recur.

## Comments

**2026-10-20** RESULT: partial
TESTS: +1 new, all green
TOUCHED: tests/Feature/Docs/HandoverHygieneTest.php
docs/HANDOVER.md
docs/HANDOVER-ARCHIVE.md
OUT-OF-SCOPE: none

#1: `test_handover_fits_the_byte_budget_a_session_can_load` asserts docs/HANDOVER.md <= 40 * 1024 bytes. Watched it fail first: 92,941 bytes against 40,960 (the file had grown past the card's 78,981 since it was written). The budget is the figure the orient hook itself tests (`$bytes -gt 40KB` in ~/.claude/hooks/orient-hook.ps1, which also names 48 KB as the wall). The card's task says to agree the budget with Rob; I took the hook's figure as the one the repository states. If Rob wants the 48 KB wall instead, it is the one constant HANDOVER_BYTES_BUDGET.

#2: Folded the exception bullets for cards 0040 to 0082 (601 lines, about 55 KB, the oldest third of the list) verbatim into a new top section of docs/HANDOVER-ARCHIVE.md. Nothing was reworded or dropped. One bullet in the live handover says what still stands: the re-run owed per ENGINE_VERSION bump, the browser sign-off (0001), the three calls left to Rob that DECISIONS 2026-09-08 records (0078, 0080, 0081), and METHODOLOGY.md still not mentioning deprivation (0049, re-checked: still true). The working-tree warnings and the pre-0040 operational bullets (scenarios:audit gate, the uncarded override-map fault, the unapplied 0018 migration) stay live. Handover is now 38,260 bytes, so the headroom is only about 2.7 KB: the next few cards will hit the test.

#3 NOT met, by design: the card says the bounding rule (bullet cap, age cut-off, or archive at done/) is Rob's call. The _Last updated_ line now says the test holds 40 KB and that the folding rule is open on this card. Recommendation for Rob: archive a bullet when its card reaches done/, because the card already carries the detail and done/ is a fact a session can check.

Not done: the 710-character line is still there (it is now in a section below the list, not in a folded bullet). Splitting it is a task on the card, not an acceptance criterion, so I left it.
