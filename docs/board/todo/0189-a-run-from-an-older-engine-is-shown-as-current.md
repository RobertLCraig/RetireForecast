# A stored run from an older engine is shown as if it were current

## Why
Found in the 2026-09-08 review of card 0061 and re-confirmed by its 2026-09-28 manager pass.

The results page, Compare and the PDF print captions read from the CURRENT settings and code, for
example `ResultPresenter::planningHorizonBasis()` ("run to the 75th-percentile age at death of the
last of you"). The figures beside a caption can come from a stored `SimulationRun` stamped with an
older `engine_version`. Nothing compares `SimulationRun::$engine_version` with
`ScenarioForecaster::ENGINE_VERSION`, and no view prints it.

So a plan run before card 0061 shows median-lifespan figures under a caption that says one
household in four outlives them. The same holds after every engine bump with an owed re-run (the
HANDOVER lists many). The reader cannot tell a stale run from a fresh one.

## Links

**Relates to**
- `0161` - re-running every stored scenario clears today's stale runs; this card makes the next
  stale run visible instead of silent.

## Not this card
- Re-running stored scenarios (0161).
- Re-running automatically.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a stored run's engine version differs from the current engine version, THE APP SHALL say on the results page that its figures came from an older version of the model and need a re-run. proves: test_a_run_from_an_older_engine_is_flagged_for_a_re_run
<!-- AC:END -->

## Comments
