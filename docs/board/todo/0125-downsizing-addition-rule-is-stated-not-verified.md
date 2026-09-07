# The downsizing-addition rule is stated, not verified

## Why
Card 0053 built the Inheritance Tax downsizing addition, and it reaches a projection: every stored
plan that sells its home now pays different Inheritance Tax. The rule behind it was written from
the statute as the building session understood it, with **no web access to check it**, so nothing
in it carries a `verified_on` date. The house rule is that every tax figure carries a source URL
and a verified-on date, and this one does not.

Three things need a primary source before this can be read off:

- The **8 July 2015** disposal date on
  `Iht\InheritanceTaxCalculator::DOWNSIZING_DISPOSALS_AFTER_YEAR`.
- The **order of operations**: that the tapered allowance is the ceiling on the qualifying home
  plus the addition together, rather than the taper being applied to either part separately. Card
  0053's criterion #3 turns on this and it is worth six figures on an estate near the threshold.
- The **cap** on the addition: that it is limited to the value of assets OTHER than the home
  passing to direct descendants, and what "closely inherited" covers.

Also worth checking against the source: the engine subtracts pence where the statute works in
percentages of the maximum band at each date, which is only equivalent while one frozen band is in
force for a whole run; and where a plan disposes of more than one home the engine takes the most
recent disposal, where the statute lets personal representatives choose.

## Links

**Relates to**
- `0053` - built the rule this card sources.
- `0122` - the standing sourcing-gap card from the same review.

## Not this card
Changing the arithmetic. If the source says the engine has it wrong, that is a new card.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL cite the downsizing addition to a primary source with a `verified_on` date. proves: none
- [ ] THE APP SHALL record whether the checked rule matches what the engine computes. proves: none
<!-- AC:END -->

## Tasks
- [ ] **Needs web, so not an unattended card.**
- [ ] Pin the three items above to IHTA 1984 ss.8FA to 8FE and the gov.uk guidance.
- [ ] Update the constant's docblock and docs/spec/ASSUMPTIONS.md §26 with the date checked.
