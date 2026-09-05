# A card that needs a figure looked up burns an unattended session, every time, in silence

## Why
An unattended card session cannot reach the web. `WebSearch`, `WebFetch` and the browser tools are
all refused in one. Nothing in `docs/board/README.md`, `CLAUDE.md` or `docs/HANDOVER.md` says so.

So a card whose acceptance asks for a *sourced* figure looks perfectly buildable from the lane. The
loop starts it, the session reads the card, reads the engine, works out exactly what to build,
discovers it cannot get the one number the acceptance turns on, writes "blocked", and stops.
Everything except the number was done, and it will be done again next time, because nothing about
the card changed.

Card 0027 has done this twice, on 2026-09-05 and again on 2026-09-05, and the two entries in its
thread say close to the same thing. That is the failure `docs/board/README.md` names under
`## Comments`: a card something keeps writing to without having anything new to say. It is not one
card's problem. The same wall stands in front of 0032, which asks for "a sourced figure appropriate
to a leasehold sale", and in front of any card written the same way.

Nobody decided this. The rule that reaches the loop is `not_for_the_loop:`, and its own section in
the board README defines it by *outward effect* — DNS, a deploy, a message somebody receives. A card
that only needs to read a public web page has no outward effect at all, so the one key that would
have kept the loop off it does not, on its own terms, apply.

## Links

**Relates to**
- `0027` - the card this was found on; its thread carries both dated dead ends, which is the
  evidence for the cost.
- `0032` - asks for a sourced leasehold selling-cost rate, so it is standing behind the same wall
  and has not been started yet.

## Options

1. **Say it in the board README, and mark such cards.** Add web access to the list of things the
   unattended loop does not have, and say that a card needing a figure looked up carries
   `waiting_on:` with a recheck date, the way an outside quote does. Costs one paragraph and a
   sweep of the open lanes for cards that ask for a "sourced" anything. The loop then skips them and
   they surface to a person, who can clear several in one attended sitting. It does not make the
   figures appear; it stops the loop pretending it can get them.
2. **Give the unattended session web access.** Costs a scheduler change and a judgement that is
   yours: whether an agent running with nobody watching should be fetching from the internet at all.
   It would let these cards simply be built, which is the better end state if the answer is yes.
   Whichever way this goes, option 1's paragraph is still worth having, because it is the thing that
   makes the constraint visible either way.

## Recommendation
Option 1 now, because it is cheap, it is local to this board, and it stops the bleeding this week.
Option 2 is worth answering separately and is not urgent once the loop stops picking these cards up.

Whether the loop gets the web is a cost and a risk you carry, not something reading settles, so it
is yours. Paste into `## Comments`:

    **YYYY-MM-DD** **Decided:** Option 1. Document it in the board README and mark the
    affected cards `waiting_on:`. Web access for the unattended loop stays off for now.

## Comments
