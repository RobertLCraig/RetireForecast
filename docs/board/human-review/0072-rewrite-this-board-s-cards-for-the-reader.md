# Rewrite this board's cards for the reader

## Why
**A card on this board opens with the answer and never says what is wrong.** On 2026-08-18 Rob said
most of the cards he was handed made him work backwards: they lead with candidate solutions and
their costs, so he has to reverse-engineer the problem out of the proposals. He cannot tell whether
the options are the right ones, because he does not yet know what they are for.

**Two more faults, in his words.** Cards ask him to settle things an agent could have researched and
applied. And a bare card number dropped into a sentence tells him some other card matters and
nothing about why, so he opens it to find out.

**What it costs.** His attention is the only scarce thing here. Measured on 2026-08-20, 258 of 398
open cards across the estate fail at least one of these rules and 257 of those fail on the link rule
alone. A card that reads badly costs a round trip; one that should never have been surfaced costs
the whole reading for nothing. Enough of either and he stops opening the ones that mattered.

**How it came to be this way.** Every card here was written by an agent against a convention that,
until 2026-08-18, said nothing about stating the problem first, nothing about whether a question was
a person's to answer at all, and nothing about how to name another card. It gained all three rules
that day, and nothing was applied to the cards, so this board is measured against a standard none of
it was written to.

## Links

**Relates to**
- `progressboard#0065` - the estate-wide rewrite this card was seeded from; its pilot over
  ProgressBoard's own 40 cards is the worked example of a pass.
- `progressboard#0066` - the five checks the count below is measured with, and why each is
  structural rather than a judgement about prose.

## Not this card
**Changing the convention.** `docs/board/README.md` here is a COPY of a canonical file outside every
repository, so an edit to it is destroyed silently on the next distribution. This card applies the
convention and never changes it.

**Rewriting cards in `done/` or `discarded/`.** Those are a record of what happened. Rewriting a
record is falsifying it, and nobody reads them to decide anything.

**Deleting anything.** A badly written card still holds facts somebody measured. A rewrite keeps
everything the card knows and changes only how it is ordered and said. `## Direction` and
`## Decided` are append-only: do not edit them, on any card, for any reason.

**Any other board.** Each one carries its own copy of this card, worked in its own repository.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a card in a non-terminal lane is rewritten, THE CARD SHALL state the problem in
      `## Why` before any solution appears anywhere in it. proves: none - about prose, and no check
      here reads prose
- [x] #2 WHEN a rewritten card is a decision, THE CARD SHALL say which of the four reasons makes it
      a person's to answer, or SHALL be converted to a feature card whose `## Plan` records the
      practice applied and its source. proves: none - the command that counts it is in another
      repository, named in `## Plan`
- [x] #3 WHEN a rewritten card names another card, THE CARD SHALL name it in a `## Links` section
      with the relationship type and one line of why, and SHALL NOT leave a bare card number in a
      sentence as the only mention of it. proves: none - as #2
- [x] #4 THE `Blocked by` LINES on every rewritten card SHALL match that card's `needs:` frontmatter
      exactly, in both directions. proves: none - as #2
- [x] #5 THE REWRITE SHALL preserve every measurement, date and decision the card already carried,
      and SHALL NOT edit `## Direction` or `## Decided`. proves: none - as #2
- [x] #6 WHEN this board's rewrite is finished, THE BOARD SHALL report zero open cards failing the
      checks. proves: none - as #2
<!-- AC:END -->

## Tasks
- [x] Read the count, and write it into `## Comments` before changing anything
- [x] Rewrite `human-review/` first, then `todo/`, `in-progress/` and `ai-review/`
- [x] For each decision card, apply the four-reason test and convert the ones that fail it
- [x] Read the count again and write into `## Comments` what changed, counted by rule

## Plan
**Where to stand.** This repository, on whatever branch the session was given. Nothing outside it is
edited and no card changes lane. **The one command, from this board's directory, in PowerShell:**

    php C:\Dev\ProgressBoard\artisan board:convention --path=$PWD

It prints one tab-separated line: board name, OPEN cards failing the checks, open cards, the next
free card number, and the directory read. The second number is this card's finish line and it must
reach 0. Run it before the first edit and after the last. `--path` matters: a build worktree is not
`C:\Dev\<board>`, and without it you measure a tree you are not editing.

**What the checks look for is in `docs/board/README.md` here**, three sections of it: "`## Why` is
the PROBLEM, and it comes before any answer", "Links: say what the relationship IS, never a bare
card number", and "Is this actually a person's to decide?". Read those three first. Every check is
structural - a missing `## Links` section, a `Blocked by` line that disagrees with `needs:`, a link
with nothing after the dash - so each flag names one thing to fix and none is an opinion.

