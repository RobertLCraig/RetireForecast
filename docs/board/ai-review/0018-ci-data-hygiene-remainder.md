# CI and data-hygiene remainder

## Why
The freshness guardrails already run monthly in CI (the `data-freshness` workflow, which takes
effect on GitHub once pushed). What is left is low-value hardening.

## Not this card
The freshness guardrails themselves, which are done.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL record a tamper-evident hash for each forecast run.
- [x] #2 THE APP SHALL cache forecasts such that an unchanged scenario does not recompute.
<!-- AC:END -->

## Tasks
- [ ] Push so the `data-freshness` workflow takes effect on GitHub
- [x] Tamper-evident run hash
- [x] Forecast caching

## Comments

**2026-08-29** Built both acceptance criteria on `simulation_runs`, mirroring the pair of jobs
`threshold_results` already did for a sweep. Two nullable columns
(`2026_08_29_100000_add_hashes_to_simulation_runs_table.php`):

**#1 the tamper-evident stamp.** `integrity_hash` is an `APP_KEY`-keyed HMAC over what the run
*claims* (scenario, mode, paths, seed, engine + tax-year stamps, inputs hash, frozen assumptions)
and what it *produced* (every variant's decrypted result payload), written inside the same
transaction that persists the results. `SimulationRun::isIntact()` re-derives it, and
`php artisan scenarios:audit` gained check 8, which reports any completed run that no longer
matches, so the command that already gates a release on correct figures now gates it on unaltered
ones. Two judgement calls worth knowing: an HMAC rather than a plain sha256, because a plain hash
can be recomputed by whoever did the editing (that is a checksum against accident, not against
tampering); and the mutable lifecycle columns (status, progress, timestamps, error) are
deliberately **outside** the stamp, because a cancel or a progress tick moves them legitimately and
a guard that cries wolf gets switched off.

**#2 the cache.** `inputs_hash` keys a run on the effective builder form-state, the frozen
assumptions, the engine and tax-year stamps and mode/paths/seed; `SimulationRunner::preview()` and
`dispatch()` return the matching run instead of recomputing. Saving an edited scenario still deletes
its runs (that stays the primary invalidation), so the hash is the belt-and-braces, and it catches
the two things deletion cannot see: an `ENGINE_VERSION` bump, and an admin editing the
assumption-set row a scenario points at (which is why the hash covers the frozen assumptions, not
just the form-state). Real effect: "Re-run all" on Compare no longer queues a fresh 10,000-path run
per plan on every click, and a second click on a results page no longer queues a duplicate beside
the one the worker already holds. `ScenarioCompare::$familyQueued` now counts the runs actually
queued rather than the plans compared, so the count stays true.

**Assumed.** That "forecast run" means the `SimulationRun` record, the thing the schema, the model
docblock and `ThresholdRunner` all already call a run. That an unseeded request (the only kind the
app makes) should hash its seed as `null` rather than as the value randomly drawn, or the cache
would be dead on arrival; an explicitly seeded request still gets its own entry, so reproducibility
is untouched. Also that card 0042's "Not this card: persistent forecast caching" defers that work
*to here*, not away from both: 0042 keeps request-scoped memoisation of `ScenarioForecaster`, which
is untouched.

**Not settled from the repository, for Rob.**
1. **The migration has not been run against the app database.** `.env` in a worktree is a hard link
   to the real one, so migrating would have altered the live Postgres from here. Run
   `php artisan migrate` after this branch merges, before opening the app.
2. **The first `scenarios:audit` after that will report existing completed runs** as carrying no
   integrity stamp (they predate the column). That is the honest reading, because an unstamped
   result cannot be vouched for, and it clears as soon as those scenarios are re-run, which the head of
   the todo queue requires anyway. If it proves noisy, the alternative is to skip unstamped runs
   silently, which I did not take because it hides a real gap.
3. **The push task is left open.** Pushing is gated on Rob's explicit go-ahead (HANDOVER "Branch
   status") and this session is in a worktree the scheduler owns, so the `data-freshness` workflow
   still does not take effect on GitHub. It carries no acceptance criterion.
4. **Nothing here has been looked at in a browser.** Herd serves the app from `C:\Dev\RetireForecast`,
   not this worktree. The Compare "Re-run all" behaviour when every plan is a cache hit is proven by
   test, not by eye.

**Note on the runner.** The card instructions name `.\vendor\bin\pest.bat`; this project has no
Pest, it is PHPUnit. Ran `php artisan test` (whole suite green, one skip: the posture-aware
`BannedPhrasingTest`, which is expected in advice mode) and `.\vendor\bin\pint.bat --dirty`.
