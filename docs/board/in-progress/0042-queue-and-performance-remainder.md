# Queue safety and forecast performance

## Why
From the expert panel, 2026-08-19 (engineer findings F6, F12, F13). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

**The queued Monte Carlo has no timeout, and the retry window is 90 seconds.**
`RunScenarioSimulation` declares no `$timeout`, no `$tries` and no `WithoutOverlapping`. It works
today **only because Windows has no `pcntl`**, so the worker's 60-second default cannot be
enforced. Anywhere with `pcntl` - CI, Docker, WSL, a Linux deploy, the public release the PRD keeps
the door open for - every full run is killed at 60 seconds and marked failed. And with a 90-second
retry window, a restarted worker picks up a still-reserved job and runs it **concurrently**, so two
runs write results for the same run and fight over progress. Card 0009 is literally about
restarting a worker.

**Nothing is memoised.** `ScenarioForecaster` re-derives everything on every call: decrypt the base,
recursively decrypt the parent, deep-merge the overrides, then run the assembler - four or five
times per call. The affordability screen then loops every stored plan, running several full lifetime
projections and a bisection each. Neither Livewire component uses `#[Computed]`, so any re-render
redoes all of it. With twenty scenarios that is well over a hundred full projections per render.

**Percentiles re-sort the same array.** `Simulator::percentile()` sorts a copy on every call, five
times per year index, three times over for the three fan charts. Sort once, index many.

## Not this card
Persistent forecast caching. Request-scoped memoisation first; measure before building more.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a full simulation is queued, THE APP SHALL allow it to run to completion without being killed by a worker timeout. proves: `test_a_long_run_is_not_killed_by_the_workers_default_timeout`
- [x] #2 WHEN a worker restarts mid-run, THE APP SHALL NOT run the same simulation twice concurrently. proves: `test_a_restarted_worker_cannot_run_the_same_record_twice_at_once`
- [ ] #3 WHEN the affordability screen re-renders, THE APP SHALL NOT recompute forecasts that have not changed. proves: `test_an_unchanged_scenario_is_not_projected_a_second_time`
<!-- AC:END -->

## Tasks
- [x] Set `$timeout`, `$tries` and `WithoutOverlapping` on `RunScenarioSimulation` and `RunLeverThreshold`
- [x] Set `DB_QUEUE_RETRY_AFTER` above the timeout, in `.env` and `.env.example`
- [x] Request-scoped memo in `ScenarioForecaster`, keyed on scenario id and `updated_at`
- [x] `#[Computed]` on the affordability and results row assembly
- [x] Sort once in `moneyPercentiles`, then index

## Comments
**2026-09-06**
RESULT: partial
TESTS: +15 new, all green
TOUCHED:
- app/Jobs/RunScenarioSimulation.php
- app/Jobs/RunLeverThreshold.php
- config/queue.php
- .env.example
- app/Forecast/ScenarioForecaster.php
- app/Providers/AppServiceProvider.php
- app/Livewire/Affordability.php
- app/Livewire/ScenarioResults.php
- packages/finance-engine/src/MonteCarlo/Simulator.php
- tests/Feature/Queue/QueuedRunSafetyTest.php (new)
- tests/Feature/Forecast/ScenarioForecastMemoTest.php (new)
- docs/HANDOVER.md
- docs/board/in-progress/0042-queue-and-performance-remainder.md (this card)
- docs/board/todo/0104-a-restarted-worker-can-build-the-same-export-twice.md (new)
OUT-OF-SCOPE: 0104

**The queue half is done and #1 and #2 are ticked.** Both jobs now declare `$timeout = 3600`,
`$tries = 1`, `$failOnTimeout` and a `WithoutOverlapping` on their own record id, expiring an
hour and a minute out so the lock cannot lapse under a run that is still going. The overlap test
does not run a worker (Windows has no `pcntl`, so nothing here can): it drives the job's own
middleware through a pipeline and nests a second attempt inside the first, which is a restarted
worker picking up a still-reserved job, and it counts two executions before the fix and one after.
The timeout and attempt limit are asserted on the QUEUE PAYLOAD the worker actually reads them
back off, not on the class, so a property renamed out from under the payload still fails.