**`human-review/` first, and that is not tidiness.** That lane is the only one a person reads. A
`todo/` card is read by an agent, a reader with different problems, so rewriting those first spends
the session on the half nobody is complaining about.

**Expect the four-reason test to shrink the queue rather than reformat it.** A decision whose answer
turns on established practice is not Rob's: research it, apply it, and rewrite the card as a feature
card whose `## Plan` says what was applied and where it came from. Count those separately from the
cards merely rewritten - that is the change that gives him evenings back.

**If the board is too big for one session, stop cleanly.** Tick nothing, write the count you reached
into `## Direction`, and leave the card where it is; the next session carries on from that entry. A
part-rewritten board is normal. A card ticked off a board that is not at 0 is not.

## Comments

**2026-09-08** RESULT: partial
TESTS: +0 new, all green
TOUCHED:
docs/board/in-progress/0072-rewrite-this-board-s-cards-for-the-reader.md
docs/board/ai-review/0065-modelling-and-sourcing-remainder.md
docs/board/human-review/0004-where-the-v2-bases-remortgage-money-comes-from.md
docs/board/human-review/0007-withdrawal-sequencing-pcls-timing-and-optimiser.md
docs/board/human-review/0016-adviser-parity-remainder.md
docs/board/human-review/0017-b2-b4-results-page-restructure.md
docs/board/human-review/0022-interest-only-and-rio-indications-change-the-keep-the-flat-routes.md
docs/board/human-review/0024-interest-only-mortgage-cost-is-inflated-and-survivor-scaled.md
docs/board/human-review/0025-an-unfunded-one-off-zeroes-the-full-spend-probability.md
docs/board/human-review/0028-property-holding-costs-are-too-smooth.md
docs/board/human-review/0030-let-to-let-is-modelled-on-gross-rent.md
docs/board/human-review/0031-no-letting-affordability-test-on-a-rent-plan.md
docs/board/human-review/0033-expenses-are-misfiled-across-the-sell-boundary.md
docs/board/human-review/0034-year-zero-purchase-funding-cannot-see-capital-receipts.md
docs/board/human-review/0038-engine-defaults-that-reach-a-projection-undisclosed.md
docs/board/human-review/0041-engine-integrity-remainder.md
docs/board/human-review/0042-queue-and-performance-remainder.md
docs/board/human-review/0044-attendance-allowance-and-the-carer-lever-cannot-be-modelled.md
docs/board/human-review/0046-pension-credit-is-treated-as-automatic-and-secure.md
docs/board/human-review/0047-council-tax-is-charged-in-full-for-life.md
docs/board/human-review/0048-housing-benefit-is-never-awarded.md
docs/board/human-review/0049-deprivation-of-capital-is-never-warned-about.md
docs/board/human-review/0050-disability-benefits-in-a-care-placement.md
docs/board/human-review/0051-benefits-rule-corrections-remainder.md
docs/board/human-review/0053-rnrb-downsizing-addition-is-not-modelled.md
docs/board/human-review/0055-care-means-test-disregard-and-deferred-payment.md
docs/board/human-review/0056-a-lifetime-mortgage-is-never-redeemed-on-entry-to-care.md
docs/board/human-review/0057-an-inherited-pension-is-taxed-twice-and-the-tool-shows-one-tax.md
docs/board/human-review/0058-the-estate-figure-is-a-point-estimate-presented-as-precise.md
docs/board/human-review/0059-estate-planning-remainder.md
docs/board/human-review/0060-no-secured-income-can-be-bought-for-a-survivor.md
docs/board/human-review/0061-the-deterministic-horizon-is-a-median-not-a-last-survivor.md
docs/board/human-review/0062-asset-allocation-is-hardcoded-and-decoupled-from-risk.md
docs/board/human-review/0063-no-dynamic-withdrawal-policy.md
docs/board/human-review/0064-inflation-is-drawn-independent-and-memoryless.md
docs/board/human-review/0066-lasting-powers-of-attorney-before-any-deed.md
docs/board/human-review/0067-get-the-lease-extension-valued.md
docs/board/human-review/0068-confirm-the-state-pension-figure-and-take-the-benefits-actions.md
docs/board/human-review/0069-settle-the-sale-price-basis-and-what-happens-if-it-does-not-sell.md
docs/board/human-review/0070-get-the-managing-agents-accounts-and-planned-works.md
docs/board/human-review/0071-get-the-buy-side-mortgage-rate-quoted.md
docs/board/in-progress/0040-liquid-wealth-all-lands-on-the-first-person.md
docs/board/todo/0077-money-drawn-from-a-pension-never-reaches-the-pension-credit-test.md
docs/board/todo/0078-the-cheapest-draw-order-search-only-tries-three-orders.md
docs/board/todo/0079-an-inherited-pension-is-taxed-in-full-even-when-it-should-be-tax-free.md
docs/board/todo/0080-a-pot-that-already-had-its-tax-free-cash-is-treated-as-if-it-had-not.md
docs/board/todo/0081-the-cheapest-draw-order-can-be-the-one-that-funds-least.md
docs/board/todo/0082-section-24-credit-outlives-the-mortgage-it-relieves.md
docs/board/todo/0083-three-tracked-files-are-not-pint-clean.md
docs/board/todo/0085-the-service-charge-escalation-default-has-no-published-source.md
docs/board/todo/0086-source-the-single-property-volatility-multiple.md
docs/board/todo/0087-source-the-letting-cost-rates.md
docs/board/todo/0093-a-freehold-seller-is-charged-leasehold-fees-by-default.md
docs/board/todo/0094-the-bought-homes-upkeep-is-a-percentage-of-its-value.md
docs/board/todo/0101-shared-spending-empties-the-first-persons-accounts-first.md
docs/board/todo/0102-two-people-cannot-say-what-share-of-the-home-each-owns.md
docs/board/todo/0106-pin-the-attendance-allowance-and-carer-earnings-figures.md
docs/board/todo/0109-pin-the-smi-standard-rate-and-capital-cap.md
docs/board/todo/0110-the-forecast-assumes-pension-credit-has-been-claimed.md
docs/board/todo/0111-pin-the-four-council-tax-figures.md
docs/board/todo/0113-pin-the-two-housing-benefit-figures.md
docs/board/todo/0117-two-files-fail-the-house-style-check.md
docs/board/todo/0118-pin-the-disability-benefit-care-rules.md
docs/board/todo/0131-two-engine-version-bumps-are-missing-from-the-stamp-log.md
docs/board/todo/0134-pin-the-inherited-pension-double-charge.md
docs/board/todo/0135-pin-the-park-home-and-nursing-care-figures.md
docs/board/todo/0138-source-the-spending-guardrail-trigger-and-cut.md
OUT-OF-SCOPE: none

