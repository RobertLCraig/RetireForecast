---
needs: 0101
---

# All the money lands on whoever was entered first, which changes the care means test

## Why
From the expert panel, 2026-08-19 (engineer finding F8). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Three separate places give money to the first-declared person:

- `HousingComparison::withHousing()` credits the entire sale proceeds of a jointly owned home to
  `persons[0]`.
- The forced-sale branch in `PathProjector` gives 100% of net proceeds to the first living person.
- Annual surplus banks to the first living person, including income the other person generated.

But `careAssessableCapital()` is deliberately **individual**, because that is how the English care
means test works. So the second person goes into care with an artificially empty balance sheet and
is assessed onto local-authority funding far sooner than reality.

Care is one of the two headline risks the tool exists to surface, and the model's answer currently
depends on the order the two people were typed in. It also skews the first-death estate, which is
immaterial for a married couple but material for the cohabiting case the codebase supports.

## Links

**Blocked by**
- `0101` - the funding waterfall still spends the first-declared person's accounts to zero before
  the second's, so the third criterion's care charge keeps moving with typing order until that is
  settled. Nothing else on this card waits on it.

## Not this card
The care means-test rules themselves, which are card 0055.

Whose assets pay for shared spending, which is card 0101 and is what the third criterion waits on.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a jointly owned home is sold, THE APP SHALL split the proceeds between the owners by their ownership shares.
- [x] #2 WHEN a year produces a surplus, THE APP SHALL bank it to the person who generated it where that is attributable, and split it evenly otherwise.
- [ ] #3 WHEN the order two people were entered in is swapped, THE APP SHALL produce the same care charge.
<!-- AC:END -->

## Tasks
- [x] Split sale proceeds by `ownershipShare` in both sale paths
- [x] Attribute banked surplus rather than defaulting to the first living person
- [ ] Test that swapping declaration order leaves the care charge unchanged; it fails today

## Comments

**2026-09-05**
RESULT: partial
TESTS: +5 new, all green
TOUCHED:
- packages/finance-engine/src/Money/PenceSplit.php (new)
- packages/finance-engine/src/Housing/HousingComparison.php
- packages/finance-engine/src/Forecast/PathProjector.php
- packages/finance-engine/tests/Forecast/OwnerAttributionTest.php (new)
- app/Forecast/ScenarioForecaster.php
- docs/DECISIONS.md
- docs/HANDOVER.md
- docs/HANDOVER-ARCHIVE.md
- docs/board/todo/0101-shared-spending-empties-the-first-persons-accounts-first.md (new)
- docs/board/todo/0102-two-people-cannot-say-what-share-of-the-home-each-owns.md (new)
- docs/board/in-progress/0040-liquid-wealth-all-lands-on-the-first-person.md
OUT-OF-SCOPE: 0101, 0102

All three named places now credit the owner rather than the first-declared person, through one new
home for the rule, `Money\PenceSplit`. Its leftover pennies go to the LOWEST person id rather than
the first declared, so no division turns on order. `ENGINE_VERSION` is
`finance-engine/liquid-wealth-split-between-owners` and the stored-scenario re-run is owed; this was
built in a worktree, so nothing has been seen in a browser (no screen changed, so there is nothing
new to look at). `GoldenMasterTest` did not redden and needs no re-pin: its frozen household never
sells, and it retires in the base year and is in drawdown from then on, so it banks no surplus.

**Criterion 3 is NOT met and is left open.** The three fixes make the care assessment order-neutral
in the year care STARTS, which is what the first care year is assessed on, and that is what the new
`test_swapping_the_order_the_household_was_entered_in_leaves_the_first_care_year_unchanged` pins. A
whole care spell still moves: `PathProjector::fundShortfall` walks `$household->persons` in
declaration order when it sells assets to pay the fee, so the first-declared person's cash is spent
to zero before the second's is touched, and that changes the resident's own capital in the years
after the first. Measured on a two-person fixture with equal capital and a three-year care spell,
swapping the order moves the bill from £240,000 to £124,131.78. That is a fourth site of the same
fault, and I did not fix it here because it decides whose assets pay for shared spending, which is a
modelling rule in its own right and would move every stored two-person plan that ever draws down.
It is card **0101**, and it is what criterion 3 now waits on.

