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
- [ ] WHEN a plan's newest completed run is a preview and an older full run exists, THE APP SHALL show the full run's odds on the Compare page. proves: `test_compare_reads_the_full_run_not_a_newer_preview`
- [ ] WHEN a plan's newest completed run is a preview and an older full run exists, THE APP SHALL build the threshold pictograph from the full run. proves: `test_the_pictograph_reads_the_full_run_not_a_newer_preview`
<!-- AC:END -->

## Plan
Add a mode-filtered lookup (or a `?SimulationMode` argument to `latestCompletedRun()`) and use it in
the two readers above. Copy the `completedRun()` helper in
`tests/Feature/Livewire/AffordabilityTest.php` for fixtures; it takes a mode.

## Comments
