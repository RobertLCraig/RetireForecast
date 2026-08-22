# Optional refinements to built features

## Why
All flagged v1 limits, recorded in DATA-MODEL "Known divergences" and docs/build/PLAN.md. None
is a correctness gap; each is a known simplification to pick off by value.

## Not this card
Anything that is a correctness gap. There is no OPEN correctness gap from the adviser-parity
sweep remaining.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHERE a refinement below is built, THE APP SHALL remove its corresponding entry from
      DATA-MODEL "Known divergences" in the same change.
<!-- AC:END -->

## Tasks
- [ ] Care flags: age-conditioning of the onset rate
- [ ] Care flags: sex split of care *duration* (probability is already sex-differentiated)
- [ ] Means-test v1: Pension Credit counted into the contribution
- [ ] Means-test v1: LA-versus-self-funder fee gap
- [ ] CGT deemed-occupation absences
- [ ] Annuitisation retirement-month override
