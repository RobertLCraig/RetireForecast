# The fixed DB rate disclosure overstates the 3%-to-5% gap

## Why
`ResultPresenter::assumedFigures()` tells a reader whose DB scheme is on a Fixed increase with no
rate entered: "Over thirty years the difference between them roughly doubles the pension." The
two rates are 3% and 5%. 1.05^30 / 1.03^30 is about 1.78, so a 5% pension ends about three
quarters higher, not double. The sentence states a figure the model does not hold.

Found while building card 0095, which only re-sourced the constants and left this copy alone.

## Links

**Relates to**
- `0095` - sourced the 3% default this sentence sits beside.
- `0035` - wrote the sentence.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN a DB pension on a Fixed basis has no rate entered, THE APP SHALL describe the thirty-year gap between 3% and 5% as roughly three quarters, worked from the two rates rather than restated. proves: `test_an_assumed_fixed_db_escalation_rate_is_disclosed_with_its_value`
<!-- AC:END -->

## Plan
Edit the fixed-rate sentence in `app/Forecast/ResultPresenter.php` (`assumedFigures()`), and extend
the named test in `tests/Unit/Forecast/AssumedFiguresDisclosureTest.php`. No engine change and no
`ENGINE_VERSION` bump.