**Assumed, because the repository does not say otherwise.** Criterion 1 asks for a split "by their
ownership shares" and there are none. `Property::$ownershipShare` is a single figure meaning the
HOUSEHOLD's beneficial share of a home held with somebody outside it, which `HousingProceeds` already
applies before any of this. So a jointly held home splits EQUALLY, which is the rule
`careAssessableCapital()` was already applying to the equity it does not disregard, and the two now
agree instead of contradicting each other. A couple owning 70/30 still cannot say so: card **0102**.

Criterion 2's "where that is attributable" is read as PROPORTION of the net income each living
member produced (taxable income after tax and NI, investment income, tax-free streams and pension
cash, and a capital receipt in their own name). Spending is deliberately not netted off person by
person, because the engine holds one household expense profile and there is no honest per-person
share of it to subtract; that approximation is flagged in `attributeSurplus`'s docblock rather than
hidden. Pension Credit and the buy-to-let finance-cost reducer carry no name, so a year whose whole
surplus is of that kind splits evenly, which is the criterion's "otherwise" branch and is tested.

All five tests were watched failing against the unfixed engine first, each for the reason its
criterion describes and not for a missing class. The care charge is the instrument in four of them
because it is the only per-person figure `ForecastResult` exposes; two of the four are narrowed to
the first care year for the reason above, and both say so in a comment naming card 0101.

**2026-09-05**
RESULT: blocked
TESTS: +0 new, all green
TOUCHED:
- docs/board/in-progress/0040-liquid-wealth-all-lands-on-the-first-person.md
OUT-OF-SCOPE: none

A second unattended session on this card. It wrote no engine code, because the one thing still open
is criterion 3, and criterion 3 cannot be met inside this card. What it did instead was check the
previous session's diagnosis rather than inherit it, and then make the blocker machine-readable, so
the loop stops re-opening a card it cannot finish.

**The blocker is real, and it was re-measured here.** The criterion-3 fixture already in
`OwnerAttributionTest` does NOT move over a whole care spell, because the couple in it are rich
enough to self-fund every year in either order, so widening its assertion would have proved nothing.
A leaner household does move: two people, no home, one taxable income of 15,000 pounds each, 150,000
pounds of cash each, household spend equal to their combined income, one of them in care from age 88
to death at 90. Swapping the order those two people are declared in moves the care bill the household
bears from **240,000 pounds to 133,030.80 pounds**. The whole of that gap is the funding waterfall:
`PathProjector::fundShortfall` walks `$household->persons` in declaration order inside each bucket,
so the first-declared person's cash is spent to zero before the second's is touched, and the resident
either keeps her capital or loses it depending only on where she was typed.

**Card 0101 is correctly aimed, which was worth proving before waiting on it.** A throwaway patch
splitting each bucket's draw across the living holders in proportion to what they hold, capped at
each balance with a second pass for whoever the first pass filled, made that same fixture identical
in both orders. So the waterfall is the LAST site of this fault, not merely the next one, and
criterion 3 closes when 0101 lands rather than uncovering a fifth site behind it. The patch was
reverted and nothing from it is committed; `PenceSplit::byWeight` already carries the split rule 0101
needs.

**Why it was not just fixed here.** Three reasons, and any one of them is enough. It is a modelling
rule with a genuine alternative, not a tidy-up: a couple planning for care might deliberately spend
the non-resident's money first, and pro rata is a neutral default rather than an obvious one, so it
belongs on a card somebody reviews. It moves every stored two-person plan that ever draws down, which
means an `ENGINE_VERSION` bump, a `MonteCarlo\GoldenMasterTest` re-pin and a DECISIONS entry that
0101 already lists as its own tasks. And building it here would leave 0101 sitting in `todo/`
describing a fault that no longer exists, which an unattended session cannot correct because it may
not edit another card.

**What changed on this card.** `needs: 0101` in the frontmatter and a `## Links` / `Blocked by`
entry naming it, which is the board's own convention and the half the previous session left in
prose. The README is explicit that a blocker named only in a paragraph is one no view can show, and
that the unattended loop will not start a card whose `needs:` is unresolved. That is the intended
behaviour here: this card should wait for 0101 rather than be handed to a third session that finds
the same wall. `## Not this card` gained the matching scope fence.

Criteria 1 and 2 stay ticked and were re-run green (Engine suite, 518 tests). Criterion 3 stays open.
Still built in a worktree and still nothing new to look at in a browser: no screen changed.
