# The letting note claims a PRR cut that is not modelled

## Why
Found by the 2026-09-05 review of card 0030, and still true on 2026-10-05. The `letting_caveats`
note in `ResultPresenter::inputNotes()` says time spent let "ARE in the figures" as a cut to
Private Residence Relief on a later sale. Only `everLet` with a `cgtHistory` builds that history,
in `HouseholdAssembler::cgtHistoryFrom()`. Setting `isLet` alone, from the builder checkbox or the
"Let out & rent elsewhere" what-if, builds none. So the result states as modelled a tax cost that
the forecast does not charge.

## Links

**Relates to**
- `0030` - wrote the note.

## Not this card
- How Private Residence Relief is calculated once a CGT history exists.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a let plan has no letting history for Private Residence Relief, THE APP SHALL NOT tell the reader that the relief cut is in the figures. proves: `test_the_letting_note_does_not_claim_an_unmodelled_prr_cut`
<!-- AC:END -->

## Plan
Either say the PRR cut is not modelled unless a letting history is entered, or build the history
from `isLet`. The first is the smaller change; the second is a decision for Rob.

## Comments

**2026-10-21** RESULT: done
TESTS: +1 new, all green
TOUCHED: app/Forecast/ResultPresenter.php
tests/Unit/Forecast/InputNotesTest.php
OUT-OF-SCOPE: none

Took the Plan's first branch (the smaller change; building the history from isLet stays Rob's call). The letting_caveats note now says the Private Residence Relief cut is in the figures only when the home carries a CGT history (Property::$cgtHistory, which only everLet + an entered history with a purchase price builds). Without one it says the Pension Credit consequence is in the figures and the PRR cut is not, and points to the home step. test_the_letting_note_does_not_claim_an_unmodelled_prr_cut was watched failing on the bare isLet plan before the fix, and also pins that a plan with a letting history keeps the claim. No figure moves, so no ENGINE_VERSION bump and no re-run owed. The new sentence is not seen in a browser: the worktree is not what Herd serves.
