# Restart the stale queue worker

## What I need from you

**Start the queue worker, then click "Re-run all" on Compare for scenario 9 and watch every plan
get a fresh result.** Checked 2026-09-30: no worker is running (no `queue:work` process; `jobs` 0
pending, `failed_jobs` 0). It dies with every reboot and nothing restarts it.

1. In PowerShell, from `C:\Dev\RetireForecast`, and leave the window open:
   `php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 artisan queue:work`
   Pass: the window prints `INFO  Processing jobs from the [default] queue.` and stays put.
2. Open <https://retireforecast.test/scenarios/9/compare> and click **Re-run all**.
   Pass: every plan's Monte Carlo card shows a spinner, then a result, within a few minutes (about
   30 s a plan, 21 plans), with no artisan command typed, and no plan is left on "Not simulated yet".

**Fail:** a spinner that never resolves, or a plan with no result. Write which plan here and whether
the worker window was still printing; the card then goes back to `todo/` with that as the finding.
A spinner that never resolves with no worker window open is step 1 not done, not a bug.

**Why it needs you** Step 1 is a process on your machine, outside the repository, and the unattended
loop may not start one (rule 2). Step 2 is the one link in the chain nobody has watched from a
browser, and it is the same click card 0001's sign-off pass makes, so do them in one sitting.

## Why
The queue worker is machine state, not repo state: it dies on reboot, there is no supervisor and no
signal when it is gone. While it is down the in-app "Re-run all" queues jobs that never run, and
thresholds, the trade-off map, assistant answers and the "Check how sure" runs all sit on spinners.
It also gates the browser sign-off (0001).

## Not this card
Making the worker resilient, supervising it, or moving it off the database driver.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the queue worker is running, THE APP SHALL complete an in-app "Re-run all" and
      show refreshed figures without a manual artisan call. proves: manual
- [x] #2 THE APP SHALL produce a completed Monte Carlo fan for at least one stored scenario,
      confirming the queued path works end to end. proves: `test_re_run_all_queues_a_full_run_for_every_plan_compared`
<!-- AC:END -->

## Tasks
- [ ] Start the worker (step 1 above)
- [ ] Trigger "Re-run all" and confirm it completes (step 2 above)

## Comments

**2026-08-22** Three unattended passes, condensed. Everything behind the button is proven
headless: `ScenarioCompare::runFullFamily()` is `plans()->map(fn ($plan) => $runner->dispatch($plan)->id)`,
its map is covered by `ScenarioCompareTest::test_re_run_all_queues_a_full_run_for_every_plan_compared`,
and a live worker completed run **769** for scenario 9 in 29 s from a bare
`SimulationRunner::dispatch()` with no artisan command (10,000 paths, one Result per variant,
`latestCompletedRun()` returns it). All 21 plans Compare shows for base 9 carried a completed
2026-08-22 run and `CombinationComparisonData::assemble()` returned a non-null `mc` for each; the
50+ interest-only variants 55 to 58 are `draft`, so Compare excludes them by design. What no pass
could do is the click itself: the builds ran in a worktree Herd does not serve. The loop then moved
the card here. Pre-existing pint drift in `QuickWhatIf.php` and `SimulationRunner.php` was noted
and left alone; card 0083 carries it.

**2026-09-30** Triage. Re-checked the machine: the 2026-08-22 worker (PID 68992) is gone and
nothing has replaced it, so the August "worker started" tick was reverted. The ask above is now the
exact command and the exact click.