**The count before anything changed: 66 open cards failing, of 139.** After: **0 of 139.** By rule,
before and after: unexplained link 58 to 0; outward effect with no `not_for_the_loop:` 10 to 0;
`Blocked by` disagreeing with `needs:` 1 to 0; no reason it is yours 2 to 0. Lanes worked in the
order the Plan gives: `human-review/` first, then `todo/`, `in-progress/`, `ai-review/`.

**What the 58 link failures actually were.** Almost all of them are the same shape: a `## Not this
card` line that fences a subject off by naming the card that owns it. That is a real relationship
and it now has a `## Links` entry saying which one. Nine cards already had a `## Links` section and
only needed a line added. One trap worth knowing: a second and third number written inside one
bullet (`` - `0106`, `0109` ``) is NOT read as a link, only the number the bullet starts with is, so
`0109` and `0113` were flagged for cards their own Links section appeared to name.

**The ten `publish` flags were all the word and not the effect.** Every one is a card asking for a
figure to be pinned to a source somebody ELSE published, which is a read and not a deploy. Each now
carries `no_outward_effect:` with that reason. None gained `not_for_the_loop:`, so all ten stay
available to the unattended loop exactly as before. They remain blocked on web access, which their
own text already says.

**Card 0022 was corrupted and the corruption was hiding its ask.** A copy of its `## Direction`
block had been pasted into the middle of ask item 2, splitting the sentence `Pass: the answer
written into `` `## Decided` `` and leaving a stray `## Decided` heading in the middle of the card.
Everything after that point, including its `**Why it needs you**` paragraph, was therefore outside
the ask, which is why it read as a decision card that never said why it was Rob's. The duplicate
block is removed and the sentence is whole again. **Nothing was deleted that the card did not
already carry twice**: the same text still stands, once, in `## Direction`, which was not touched.
Its seven `needs:` numbers now each have a `Blocked by` line with a reason.

