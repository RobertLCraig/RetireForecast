---
not_for_the_loop: whether decrypted personal forecasts may be stored past the response, where, and for how long is Rob's data-handling call
---
# Forecasts are recomputed on every page load

## What I need from you
Say whether a forecast may be kept, decrypted, after the web request that computed it ends. If yes,
say where (the app's cache store, or a private local file like the export zip) and for how long.

## Why
Split out of card 0042 by its 2026-09-29 decision (option B). That card memoises forecasts within
one request only. Every Livewire re-render is a new HTTP request, so the memo and `#[Computed]`
start empty each time and the affordability screen recomputes every plan on each load.

Measured on 0042 (`ScenarioFixture::rich`, in-memory SQLite, indicative only): one affordability
row costs about 64 ms cold. At twenty plans that is about 1.3 s per load.

A persistent cache can key on `ScenarioForecaster::stamp()`, which already changes whenever any
input to the forecast changes. The precedent for holding decrypted figures past a response is
`ScenarioExport::build()`: a private local store, 24-hour expiry, an erase path.

## Links
**Relates to**
- `0042` - built the request-scoped memo and the stamp this would key on.
- `0001` - browser sign-off, which the measured saving should be checked against.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the affordability screen is loaded a second time for unchanged plans, THE APP SHALL NOT project those plans again. proves: `test_an_unchanged_plan_is_not_projected_on_a_second_request`
- [ ] #2 WHEN any input to a plan changes, THE APP SHALL NOT serve the cached forecast. proves: `test_a_changed_plan_misses_the_persistent_cache`
- [ ] #3 WHEN a user is erased or the expiry passes, THE APP SHALL remove their cached forecasts. proves: `test_cached_forecasts_are_removed_on_erase_and_expiry`
<!-- AC:END -->
