# The survivor-annuity lever may no longer be monotone

## Why
Found building card 0065 #1. `SurvivorAnnuityFractionLever::direction()` says success only rises
with the survivor's fraction, and the threshold search trusts that. It was true while a bigger
survivor's pension cost nothing. Since card 0065 the rate is re-quoted for each fraction, so a
bigger survivor's pension lowers the income while both partners live. Success can now fall as the
fraction rises (a long joint life, a short survivor spell), and a threshold found by assuming
otherwise can be wrong with nothing on screen saying so. The one sweep test,
`test_a_bigger_annuity_survivor_income_does_not_lower_success`, still passes on its fixture, which
proves only that this household is monotone.

## Links

**Relates to**
- `0065` - re-quoted the annuity rate for each survivor fraction, which is what can break the lever's direction.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN the survivor-annuity sweep's success curve is not monotone, THE APP SHALL NOT report a threshold found by assuming it is.
<!-- AC:END -->

## Tasks
- [ ] Build a household whose long joint phase makes a bigger survivor's fraction lower success, and watch the threshold go wrong.
- [ ] Decide how the threshold service handles a non-monotone lever (scan, or report the curve only).
