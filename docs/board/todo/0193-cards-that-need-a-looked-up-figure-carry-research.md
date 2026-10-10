---
waiting_on: progressboard#0211, the research-only web session that reads the `research:` key - recheck 2026-10-13
---
# Cards that need a looked-up figure carry `research:`, and the board says why

## Why
An unattended build session here has no web. A card whose acceptance turns on a sourced figure
looks buildable from the lane, so the loop starts it, gets as far as the number, writes "blocked"
and stops, and does the same on the next take. Card 0027 did this twice; the sourcing cards 0086,
0087, 0091 and 0129 have been parked on a person for it.

Rob ruled on 0084 (2026-09-28): the web goes ONLY to a separate research session that can write
notes under `docs/research/` and nothing else, and a later build session, which keeps no web,
builds from the note. That session is built in ProgressBoard by `progressboard#0211`, and it is
started by a `research:` key in a card's frontmatter, with the question as its value.

Nothing on this board uses that key yet, and `docs/board/README.md` does not say the build loop has
no web. So once 0211 lands, every card stuck behind the wall must be marked, or the research
session never runs for it and the loop keeps burning takes.

## Links

**Relates to**
- `0084` - the decision this carries out: web only in a notes-only research session, and the
  waiting cards get a `research:` key once it lands.
- `progressboard#0211` - builds the research session and defines what `research:` means; this
  card waits on it.
- `0086`, `0087`, `0091`, `0129` - the four cards 0084's answer names; each carries
  `waiting_on: progressboard#0211`.
- `0027`, `0032` - the cards the wall was found on (a sourced bridging cost, a sourced leasehold
  selling-cost rate).
- `0085`, `0095`, `0106`, `0109` and the other "pin" / "source" cards in `todo/` - the same shape of
  gap; the sweep decides which of them qualify.

## Not this card
- Building the research session, or changing any scheduler script. That is `progressboard#0211`.
- Looking up any figure. The research session does that from the key this card writes.
- Changing any engine code, constant or `docs/spec/ASSUMPTIONS.md` row.
- Moving any card between lanes.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 `docs/board/README.md` SHALL say, in its frontmatter key table and one short paragraph, that the unattended build and review sessions have no web, and that a card whose acceptance needs a figure looked up carries `research:` with the question as its value, worded to match what `progressboard#0211` shipped. proves: none - a prose rule in a board doc; read it against 0211's README text
- [ ] #2 EVERY open card (todo/, human-review/, in-progress/) whose acceptance cannot be met without reading a public web page SHALL carry `research:` naming the exact figures and the file the answer lands in. 0086, 0087, 0091 and 0129 are among them. proves: none - which cards need the web is a judgement made by reading each card
- [ ] #3 ON each card marked in #2, the `waiting_on: progressboard#0211` line SHALL be removed, and its "What I need from you" A/B question SHALL be replaced by a dated `## Comments` line saying 0084 settled it. proves: none - card text, checked by reading the diff
- [ ] #4 THE SWEEP SHALL be listed in this card's `## Comments`: each card marked, and each card read and left unmarked with the reason. proves: none - a record, checked by reading it
<!-- AC:END -->

## Tasks
- [ ] Confirm `progressboard#0211` is in `done/` and read the key's final name and value format from it.
- [ ] Add `research:` to the key table and the paragraph to `docs/board/README.md` (#1).
- [ ] Sweep the open lanes and mark each qualifying card (#2, #3).
- [ ] Write the sweep list into `## Comments` (#4).

## Plan
This card is doc and frontmatter work only. Do it after `progressboard#0211` is in
`C:\Dev\ProgressBoard\docs\board\done\`. If it is not, stop and leave this card where it is.

1. Read `progressboard#0211` (find it with
   `php C:\Dev\ProgressBoard\artisan board:find 0211 --project=ProgressBoard`). Take from it the key
   name, its value format, and what a research session hands back. Do not invent a format. If 0211
   changed its README text, copy that wording into this board's README.
2. Ask: is `docs/board/README.md` here a copy of a canonical README kept outside this repo? 0211's
   tasks say the canonical copy lives elsewhere. If a local edit would be overwritten, put the text
   where 0211 says and record that in `## Comments`.
3. Find candidates: `grep -rliE "sourced|published source|verified_on|pin the|source the" docs/board/todo
   docs/board/human-review docs/board/in-progress`. Read each hit. Mark a card only if its acceptance
   asks for a figure that only a web page can give. A card that already has its source URL, or that
   needs no outside number, is left alone and listed as such.
4. Write each `research:` value as the question a researcher with no other context can answer:
   which figures, for what case, and the file and section the answer lands in (for example
   `docs/spec/ASSUMPTIONS.md` §14). The research session sees only the card.
5. 0027 is in `in-progress/` and 0032 is in `ai-review/` today. Read where each is when you start;
   do not move either. Mark one only if it is still open and still turns on an unsourced figure.
6. A card with `needs:` as well keeps it. Remove only the `waiting_on:` that names 0211.
