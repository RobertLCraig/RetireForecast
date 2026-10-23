# The "Retire 2 years later" what-if carries no carer earnings-limit warning

## Why
Raised by card 0044 (scope review, finding 3). Card 0044 warns on the "How far can we go?"
retirement-age lever that working longer postpones the Pension Credit carer addition, because
Carer's Allowance has an earnings limit. The quick what-if "Retire 2 years later" in `QuickWhatIf::PRESETS`
also extends working life and shows no such warning, so the same interaction is invisible there.

## Links
**Relates to**
- `0044` - the lever warning, `ThresholdPresenter::leverCaveat()`.
- `0108` - the projector does not apply the earnings limit yet.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the "Retire 2 years later" what-if is offered to a household where `ThresholdPresenter::leverCaveat()` would fire, THE APP SHALL show the same carer earnings-limit warning beside it. proves: `test_the_retire_later_preset_shows_the_carer_earnings_limit`
<!-- AC:END -->

## Tasks
- [ ] Reuse `ThresholdPresenter::leverCaveat()` for the retire-later preset rather than restating the wording

## Comments

**2026-10-23** RESULT: done
TESTS: +1 new, all green
TOUCHED: app/Forecast/QuickWhatIf.php
resources/views/components/quick-what-ifs.blade.php
tests/Feature/Forecast/QuickWhatIfTest.php
OUT-OF-SCOPE: none
New QuickWhatIf::caveat() calls ThresholdPresenter::leverCaveat() for 'retire_2_years_later' only. It asks it up to the latest age the preset moves anyone to (each working person's planned age + 2, capped at 80, as retireLater() clamps). The quick-what-ifs component prints it under the button, so the dashboard and the results page both show it.
The test was watched red first: the component rendered no warning at all.
The wording is the lever's own, so it says 'the odds above already take it off', which reads oddly beside a button. The card asked for the same warning and no restated wording, so I did not change it. Worth a look in review.
Not seen in a browser (worktree; Herd serves C:\Dev\RetireForecast).
