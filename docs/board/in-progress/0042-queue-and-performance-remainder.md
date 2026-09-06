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
- [ ] #1 WHEN a full simulation is queued, THE APP SHALL allow it to run to completion without being killed by a worker timeout.
- [ ] #2 WHEN a worker restarts mid-run, THE APP SHALL NOT run the same simulation twice concurrently.
- [ ] #3 WHEN the affordability screen re-renders, THE APP SHALL NOT recompute forecasts that have not changed.
<!-- AC:END -->

## Tasks
- [ ] Set `$timeout`, `$tries` and `WithoutOverlapping` on `RunScenarioSimulation` and `RunLeverThreshold`
- [ ] Set `DB_QUEUE_RETRY_AFTER` above the timeout, in `.env` and `.env.example`
- [ ] Request-scoped memo in `ScenarioForecaster`, keyed on scenario id and `updated_at`
- [ ] `#[Computed]` on the affordability and results row assembly
- [ ] Sort once in `moneyPercentiles`, then index
