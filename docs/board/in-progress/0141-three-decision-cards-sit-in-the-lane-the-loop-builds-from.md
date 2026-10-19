# Three decision cards sit in the lane the unattended loop builds from

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