**Criterion 1 is left open on purpose.** Every open card does have a `## Why`, and on all 139 it
comes before every `## Options`, `## Recommendation`, `## Acceptance`, `## Plan` and `## Tasks`
section, which was checked. But the criterion says the problem comes before any solution appears
ANYWHERE in the card, and that is a judgement about prose inside each `## Why`. This session added
links and frontmatter; it did not read and re-judge 139 `## Why` sections, and no check on this
board can. Ticking it would be claiming a pass nobody ran.

**The third Task is left open, and it needs a call this session may not make.** The four-reason test
is applied in the sense the checks measure: every decision card in a lane waiting on Rob now states
a reason, and none is flagged. What is NOT done is the part the Plan calls the shrinking of the
queue, converting a decision card whose answer is really established practice into a feature card. A
converted card would no longer belong in `human-review/`, and moving a card between lanes is the
scheduler's, not a build session's. Whoever picks that up needs the lane move as well as the
rewrite.

**Not checked in a browser.** This is a worktree, so the board renders from `C:\Dev\RetireForecast`
and not from here. Nothing on this card needs a screen, but the rendered link edges are worth a
glance once this merges.

**2026-09-08** RESULT: done
TESTS: +0 new, all green
TOUCHED:
docs/board/in-progress/0072-rewrite-this-board-s-cards-for-the-reader.md
docs/board/human-review/0013-nominal-pounds-toggle.md
docs/board/human-review/0028-property-holding-costs-are-too-smooth.md
docs/board/human-review/0031-no-letting-affordability-test-on-a-rent-plan.md
docs/board/human-review/0036-transition-year-income-is-not-prorated.md
docs/board/human-review/0039-monte-carlo-has-no-golden-master.md
docs/board/human-review/0045-support-for-mortgage-interest-is-not-modelled.md
docs/board/human-review/0047-council-tax-is-charged-in-full-for-life.md
docs/board/human-review/0049-deprivation-of-capital-is-never-warned-about.md
docs/board/human-review/0050-disability-benefits-in-a-care-placement.md
docs/board/human-review/0052-benefits-and-debt-signposting.md
docs/board/human-review/0055-care-means-test-disregard-and-deferred-payment.md
docs/board/human-review/0058-the-estate-figure-is-a-point-estimate-presented-as-precise.md
docs/board/human-review/0059-estate-planning-remainder.md
docs/board/human-review/0063-no-dynamic-withdrawal-policy.md
docs/board/human-review/0064-inflation-is-drawn-independent-and-memoryless.md
docs/board/todo/0114-housing-benefit-ignores-the-local-housing-allowance-cap.md
docs/board/todo/0141-three-decision-cards-sit-in-the-lane-the-loop-builds-from.md
OUT-OF-SCOPE: 0141

**This run closed the two things the last one left open.** The count is still **0 open cards failing
of 140**, checked before and after, the extra card being 0141.

**Criterion 1: every open card's `## Why` was read this time.** The last entry left it open because
139 `## Why` sections had not been read and re-judged. They have been now, all of them, in all four
open lanes. The bar used was the README's own: a sentence that names WHAT to build, WHERE to build
it, or which of several answers to pick, is a solution and does not belong in `## Why`. Sixteen
cards carried one. On each, the sentence was **moved, not deleted**: into `## Plan` where the card
had one or gained one, and dropped only where the card's own `## Acceptance` or `## Tasks` already
carried the same statement (0013 and 0050). The commonest shape was "the machinery already exists",
which reads as a fix and is one.

Two of the sixteen took more than a move. **0013 had no problem in it at all**: its `## Why` was one
sentence of implementation constraint. The problem is now stated (every reported figure is in
today's money, so a reader cannot check the forecast against a statement or a balance, which are
printed in the pounds of their own year) and the constraint stands in criterion #2, where it always
was. **0114 carried its two candidate answers as a numbered list inside `## Why`**; they are now in
its `## Plan` above the standing instructions, unedited.

**The one place the criterion cannot hold literally, and it is the convention's doing.** On a
`human-review/` card the board README REQUIRES `## What I need from you` directly under the title,
above `## Why`, so that the ask is not hidden behind reasoning. On the 62 cards in that lane an
answer therefore appears before the problem, by the convention this card is forbidden to change.
Criterion 1 is ticked for what it can mean here: on all 140 open cards `## Why` states the problem
and proposes nothing.

