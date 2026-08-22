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
- [x] #2 THE APP SHALL produce a completed Monte Carlo fan for at least one stored scenario,
      confirming the queued path works end to end.
<!-- AC:END -->

## Tasks
- [x] Start the worker (see How to pick up in HANDOVER.md)
- [ ] Trigger "Re-run all" and confirm it completes

## Direction
**2026-08-22** Verified the worker rather than restarting it, because it was already up when this
card was picked up, and confirmed the queued path end to end. Nothing was built: this card is
operational, and resilience/supervision is out of scope by its own "Not this card".

**The worker is live and on the right database.** PID 68992, started 2026-08-22 03:33:02 local.
`handle.exe` on that PID shows its working directory and `artisan` handle are both
`C:\Dev\RetireForecast`, so it runs the tree Herd actually serves, not this worktree, and survives
this worktree being cleaned. Its stdout/stderr are redirected to
`storage/logs/queue-worker-0009.log` here. It is not the stale pre-Postgres worker of the
2026-07-09 gotcha: `php artisan db:show --counts` reports connection `pgsql`, database
`retireforecast`, `jobs` 0 pending and `failed_jobs` 0, and that log records a 22-job batch drained
between 03:33 and 03:44 today, ~28s per job.

**End-to-end proof (AC #2).** Rather than trust the drained batch, I dispatched a fresh full run for
base scenario 9 through `SimulationRunner::dispatch()` — the exact single call
`ScenarioCompare::runFullFamily()` makes per plan. The already-running daemon picked it up and
finished it in 29 seconds with no `artisan queue:*` command from me. Run **769**: status `done`,
progress 100, 10,000 paths, seed 401030566, one `Result` per variant (`stay_put`, `buy_outright`,
`rent`), each carrying a 45-year `fanChart` with real p10/p25/p50/p75/p90 bands (2026 at 10,000
paths narrowing to 2070 at 1 surviving path); `stay_put` full-spend success 0.1850, essentials
0.2102. `Scenario::find(9)->latestCompletedRun()` — the single accessor the results page and the
PDF read — now returns 769, so the figures behind the screen are refreshed.

**Assumed, and disclosed.** `dispatch()` with no seed takes a fresh `random_int` seed, which is
exactly what the in-app button does, so I passed none. Consequence: run 769 wrote a new
`result_snapshot` on scenario 9 and supersedes that plan's run from the 03:33 batch. Both are
ordinary canonical-path runs; no figure was hand-set. I deliberately did **not** re-run the whole
22-plan family — the 03:33 batch already did, and HANDOVER says the comparison should not be read
off until the ranking-mover defects (0024, 0025, 0034, 0035/0036) land, so more churn on Rob's
stored snapshots would buy nothing.

**AC #1 left open, and why.** Everything behind the button is proven — dispatch reaches the worker,
the worker completes it unaided, and the app's own accessor returns the refreshed run. The two
words I cannot honour from here are *in-app*: Herd serves this project from `C:\Dev\RetireForecast`,
not from this worktree, so I cannot click "Re-run all" or see the refreshed figures render. Ticking
it would be reporting a browser check I did not do. **It needs Rob's browser pass** — the same pass
card 0001 already gates on, so it costs nothing extra to fold in there.

**Two things the repository could not settle.** (1) Who dispatched the 03:33 batch. It landed 45
seconds after the worker booted and 3 minutes after the scheduler moved this card to in-progress,
but nothing on disk attributes it, so I have not claimed it as an in-app click. (2) Whether the
worker stays up. "The worker is running" is machine state, not repo state: it dies on reboot, there
is no supervisor, and there is no signal when it is stale — which is what made this card necessary
in the first place. So this card can regress without any code changing. The restart line is in
HANDOVER "How to pick up"; making it self-healing is explicitly a different card.

**Test note.** The instructions named `.\vendor\bin\pest.bat`; this project has no Pest. Ran the
documented commands instead: `php artisan test` (PHPUnit 12) and `vendor\bin\pint.bat`.

**2026-08-22 (second pass)** Resumed this card, changed no code, and closed the one thing the first
pass left genuinely unknown: whether the *whole compared set* is refreshed, not just scenario 9.

**The worker still holds.** Same daemon, PID 68992, up since 03:33:02 local; `pgsql`, 0 pending
jobs, 0 failed jobs, 0 runs in a non-terminal state.

**The 03:33 batch is exactly a "Re-run all" set, and it is complete.** `ScenarioCompare::plans()`
is `CombinationComparisonData::plans()` — the base plus its children whose status is `Ready`. For
base 9 that is **21 plans** of the 25 in the family; the other four (55, 56, 57, 58, the 50+
interest-only variants and the withdrawn RIO) are status `draft`, so Compare excludes them by
design and their older 2026-08-12 runs are not staleness. **All 21 compared plans carry a completed
run from 2026-08-22** — 20 dispatched in one second at 02:33:47 UTC plus run 769 — with no gaps.
That is the plan set `runFullFamily()` builds, one run each, all `done`, which is what a completed
in-app "Re-run all" leaves behind.

**"Refreshed figures" checked at the assembler, not the pixels.** `CombinationComparisonData::assemble()`
— the single call `ScenarioCompare::render()` makes for the table, burndown and Monte Carlo cards —
returns all 21 plans with a non-null `mc`, so no plan falls to the "Not simulated yet" branch and
every figure on that screen is a 2026-08-22 run.

**Why I did not re-trigger the batch.** Task 2 stays open because I did not click it. I chose not to
dispatch a fresh 21-plan family from the component either: the button's own map loop is already
covered with a faked queue (`ScenarioCompareTest::test_re_run_all_queues_a_full_run_for_every_plan_compared`),
`dispatch()` → live worker → `done` is proven by run 769, and the 21 completed runs above show the
composition. It would have produced no new fact and would have overwritten 21 of Rob's stored
snapshots at fresh seeds for nothing.

**AC #1 stays open for the same reason as before, now the only reason.** Every link behind the
button is verified — plan set, dispatch, worker, completion, and the assembled figures the screen
reads. The unverified step is the render itself: Herd serves this project from `C:\Dev\RetireForecast`,
not this worktree, so **this still needs Rob's browser pass**, folded into card 0001.

**2026-08-22 (third pass)** Resumed, changed no code, and found nothing left that this session can
honestly close. Recording that as a result rather than passing the card round again.

**Nothing regressed, and nothing moved on its own.** Same daemon, PID 68992, still up since
03:33:02 local, still the JIT `queue:work` line from HANDOVER "How to pick up". Connection `pgsql`,
database `retireforecast`, 0 pending jobs, 0 failed jobs, no run in a non-terminal state (120 `done`,
1 `cancelled`). Latest run is still 769 and `Scenario::find(9)->latestCompletedRun()` still returns
769 — byte-for-byte the state the second pass left. So the second pass's findings still hold and are
not restated here.

**There is no headless fact left to win, and that is now checked rather than assumed.**
`ScenarioCompare::runFullFamily()` is two lines — `plans()->map(fn ($plan) => $runner->dispatch($plan)->id)`.
Its map is covered by `ScenarioCompareTest::test_re_run_all_queues_a_full_run_for_every_plan_compared`,
and `dispatch()` → live worker → `done` is run 769. The composition has no third part. Driving the
component live — against Rob's family or a throwaway one — would exercise no untested line while
overwriting stored snapshots at fresh seeds, so I did not. Task 2 stays open because clicking it is
the one thing left, not because verification was skipped.

**This card is now waiting on a person, not on work.** Its only open criterion needs the two words
*in-app*, and this worktree is not the tree Herd serves, so a browser check cannot be claimed from
here at all — not for want of effort, but by construction. Three passes have each ended at the same
step. Suggest it stops being re-picked as a build card and rides on **card 0001's** browser sign-off,
which is the same click. Nothing in the repository blocks it.

**One exception found, deliberately not fixed.** `vendor\bin\pint.bat --test` fails on
`app\Forecast\QuickWhatIf.php` and `app\Forecast\SimulationRunner.php`. Both are byte-identical to
HEAD with a clean tree, so this is pre-existing style drift inherited from master, not from this
card. Fixing it would put unrelated changes in this card's commit, so it is left alone and flagged
here; it wants its own card. `--dirty` (the documented house command) is clean, because this pass
changed no PHP.

**HANDOVER not touched, on purpose.** This pass changed no code and no repository state. "The worker
is running" is machine state, not repo state — the restart line already lives in HANDOVER "How to
pick up", and the browser gate is already recorded there under 0001. There is no fact here that
HANDOVER does not already own.
