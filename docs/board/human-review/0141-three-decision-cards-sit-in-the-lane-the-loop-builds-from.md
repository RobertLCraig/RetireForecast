# Three decision cards sit in the lane the unattended loop builds from

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
`todo/` is the lane the unattended build loop takes its next card from. Three of the cards in it are
not buildable work at all. They are decisions: each one carries `## What I need from you` or
`## Options` and `## Recommendation`, and each is waiting on an answer only Rob can give.

- `0002` whether the working partner contributes to a pension at all, which is a fact about them.
- `0003` the death-in-service cover to enter, which is a figure off their policy.
- `0084` whether the unattended loop should get web access, which the card itself says is a cost and
  a risk Rob carries.

An unattended session that starts one of them can do nothing with it. It reads the card, finds the
question, and stops. That is the same waste card `0084` was raised about, one lane further back: the
loop spends a whole session to discover the card was never for it.

`human-review/` is the lane a decision waits in, and the board README says so twice: the four-reason
test is what may enter that lane, and answering a decision is what moves it back to `todo/`. None of
these three has been answered, so none of them has earned a place here.

Nobody put them there as a decision. `0002` and `0003` predate the 2026-08-18 convention that
separated the two kinds of card, and `0084` was written straight into `todo/` by the session that
found the fault it describes.

## Links

**Relates to**
- `0084` - names the cost of an unattended session starting a card it cannot finish. These three are
  instances of it that sit one lane earlier than the cards that card is about.
- `0072` - the rewrite pass that found these, and whose four-reason sweep confirmed all three are
  genuinely Rob's rather than research an agent could apply.

## Not this card
**Answering any of the three.** Each is Rob's, and each already carries its own recommendation.

**Moving the cards.** The scheduler owns every lane move on this board. This card records which
three should move and why, so that whoever runs the move has the list.

**Any other card in `todo/`.** The rest of the lane is buildable work.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE THREE CARDS `0002`, `0003` AND `0084` SHALL sit in `human-review/`, not in the lane the
      unattended loop builds from. proves: none - a lane is a folder, and the scheduler moves it
- [ ] #2 WHEN one of the three is answered, THE CARD SHALL return to `todo/` as ordinary work, per
      the board README's rule that answering a decision moves it back. proves: none - as #1
<!-- AC:END -->

## Tasks
- [ ] Ask the scheduler to move `0002`, `0003` and `0084` from `todo/` to `human-review/`
- [ ] Re-read `todo/` for any further card carrying `## Options` or `## What I need from you`

## Comments

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code or test changed)
TOUCHED: none
OUT-OF-SCOPE: none

The card's premise is overtaken. 0002, 0003 and 0084 are no longer in todo/: all three carry a Decided answer and sit in done/ (commits dc75fc64 for 0002, 1424f423 for 0084; 0084 decided 2026-09-28). So the fault the card names, decision cards in the loop's lane, no longer exists for these three.

Neither criterion is met as written, so I name none. #1 asks for the three to sit in human-review/; they are in done/, which is the correct place for an answered decision, and moving them back would be wrong. #2 is satisfied in spirit (answering moved them out of the decision lane), but they went to done/, not todo/, because each answer was acted on. Both are proves: none and are lane moves the scheduler owns, so there was nothing to build.

Task 2, re-read of todo/ for further cards with '## Options' or '## What I need from you': four match, and every one is already kept from the loop by its frontmatter, so no new card is needed. 0004 has needs: 0022; 0015 has waiting_on: progressboard#0211; 0099 and 0173 have not_for_the_loop:.

Recommendation for Rob or the reviewer: discard this card as overtaken, or close it as done with the note above. That call is not mine to make unattended.

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code or test changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same finding as the first. The card's premise is overtaken: 0002, 0003 and 0084 are not in todo/. All three carry a Decided answer and sit in done/, which is where an answered decision belongs. Moving them to human-review/ (#1) would undo answers that were already acted on, so I name no criterion met. #2 is a lane rule the scheduler owns; nothing to build.

Task 2 re-read of todo/ for '## Options' or '## What I need from you': four match (0004, 0015, 0099, 0173). Each is already kept from the loop by frontmatter (needs: 0022; waiting_on: progressboard#0211; not_for_the_loop: on 0099 and 0173). No new card needed.

This card has now spent two unattended sessions finding nothing to do. Rob or the reviewer should close it as overtaken (discard, or done with this note). That call is not mine to make unattended.

**2026-10-20** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-20 manager (m20261020011028-45a7)

**outcome: rob**

The three cards were answered and moved to `done/`, so this card has no work left, and only Rob can close it as overtaken (discard it, or move it to `done/` with the builder's note).

**what the session said**

Both builder takes were right. I checked the board. `0002`, `0003` and `0084` are all in `docs/board/done/`. Each one has an answer, and that answer was acted on.

So the problem this card describes is already gone. Criterion #1 asks to move the three cards to `human-review/`. Doing that would undo answers that are already in place. Criterion #2 is a rule about moving cards between lanes. The scheduler does those moves, so there is nothing for the builder to build. Neither box is ticked, and no review finding disproves anything. If I reopen the card, a third session will find the same nothing.

Task 2 is done. The other four decision-style cards in `todo/` (`0004`, `0015`, `0099`, `0173`) are already kept away from the loop by settings at the top of each card. Nobody has to choose anything here. What is left is to close the card, and only a person can do that.

WHY: The three cards were answered and moved to `done/`, so this card has no work left, and only Rob can close it as overtaken (discard it, or move it to `done/` with the builder's note).

OUTCOME: rob

