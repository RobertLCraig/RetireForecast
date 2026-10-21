# Compare and the threshold explorer can show odds from a 1,000-path preview

## Why
`Scenario::latestCompletedRun()` filters on status only, never on mode. `SimulationRunner::preview()`
stores a 1,000-path preview as a `Done` run. So the newest preview wins over an older 10,000-path
full run in every reader of `latestCompletedRun()`.

Card 0010 #3 fixed this for the `/afford` hero only (`Affordability::storedMonteCarlo()` now asks for
`SimulationMode::Full`). Two other readers still take whatever finished last:
- `CombinationComparisonData::assemble()` feeds the Compare page's "how sure" figure.
- `ThresholdExplorer::pictograph()` feeds the threshold pictograph.

A preview has about ten times the sampling noise of a full run, and neither screen says which it is.

Not checked: whether the results page should also prefer a full run. It is where previews are made,
so a preview there may be intended.

## Links

**Relates to**
- `0010` - fixed the same fault on `/afford`; its `storedMonteCarlo()` is the pattern to copy.

## Not this card
- The results page and the PDF (`ScenarioResults`, `ScenarioReport`), until someone decides whether a
  preview is meant to show there.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN a plan's newest completed run is a preview and an older full run exists, THE APP SHALL show the full run's odds on the Compare page. proves: `test_compare_reads_the_full_run_not_a_newer_preview`
- [x] WHEN a plan's newest completed run is a preview and an older full run exists, THE APP SHALL build the threshold pictograph from the full run. proves: `test_the_pictograph_reads_the_full_run_not_a_newer_preview`
<!-- AC:END -->

## Plan
Add a mode-filtered lookup (or a `?SimulationMode` argument to `latestCompletedRun()`) and use it in
the two readers above. Copy the `completedRun()` helper in
`tests/Feature/Livewire/AffordabilityTest.php` for fixtures; it takes a mode.

## Comments

**2026-10-21** RESULT: done
TESTS: +2 new, all green
TOUCHED: app/Models/Scenario.php
app/DecisionSupport/CombinationComparisonData.php
app/Livewire/ThresholdExplorer.php
tests/Feature/DecisionSupport/CombinationComparisonGateTest.php
tests/Feature/DecisionSupport/ThresholdExplorerTest.php
OUT-OF-SCOPE: none

`Scenario::latestCompletedRun()` now takes an optional `?SimulationMode`; with none it behaves as before, so the results page, the PDF and the assistant are unchanged (the card leaves them open). `CombinationComparisonData::assemble()` and `ThresholdExplorer::pictograph()` pass `SimulationMode::Full`.

Both tests were watched failing first: Compare's row sortKey was not 0.92, and the pictograph showed 4 of 10 (the 0.37 preview) instead of 9 of 10. Each test makes the full run, travels one minute, then makes the preview, so `latest()` has a strict order to pick from. The Compare test's helper in CombinationComparisonGateTest gained a mode argument (default Full, so the existing tests are unchanged); ThresholdExplorerTest got its own small completedRun helper.

A plan with only a preview now shows no odds on Compare or in the pictograph, and each screen invites a run, the same as /afford since card 0010 #3. Built in a worktree, so not seen in a browser.
