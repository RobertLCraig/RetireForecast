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

## Comments

**2026-10-17** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card needs a primary source for three things: the 8 July 2015 date, the order of operations (the taper as the ceiling on the home plus the addition), and the cap on the addition. Those are IHTA 1984 ss.8FA to 8FE on legislation.gov.uk and the gov.uk guidance. This unattended session has no web: WebFetch on legislation.gov.uk/ukpga/1984/51/section/8FE was refused for lack of permission. I did not route round the refusal with curl. The card's own first task already says it is not an unattended card. I did not cite the rule from memory, because a figure stated without checking is the exact fault this card exists to fix.

To move it: run it in an attended session with WebFetch allowed, or mark it not_for_the_loop: so the scheduler stops picking it up. Both criteria are prose (proves: none). The work is the docblock on Iht\InheritanceTaxCalculator::DOWNSIZING_DISPOSALS_AFTER_YEAR and docs/spec/ASSUMPTIONS.md section 26, each with a source URL, a verified_on date, and a line saying whether the engine matches. That line also covers the percentage-versus-pence point and the most-recent-disposal choice.
