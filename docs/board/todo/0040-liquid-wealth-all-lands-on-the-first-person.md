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
- [ ] #1 WHEN a jointly owned home is sold, THE APP SHALL split the proceeds between the owners by their ownership shares.
- [ ] #2 WHEN a year produces a surplus, THE APP SHALL bank it to the person who generated it where that is attributable, and split it evenly otherwise.
- [ ] #3 WHEN the order two people were entered in is swapped, THE APP SHALL produce the same care charge.
<!-- AC:END -->

## Tasks
- [ ] Split sale proceeds by `ownershipShare` in both sale paths
- [ ] Attribute banked surplus rather than defaulting to the first living person
- [ ] Test that swapping declaration order leaves the care charge unchanged; it fails today
