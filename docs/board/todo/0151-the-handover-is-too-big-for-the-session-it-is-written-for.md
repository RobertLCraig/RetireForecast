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
- [ ] WHEN the suite runs, THE APP SHALL fail if `docs/HANDOVER.md` exceeds its agreed byte budget,
      so the file cannot silently grow past what a session can load. proves:
      `HandoverHygieneTest` - a new case asserting the budget; watch it fail against today's 78,981
      bytes before anything is folded.
- [ ] THE APP SHALL bring `docs/HANDOVER.md` under that budget by folding stale blocks into
      `docs/HANDOVER-ARCHIVE.md`, losing no fact that is still true. proves: the same test, green
      once the fold is done.
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