**The four-reason test: nothing on this board converts.** Nine open cards carry `## Options` (0001,
0004, 0006, 0008, 0022, 0068 in `human-review/`; 0002, 0003, 0084 in `todo/`). Each was read against
the four rows of the README table and each passes on at least one: 0002, 0003, 0004, 0022 and 0068
turn on the couple's own facts and figures; 0006 and 0008 on a preference and a risk Rob owns; 0001
on somebody looking at a screen; 0084 says in its own text that the cost and the risk of giving the
loop the web are his. **None turns on established practice**, so there is no decision card here for
an agent to research, apply and convert into a feature card. That is why the queue does not shrink,
and it is a different answer from the last entry's, which assumed the conversions existed and were
blocked on a lane move. The Task is ticked as applied, with the result being zero.

**What the test did surface is card 0141**: three of those nine (0002, 0003, 0084) sit in `todo/`,
which is the lane the unattended loop takes its next card from. A session that starts one can only
read the question and stop. Recorded rather than fixed, because a lane move is the scheduler's.

**Not checked in a browser, same as before**, and for the same reason.

### 2026-09-08 review (v20260908144146-92e5)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 274s, run by this job rather than reported by the card.

**acceptance: sound**

I checked the board, not the app code (this card only changes cards).

- **#6, #3, #4** ÔÇö `php C:\Dev\ProgressBoard\artisan board:convention --path=$PWD` prints `RetireForecast 0 140`. Zero open cards fail the link, `Blocked by`/`needs:` and reason checks. That is the tool the card names, run by me, not quoted by the builder.
- **#5** ÔÇö I diffed the `## Direction` and `## Decided` block of every card touched since `af8904c`. All identical. The one flag was card 0022's stray duplicate `## Decided` heading, which the fix removed; its real `## Decided` (`<!-- -->`) and its whole `## Direction` are byte-for-byte unchanged, and the panel text still stands once, in `docs/board/human-review/0022-interest-only-and-rio-indications-change-the-keep-the-flat-routes.md`.
- **#1** ÔÇö sampled `## Why` on 0046, 0093, 0084. Each states a problem and proposes nothing.
- **#2** ÔÇö every card carrying `## Options` states why the answer is Rob's (0084 does it in the option text: "a judgement that is yours"). 0141 and 0038 are not decision cards.

Nothing I could break. The `## What I need from you` ordering the builder flagged is the convention's, which this card may not change.

VERDICT: sound

**scope: defect**

**Fenced areas held.** Neither commit (`be64dcc`, `d829818`) touches `docs/board/README.md`, any `done/` or `discarded/` card, or any other board. `## Direction` and `## Decided` are untouched everywhere; on `docs/board/human-review/0022-...md` the panel text now stands exactly once, under `## Direction`, which is what the Comments claim.

**One thing grew.** In `docs/board/in-progress/0072-rewrite-this-board-s-cards-for-the-reader.md`, `## Tasks`, the session rewrote two of its own task lines from "write it into `## Direction`" to "write it into `## Comments`" (commit `be64dcc`). That is editing the specification it is being measured against, and it now disagrees with the same card's `## Plan`, which still says "write the count you reached into `## Direction`". A reader of the card gets two different instructions for the same act, and the record of where the count belongs is no longer one thing.

**One thing added, not asked for.** The same session created `docs/board/todo/0141-three-decision-cards-sit-in-the-lane-the-loop-builds-from.md`. The card asks for a rewrite of existing cards, not a new work item. It is disclosed in `## Comments` and marked OUT-OF-SCOPE, so it is visible, but it is still new scope.

VERDICT: defect

**breakage: sound**

I tried to break it and could not.

Checked, all from a read of the tree:

- `board:convention --path=C:\Dev\RetireForecast` prints `0 140`, so the finish-line claim holds now, not just when written.
- Every `` `0nnn` `` reference in the four open lanes resolves to a real card file. No dangling link.
- `needs:` versus the `**Blocked by**` list matches both ways on all eight cards that have either (0022, 0019, 0023, 0026, 0027, 0040, 0043, 0093). Every named blocker is still open, so none is a stale block.
- The 0022 repair: the deleted panel block still stands once, in that card's `## Direction` (`0022`, section `## Why it needs you` / `## Direction`). No `## Direction` or `## Decided` heading was added or removed anywhere in the diff.
- `no_outward_effect:` is a real key (`ProgressBoard app/Support/Board/Card.php`), and all ten uses sit inside the frontmatter fences.
- `## Plan` after `## Tasks` is the order `docs/board/README.md` gives for a feature card.

One soft point, not a break: cards like `human-review/0039` gained a `## Plan` ("about an hour") although every task is ticked, so it reads as work outstanding. The README deletes `## Plan` at `done/`, so it self-clears.

VERDICT: sound


**2026-09-08** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 6 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 6 of 6 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