`DB_QUEUE_RETRY_AFTER` went into `.env.example` at 3900, and the **default in `config/queue.php`
moved from Laravel's 90 seconds to 3900 as well**. That was deliberate and is the load-bearing
half: this worktree's `.env` is a hard link to the one the running app uses and the session rules
forbid editing it, and that file names no `DB_QUEUE_RETRY_AFTER` at all, so only the config default
reaches the machine. `test_the_retry_window_outlasts_the_longest_run` reads both back and fails if
either moves past the other. `BuildScenarioExport` already carried an hour-long timeout inside that
90-second window, so it was exposed to the same fault and is fixed here in passing by the config.

**#3 is NOT ticked, and everything its Tasks asked for is built.** `ScenarioForecaster` now
memoises on the instance, the container hands out one instance per request (`scoped`, which a queue
worker drops between jobs), and the affordability rows plus the results ladder are `#[Computed]`.
Measured on the forecaster's own surface: a second ask for an unchanged plan comes back as the SAME
`ForecastResult` object, so the projection cannot have run again.

The criterion still says "when the screen RE-RENDERS", and a Livewire re-render is a new HTTP
request. A request-scoped memo and a Livewire computed property both start empty in it, so the
screen does recompute. Closing that needs a forecast cache that outlives the response, which this
card's own "Not this card" rules out, so the criterion as written cannot be met by the plan it
carries. **Whether to lift that exclusion is Rob's call**, and it is the one thing this card needs
that the repository cannot settle. Left open rather than ticked under the narrower reading.

The memo key is not the one the Task named. Keying on scenario id and `updated_at` alone is stale
in two ways this repository would notice. `updated_at` is stored to the second, so two saves inside
one second serve the first one's figures; and an admin editing the figures inside a shared
`AssumptionSet` moves every scenario pointing at it while every one of those rows stands still.
The stamp therefore carries the two form-state columns as their **stored ciphertext** (free to
read, and encryption is randomised, so a re-save never collides), the parent chain's same stamp,
and the assumption set WHOLE. The second of those was not theory: it reddened
`SimulationRunnerTest::test_an_edited_assumption_set_misses_the_cache` on the first full run, and
`test_an_edited_assumption_set_retires_the_forecast` now holds it here too.

Three of the six memo tests pass on unmemoised code, because nothing can be stale when nothing is
remembered. They are the guards on the key, not the proof of the memo, and each was watched failing
against a deliberately weakened stamp before being kept.

`#[Computed]` has no test of its own and I could not write an honest one.
`ScenarioForecaster` is `final`, so it cannot be subclassed to count calls, and the counting would
have to sit at the public boundary, where the memo has already absorbed the second call: a test
there would count asks, not projections, and pass whatever `#[Computed]` did. Its one measurable
win is on the results page, where `downloadLadderCsv` is an action that also re-renders and so
built the ladder twice in one request; on affordability nothing asks for the rows twice today, so
it is a guard against that rather than a saving. Covered against breakage by the existing
`ScenarioResultsTest` and `AffordabilityTest`, which fatal if the attribute is not wired.

`Simulator::moneyPercentiles` sorts once and reads five bands off the ordering; `percentile()`
keeps its sorting form for the callers that read one band. No figure moves, and `GoldenMasterTest`
pins that to the penny.

Not seen in a browser: built in a worktree, which Herd does not serve. No screen changed, but the
memo sits under every screen that reads a scenario, so the results, compare and affordability pages
each want one look.

**2026-09-06**
RESULT: partial
TESTS: +0 new, all green
TOUCHED:
- docs/board/in-progress/0042-queue-and-performance-remainder.md (this card)
- docs/board/todo/0105-two-decision-support-classes-rebuild-what-the-forecaster-already-holds.md (new)
- docs/HANDOVER.md
OUT-OF-SCOPE: 0105

A resume run. The queue half was verified as built, the suite is green here, and **#3 is still not
ticked.** No code changed.

**#3 is confirmed blocked on Rob, from a second reading, and the block is now firmer than the last
entry put it.** The last entry argued that a Livewire re-render is a new HTTP request, which a
request-scoped memo cannot span. That is right, and there is a stronger fact beside it: the
affordability screen has **no in-place re-render at all**. Its only interactive element is the
`checkHowSure` button, and that action ends in `redirectRoute()`; `livewire.render_on_redirect`
defaults to false, so Livewire skips the render. Every render of that screen is therefore a fresh
full-page request with an empty memo and an empty computed cache. Reading "re-renders" as a new
request makes #3 need a cache that outlives the response, which this card's own "Not this card"
forbids. Reading it as an in-request re-render makes it describe something the screen never does.
Neither reading can be ticked honestly, and choosing to lift the exclusion is Rob's call. Left open.

