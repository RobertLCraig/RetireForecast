# The board: one task per file, and the folder is the state

**A card is a file. The folder it sits in is its state. Moving it is `git mv`.**

```
docs/board/
  todo/           ready to pick up, nothing in the way
  in-progress/    an agent is building it right now
  ai-review/      built, awaiting an adversarial review by an agent
  human-review/   a person owes something: a call to make, a build to accept,
                  or an outside party to chase
  done/           reviewed, accepted, delivered. The final product.
  discarded/      abandoned or superseded, with one line of why
```

The pipeline is a loop, not a line. Work leaving `human-review` goes back to an agent and returns
either to `human-review` again or forward to `ai-review`. **Nothing reaches `done/` without an
adversarial review**, which is what `ai-review/` is for: the point is not that the work exists, it
is that somebody tried to break it first.

**A decision card skips `ai-review/`.** There is no artefact to review; its exit is the decision
itself, so it goes from `human-review/` straight to `done/`.

There is no `status:` field, because the folder already says it. A card carrying both would
eventually disagree with itself, which is the failure this board exists to remove. There is no
`created`, `updated` or `author` field either: git holds those, and a copy would drift.

## One lane for the human, not three

A call to make, a build to accept, and an outside party to chase are the same state: nothing moves
until a person spends attention. Splitting them would be three folders to sweep instead of one, and
what a card wants is already derivable from its own shape, so the board can say "8 to call, 3 to
accept" without a second folder saying it.

The lane is named after the job, not the person, because these boards are read by more than their
author and the convention is the same on every project.

There is no `blocked/` or `waiting/` lane. Both named a state without naming who clears it, and a
holding lane nobody owns is how one real board reached 21 cards nobody could clear. If an outside
party owes you something, chasing them is your action: the card sits in `human-review/` with a
`waiting_on:` note carrying a recheck date, and a past-due recheck is surfaced.

## Two kinds of card, and the kind is derived

A card with `## Options` is a **decision**. A card with `## Tasks` is a **feature**. Nothing
declares its kind, because a declared kind is one more thing that can disagree with the card's own
contents.

**Decision:** `# title`, `## Why`, `## Options` (at least two, each with its cost),
`## Recommendation`, `## Decided`. The recommendation is the point: a decision surfaced without one
hands over the whole problem, while one that recommends has done the reading and leaves only the
judgement.

**Feature:** `# title`, `## Why`, `## Not this card`, `## Acceptance`, `## Tasks`, and an optional
`## Plan` that is deleted when the card reaches `done/`. `## Not this card` is a scope fence the
agent reads and obeys, and it is the cheapest defence against the commonest agent failure, which
is quietly building three adjacent things.

Acceptance uses EARS phrasing (`WHEN <trigger>, THE APP SHALL <observable result>`) inside
`<!-- AC:BEGIN -->` sentinels, so it stays greppable and interoperable with Backlog.md's
convention.

## Direction and Decided

`## Direction` is steering: how to approach something, a spike worth running first, a constraint
the agent should know. `## Decided` is the answer to a decision card, and filling it is that card's
exit condition. Both are append-only dated entries, added and never edited. An answer recorded as
direction neither reads as a ruling nor moves the card, which was found by watching it happen.

## Naming

`NNNN-slug.md`, four digits, allocated in order and **never reused**, so a number stays quotable
after the card has moved three times. Cross-project identity is derived at render time from the
directory name, giving `progressboard#0007`. Nothing on disk carries a project prefix.

## What the board is not

It is not the specification, the rationale, or the narrative. What the system is lives in the PRD
and the design proposal; why we decided something lives with the decided cards; what happened is
the commit log. A card links to those. It does not restate them.
