# Restart the stale queue worker

## Why
The queue worker is stale, so the in-app "Re-run all" does nothing and every queued Monte Carlo
silently fails to progress. It also gates the browser sign-off (0001), because thresholds, the
trade-off map, assistant answers and the "Check how sure" runs all need it.

## Not this card
Making the worker resilient, supervising it, or moving it off the database driver.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the queue worker is running, THE APP SHALL complete an in-app "Re-run all" and
      show refreshed figures without a manual artisan call.
- [ ] #2 THE APP SHALL produce a completed Monte Carlo fan for at least one stored scenario,
      confirming the queued path works end to end.
<!-- AC:END -->

## Tasks
- [ ] Start the worker (see How to pick up in HANDOVER.md)
- [ ] Trigger "Re-run all" and confirm it completes