**The card's own "measure before building more" is now measured.** On `ScenarioFixture::rich`, one
affordability row (both deterministic ladders plus the sustainable-spend bisection) costs about
**64 ms on a cold forecaster and about 51 ms on a fully warm one**, because the bisection is not
memoised and re-runs its own search either way. The memo therefore saves roughly 13 ms per plan per
request. At twenty plans a second render of the screen would cost about 1.3 s, and persistent
caching would save that. The figures come from a throwaway test on in-memory SQLite, since PHPUnit
is the only harness this worktree can time in; treat them as indicative, not as the real V2
scenario. That is the number the decision needs, so it is recorded here rather than left to be
re-derived.

Raised, not fixed: **0105**. `SustainableSpend` and `AdviceCostComparison` read the housing variant
off the builder state themselves and rebuild the household, the housing action and the whole
three-variant decomposition by hand, where `ProtectionGap` and `CapacityForLoss` read the
forecaster's memoised `variantInputs()`. So two of the four route around the memo this card built,
and the two hand-rolled copies also drop the shared one's `?? $all['stay_put']` fallback. It is a
"one definition, one home" fault rather than a speed one, and no Task here names either class, so it
is a card and not a change.

**2026-09-06**
RESULT: partial
TESTS: +0 new, all green
TOUCHED:
- docs/board/in-progress/0042-queue-and-performance-remainder.md (this card)
OUT-OF-SCOPE: none

A second resume run. No code changed and **#3 is still not ticked.** What this run adds is
verification of the two entries above rather than a third argument for the same conclusion: each
load-bearing claim in them was checked against this tree, and each holds.

- `vendor/retireforecast/finance-engine` here is a **live junction** onto `packages/finance-engine`,
  not the stale copy that has hidden engine edits on this board before. So the sort-once change in
  `Simulator::moneyPercentiles` is the code the suite actually ran, and `GoldenMasterTest` is
  really pinning it.
- `config/queue.php` defaults `retry_after` to 3900 and `.env.example` names
  `DB_QUEUE_RETRY_AFTER=3900`. Both jobs carry `$timeout = 3600`, `$tries = 1`, `$failOnTimeout`
  and a `WithoutOverlapping` on their own record id. Checked wider than the card asked, because the
  config default reaches everything: `RunAssistantTurn` (300s) and `BuildScenarioExport` (3600s)
  both sit inside the 3900 window too, so **no queued job in this app can now be handed to a second
  worker while the first is still inside it.** 0104 remains right about the missing overlap lock on
  the export, which is a different fault from the retry window.
- `livewire.render_on_redirect` is `false` in the shipped package config and this app publishes no
  `config/livewire.php`, so the previous entry's reading of `checkHowSure` is correct: that action
  skips its render.
- The memo stamp was read for **staleness**, not for speed, since a wrong figure costs more here
  than a slow one. Every input the derivation reads is in the stamp: both form-state ciphertexts,
  the tax year, the variant column, the parent chain walked to the same depth
  `Scenario::effectiveBuilderState()` walks, and the assumption set whole. `MEMO_SCENARIOS` evicts
  by insertion order, which can only cost a recompute and can never serve a stale answer.

**The ask #3 needs, stated plainly so it can be answered without re-reading this thread.** A
Livewire re-render is always a separate HTTP request: an action and the render that follows it are
one request, so there is no second render inside one, and both the container-`scoped` memo and
`#[Computed]` start empty in the next. #3 therefore needs a cache that outlives the response, and
this card's own "Not this card" excludes exactly that. Two ways out, and the choice is Rob's:

- **A. Lift the exclusion** and let this card build a persistent forecast cache, keyed on the stamp
  `ScenarioForecaster::stamp()` already computes. It recovers the measured 64 ms per plan per
  render (about 1.3 s on twenty plans) and the stamp is already the invalidation rule it needs.
- **B. Reword #3** to the in-request reading, which is met and proven, and raise the persistent
  cache as its own card now that the measurement exists to justify it.

**B is the recommendation.** The saving is about a second on a screen that has not had its browser
sign-off yet (card 0001), and caching decrypted personal forecasts past the response raises where
they are stored and for how long, which is a bigger question than a performance card should settle
in passing. The number is not small enough to drop, so it wants a card either way.
