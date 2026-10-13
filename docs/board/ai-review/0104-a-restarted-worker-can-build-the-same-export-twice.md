# A restarted worker can build the same export twice

## Why
`RunScenarioSimulation` and `RunLeverThreshold` now carry `WithoutOverlapping` on their record id,
so a worker restarted mid-run cannot put a second copy of the same run beside the first (card 0042).
`BuildScenarioExport` was not in that card's scope and still carries none.

It has the same shape and the same exposure. It holds a user id, it runs for up to its full hour
(`$timeout = 3600`), and `ScenarioExport` reports progress and writes files for that user as it goes.
A worker restarted while an export is being built picks the still-reserved job up and starts a
second build for the same user: two passes writing the same output and moving the same progress
counter, and whichever finishes last decides what the dashboard shows.

The retry window no longer makes that likely, because card 0042 raised `DB_QUEUE_RETRY_AFTER` past
every job timeout. It does not make it impossible: a worker killed and restarted still re-reads a
job whose lease has run out, and `--force`/`queue:restart` paths reserve on their own schedule.

## Links

**Relates to**
- `0042` - added `$timeout`, `$tries` and `WithoutOverlapping` to the two forecast jobs, and raised
  the retry window above them. This is the third long-running job, which that card did not name.

## Not this card
The forecast jobs themselves, `RunAssistantTurn` (five minutes, and a duplicate answer is not a
corrupted artefact), and any change to the retry window.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a worker restarts while an export is building, THE APP SHALL NOT build the same user's export twice at once. proves: `test_a_restarted_worker_cannot_build_the_same_export_twice_at_once`
- [x] #2 WHEN two different users export at the same time, THE APP SHALL build both. proves: `test_two_users_exports_do_not_block_each_other`
<!-- AC:END -->

## Tasks
- [ ] `middleware()` on `BuildScenarioExport` returning `WithoutOverlapping` on the user id,
      `dontRelease()`, expiring after the job's own timeout
- [ ] Extend `Tests\Feature\Queue\QueuedRunSafetyTest` rather than starting a second file: its
      data provider already runs the overlap pair over a list of job classes

## Plan
Work in `C:\Dev\RetireForecast`; run `php artisan test` from PowerShell (PHP is Laravel Herd and is
not on the Git Bash PATH).

`tests/Feature/Queue/QueuedRunSafetyTest.php` already holds the pattern: it drives the job's own
middleware through a pipeline and nests a second attempt inside the first, which is how a
concurrent worker is expressed without a worker. Windows has no `pcntl`, so nothing here can be
proved by running one.

## Comments

**2026-10-13** RESULT: done
TESTS: +2 new, all green
TOUCHED: app/Jobs/BuildScenarioExport.php
tests/Feature/Queue/QueuedRunSafetyTest.php
OUT-OF-SCOPE: none

BuildScenarioExport now has middleware(): WithoutOverlapping on the user id, dontRelease(), expireAfter($timeout + 60), the same shape as the two forecast jobs. The two proves: tests sit in QueuedRunSafetyTest and reuse its existing overlap pair with BuildScenarioExport::class. #1 was watched failing first ("Two workers ran BuildScenarioExport ... 2 is identical to 1"). #2 was green before the fix as well as after: with no middleware nothing blocks, so it is a guard that the lock is per user and not global, and it cannot fail before a lock exists. As the card says, no real worker runs here (no pcntl on Windows); the proof is the job's own middleware driven through a pipeline. No browser check is needed or done.
