# Restart the stale queue worker

## Why
The queue worker (the background process that runs queued forecasts) dies on every reboot and
nothing restarts it. While it is down, "Re-run all" on Compare queues jobs that never run, and
thresholds, the trade-off map, assistant answers and "Check how sure" sit on spinners. It also
gates the browser sign-off (card 0001) and the database catch-up (card 0161).

## Links

**Relates to**
- `0001` - its browser sign-off needs queued forecasts to finish, so it waits on a running worker.
- `0161` - its re-run of every stored scenario is queued work, so it needs the worker up too.

## Not this card
Making the worker resilient, supervising it, or moving it off the database driver.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the queue worker is running, THE APP SHALL complete an in-app "Re-run all" and
      show refreshed figures without a manual artisan call. proves: manual
- [x] #2 THE APP SHALL produce a completed Monte Carlo fan for at least one stored scenario,
      confirming the queued path works end to end. proves: `test_re_run_all_queues_a_full_run_for_every_plan_compared`
<!-- AC:END -->

## Plan
Attended session only (it starts a process on the machine).
1. In PowerShell from `C:\Dev\RetireForecast`, run in the background:
   `php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=1255 artisan queue:work`
   Pass: it prints `INFO  Processing jobs from the [default] queue.`
2. With Playwright, open <https://retireforecast.test/scenarios/9/compare> and click **Re-run all**.
   Pass: every plan's Monte Carlo card shows a result within a few minutes (about 30 s a plan,
   21 plans) and none is left on "Not simulated yet". Screenshot it to
   `docs/board/attachments/0009-<date>-1.png` and record it here.
Fail: a spinner that never resolves while the worker is printing. Record which plan.

## Comments

**2026-08-22** Everything behind the button is proven headless: `ScenarioCompare::runFullFamily()`
dispatches one run per plan, covered by `test_re_run_all_queues_a_full_run_for_every_plan_compared`,
and a live worker completed run 769 for scenario 9 in 29 s with no artisan command. The 50+
interest-only variants 55 to 58 are `draft`, so Compare excludes them by design. What no pass could
do is the click itself: the builds ran in a worktree Herd does not serve.

**2026-09-30** Triage. No worker running (no `queue:work` process; `jobs` 0 pending, `failed_jobs` 0).

**2026-10-07** Moved to todo/: not Rob's. Starting a local process and clicking a button are agent
work for an attended session (browser looks are agent work, done with Playwright). No money, risk or
preference turns on it. Plan above is the exact steps. Note: "Re-run all" rewrites stored run
results, which is also step 3 of card 0161, so do the two in one sitting, 0161's re-seed first.
