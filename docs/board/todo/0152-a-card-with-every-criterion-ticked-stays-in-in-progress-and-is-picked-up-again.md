# A card with every criterion ticked stays in `in-progress/` and is picked up again

## Why
Card `0083` was built on 2026-09-08. The commit that built it, `133a7dc`, set both of its acceptance
criteria to `- [x]` in the same change. It is still in `docs/board/in-progress/`, and it has been
handed to a fresh unattended session five times since.

    133a7dc  2026-09-08  built it, and ticked both criteria
    5473127  2026-09-20  re-verify
    ab63cfe  2026-09-20  re-verify
    cf6bb61  2026-09-20  re-verify
    9eb37ff  2026-09-20  re-verify
                          re-verify (this card's own session, the fifth)

Every one of those five entries in `0083`'s comment thread opens `RESULT: done`, says in its own
words that it is a verification and not a build, and records `TESTS: +0 new`. Five whole sessions
produced no work the card asked for, because there was none left to do.

The board's own rule is that the folder is the state, and the scheduler's standing instruction to a
session says it reads the ticked acceptance to decide where the card goes next. The ticks have said
"met" since 2026-09-08. Something between those two facts is not firing, and the card cannot say
which, because the lane-move logic lives in `C:\Dev\ProgressBoard` and this session did not read it.

The cost is not only the five sessions. A session handed a finished card is under quiet pressure to
find something to do with it, and the honest options are all bad: re-run the checks and write a sixth
near-identical entry, widen the card past the scope somebody reviewed, or go looking for work
elsewhere on the board. The first is what all five chose, so the thread is now longer than the card.

It also hides the real state of the board. `in-progress/` is meant to mean an agent is building it
right now. One of the five cards in that lane is finished, so the lane over-reports open work, and
`0083` has never reached `ai-review/` — which, per the board README, is where a card that produced
code goes, and is the review that no run so far has had.

Worth noting for whoever takes this: `0083`'s ticks are correct and were earned. This is not a card
about undoing them, and nothing about the Pint work needs redoing.

## Links

**Relates to**
- `0083` - the card this was found on, and the one carrying five verification entries.
- `0141` - the same family of fault one lane back: cards sitting in a lane that does not describe
  them, and an unattended session spending a whole run to discover the card was never for it.
- `0148` - the other way this board wastes a session on settled work: a `todo/` card asking for what
  `0083` already did.
- `0149`, `0150` - also faults in the scheduler's tooling that surface inside this repository, so
  whoever opens that tooling for this can look at those in the same sitting.

## Not this card
Moving `0083`, or any card, between lanes by hand. The scheduler owns lane moves, and a card shoved
across by an agent papers over the mechanism this card exists to fix. Also not this card: reviewing
`0083`'s Pint work, which is what `ai-review/` is for once it gets there.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a card in `in-progress/` has every acceptance criterion ticked, THE APP SHALL move it out
      of that lane rather than offering it to another session. proves: manual - the lane-move logic
      is in the scheduler outside this repository, so no test inside it can observe the decision.
- [ ] WHEN an unattended session is handed a card whose criteria are all already met, THE APP SHALL
      let it stop and say so, rather than leaving a finished card as its assignment. proves: manual -
      same reason; this is the session template's behaviour, not this repository's.
- [ ] THE APP SHALL leave `0083` reviewed rather than merely finished, since a card that produced
      code has not been through `ai-review/`. proves: none - which lane it lands in is the
      scheduler's call once the move above works.
<!-- AC:END -->

## Tasks
- [ ] Read the lane-move logic in `C:\Dev\ProgressBoard` and find why a fully-ticked card in
      `in-progress/` is re-offered instead of moved on.
- [ ] Check whether the same gap affects the other four cards now in `in-progress/`, or only `0083`.
- [ ] Decide what a session should do when its card is already met: stop and report, or be given the
      next card. Put it in the session template so it is not five sessions' improvisation.

## Comments
