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

## Not this card
The care means-test rules themselves, which are card 0055.

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
