# A card with every criterion ticked stays in `in-progress/` and is picked up again

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 3 of 3 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

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

**2026-10-20** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Nothing in this card can be built from this repository. All three criteria name the scheduler in C:\Dev\ProgressBoard, and an unattended card session is told to stay inside its worktree, so the fix is not mine to make here. Criteria 1 and 2 say proves: manual, which this session cannot do either. A ProgressBoard session, or Rob, has to take it.

What I found by reading only (nothing was changed outside this worktree):

- 0083 is no longer in in-progress/. Rob's commit 5414f0cc (2026-09-21) moved it to ai-review/ by hand. Its message names the cause: every take ended on a hold it could not clear (first a red suite that belonged to 0147, then a merge-back conflict on the card file). The loop also resumes an in-progress card before anything else, so 55 sessions went on it in one day. So criterion 3 is already true in fact: 0083 is in ai-review/. But it got there by a hand move, not by the mechanism this card asks for, so I do not name it met.
- The scheduler today: Builder.php (around lines 573-651) promotes a fully ticked card to ai-review/ when the session exits cleanly and the named tests ran. A fully ticked card with a red suite is held (line 718, 'every criterion is ticked and the suite is red'). The no-progress counter (Builder.php ~1099, work-card.ps1 ~2404) moves a card to human-review/ after 2 such takes. So a fully ticked card should now stop looping after 2 takes. In September it looped because that counter did not stop it. I did not prove when that changed or that it covers the merge-conflict hold.
- Criterion 2 (a session told its card is already met may stop and say so) is about the prompt template, Prompt.php / work-card.ps1. I did not find wording for that case. It still needs a decision there.
- The other in-progress cards: only 0152 (this card) is in in-progress/ now, so the task 'check the other four' has nothing left to check in this repository.

Suggest: move this card to the ProgressBoard board, or let Rob tick criterion 3 by hand given 5414f0cc. That is his call, not this session's.

**2026-10-20** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same answer as the 2026-10-20 entry above. Nothing in this repository changed since then: 0083 is still in ai-review/ (Rob's hand move, 5414f0cc), and 0152 is the only card in in-progress/. All three criteria are about the scheduler in C:\Dev\ProgressBoard (lane-move logic in Builder.php, the session prompt in Prompt.php / work-card.ps1). An unattended card session must stay in its worktree, so it cannot build or check them. This card should not go to the loop again. It needs one of these, which is Rob's call: move it to the ProgressBoard board, or mark it not_for_the_loop: here.

**2026-10-20** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 3 of 3 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-20 manager (m20261020190934-bd10)

**outcome: rob**

The work is in another repository, so the loop here can never finish it, and only a person can move the card.

**what the session said**

I checked two facts. Card 0083 is now in `docs/board/ai-review/`. Rob moved it there by hand. ProgressBoard supports a `not_for_the_loop` card field: `app\Support\Board\Card.php` reads it and `bin\work-card.ps1` uses it.

All three criteria are still open. No review finding is involved, and no box is ticked to untick. The work is in the scheduler in `C:\Dev\ProgressBoard`: the lane-move logic in `Builder.php` and the session prompt in `Prompt.php` / `work-card.ps1`. A RetireForecast card session must stay in its worktree, so it cannot do this work. If I reopen the card, it comes back again. That is what happened twice already.

What is left is a board move, and only a person can do it:
1. Move card 0152 to the ProgressBoard board, where a builder can reach the code.
2. Or, if Rob wants it to stay in this repository, add `not_for_the_loop: here` so the loop stops picking it.

I would go with option 1.

WHY: The work is in another repository, so the loop here can never finish it, and only a person can move the card.

OUTCOME: rob

