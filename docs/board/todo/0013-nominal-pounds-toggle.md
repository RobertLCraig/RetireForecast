# Nominal-pounds toggle

## Why
Deferred from slice #3 of PLAN-output-inflation-and-charts.md. It needs the engine's internal
pre-deflation figures exposed rather than a presenter re-inflation, because re-inflating in the
presenter would drift from the engine's own numbers.

## Not this card
The wealth chart's terminal p25/p75, also still open from slice #3.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL offer a nominal-versus-real toggle on the results figures.
- [ ] #2 THE APP SHALL take nominal figures from the engine's own pre-deflation values, never
      by re-inflating a deflated figure in the presenter.
<!-- AC:END -->

## Tasks
- [ ] Expose pre-deflation figures on the engine DTOs
- [ ] Toggle on the results page
- [ ] Assert the two paths agree on a known case
