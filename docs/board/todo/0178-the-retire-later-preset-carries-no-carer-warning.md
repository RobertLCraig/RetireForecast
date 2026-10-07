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
- [ ] #1 WHEN the "Retire 2 years later" what-if is offered to a household where `ThresholdPresenter::leverCaveat()` would fire, THE APP SHALL show the same carer earnings-limit warning beside it. proves: `test_the_retire_later_preset_shows_the_carer_earnings_limit`
<!-- AC:END -->

## Tasks
- [ ] Reuse `ThresholdPresenter::leverCaveat()` for the retire-later preset rather than restating the wording
