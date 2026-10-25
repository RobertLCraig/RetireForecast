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
- [ ] #2 THE APP SHALL produce a completed Monte Carlo fan for at least one stored scenario,
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

### 2026-10-25 review (v20261025154516-05b7)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 687s, run by this job rather than reported by the card.

**acceptance: defect**

I checked the one criterion that is ticked: #2. It does not hold.

**#2 claims too much for its test.** The criterion says the app makes a *completed* Monte Carlo fan "end to end". The test it cites is `test_re_run_all_queues_a_full_run_for_every_plan_compared` in `tests/Feature/Livewire/ScenarioCompareTest.php`. That test calls `Queue::fake()` first. So no job ever runs.

The test only checks three things:
- 3 `RunScenarioSimulation` jobs are queued.
- 3 `SimulationRun` rows exist in Full mode.
- `familyQueued` is 3.

It never checks that a run reaches `Done`, and it never checks for a fan result. `ScenarioCompare::runFullFamily()` only dispatches jobs. That proves the jobs go onto the queue. It does not prove the queued path works end to end.

The card's comment about "run 769 finished in 29 s" is prose with no attached evidence. No code or test backs it.

**#1 is `proves: manual` and still open.** That is not a finding. The Plan's screenshot (`docs/board/attachments/0009-<date>-1.png`) is not there yet. That is expected for a manual criterion.

**To fix:** add a test that runs `RunScenarioSimulation` with the real (sync) queue for one stored scenario. It should assert that the run reaches `Done` and that it holds fan percentiles. Or reword #2 so it claims only "queued".

UNMET: #2 the cited test fakes the queue, so it proves jobs are queued but never that any run completes or produces a Monte Carlo fan

VERDICT: defect

**scope: defect**

**Findings (scope lens)**

1. **The diff is far too big for this card.** The card asks for two things only: start a process, then click one button. The diff changes 600 files and adds about 78,000 lines. That includes engine tax rules, benefits, IHT, Monte Carlo, the assistant edit feature, exports, the README and about 200 board cards. None of it is needed to restart a worker. This is the card quietly growing.

2. **It goes over the "Not this card" fence.** The fence says: do not make the worker resilient. Commit `fa903461` ("a queued run gets an hour, a lock on its own record...") changes how the queue copes with failure:
   - `config/queue.php`, `connections.database.retry_after`: the timeout before a stuck job is retried goes from the default to 3900 seconds. It reads `DB_QUEUE_RETRY_AFTER`, which `.env.example` now also sets.
   - The `QueuedRunSafetyTest` test file is new.

   That is resilience work.

3. **Half done.** Plan step 2 needs a click and a screenshot at `docs/board/attachments/0009-<date>-1.png`. Neither is in the diff. That step is criterion #1, which is `proves: manual`, so it stays open and it is not a finding on its own.

These findings do not disprove criterion #2. The test it names still exists, and the suite ran green.

VERDICT: defect

**breakage: defect**

I checked the second rule, #2. It is not proven.

**What I found**

- Rule #2 says the app SHALL make a **finished** Monte Carlo fan (the chart of possible outcomes) for at least one stored plan, end to end.
- The test that is meant to prove it is `ScenarioCompareTest::test_re_run_all_queues_a_full_run_for_every_plan_compared`. It uses `Queue::fake()`. That means no job ever runs.
- The test only checks that 3 jobs go into the queue (`Queue::assertPushed`) and that 3 run rows exist. It never checks that a run finishes or that a fan exists.
- So the test proves that jobs get **queued**. It does not prove that they **complete**.
- The card's comment says "run 769 finished in 29 s". That is a note in prose. No test or saved file backs it.

Rule #1 is a manual check, so I did not judge it.

UNMET: #2 the named test fakes the queue, so it only proves jobs are dispatched and never shows any run finishing with a Monte Carlo fan.

VERDICT: defect

**acceptance**

- **#2 reopened**, by the acceptance lens: the cited test fakes the queue, so it proves jobs are queued but never that any run completes or produces a Monte Carlo fan
- **#2 was named by the breakage lens and is not a ticked criterion here**, so nothing was changed: the named test fakes the queue, so it only proves jobs are dispatched and never shows any run finishing with a Monte Carlo fan.

