---
no_outward_effect: the rule is read from published regulations; nothing here is released
---
# Pension Credit still assesses a couple as one household when one of them is in care

## Why
Found while building card 0181. `PathProjector::pensionCreditAward()` is asked with the count of
living members, so a couple where one partner is in a care home for good is still awarded the
couple guarantee on their joint income. As far as the builder knows (stated from memory, no web
access in that session), the Pension Credit rules stop treating two people as one household once
one of them lives in a care home permanently, and each is then assessed as a single person. If so,
the award in every care year of a couple on Guarantee Credit is wrong, in either direction.

## Links

**Relates to**
- `0181` - found while testing the carer addition in a funded placement.
- `0050` - the care-placement benefit rules.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry the published rule on whether a couple stays one Pension Credit household when one partner is permanently in a care home, with a source URL and a verified_on date, in docs/spec/ASSUMPTIONS.md. proves: manual
- [ ] WHEN the published rule says the couple is assessed as two single people, THE APP SHALL award each partner the single guarantee on their own income from the year the placement starts. proves: `test_a_couple_split_by_a_permanent_care_placement_is_assessed_as_two_singles`
<!-- AC:END -->

## Plan
Needs a session with web access for the source. The code is in
`packages/finance-engine/src/Forecast/PathProjector.php`, `pensionCreditAward()` and its caller.
