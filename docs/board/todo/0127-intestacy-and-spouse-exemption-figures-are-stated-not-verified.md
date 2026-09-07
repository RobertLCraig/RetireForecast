# The intestacy and capped-exemption rules are stated, not verified

## Why
Card 0054 put the intestacy rules into the first death, and they reach a projection: every stored
plan that models Inheritance Tax now pays tax on the first death unless a will is ticked. The rules
behind it were written from the statute as the building session understood it, with **no web access
to check any of it**, so nothing in it carries a `verified_on` date. The house rule is that every
tax figure carries a source URL and a verified-on date, and none of these do.

Four things need a primary source before this can be read off:

- The **statutory legacy** of £322,000 on `Iht\InheritanceTaxCalculator::STATUTORY_LEGACY_PENCE`,
  and its start date of 26 July 2023. The engine also FREEZES it rather than uprating it, which is
  the cautious direction but is a modelling choice nobody has checked against how often the fixed
  net sum is in fact reviewed.
- The **split itself**: that a surviving spouse takes the personal chattels, the statutory legacy
  and half the residue, and the issue take the other half.
- The **Northern Ireland figure**, which differs from the England and Wales one. `RegionProfile`
  bundles the two, so a Northern Irish household is currently modelled on the England and Wales
  sum with nothing on any screen to say so.
- The **cap on the spouse exemption** where the recipient is not a UK long-term resident: that it
  stops at the nil-rate band, that the test is long-term residence rather than domicile from
  6 April 2025, and what the election that removes it costs.

## Links

**Relates to**
- `0054` - built the rules this card sources.
- `0122` - the standing sourcing-gap card from the same review.
- `0125` - the same gap for the downsizing addition.

## Not this card
Changing the arithmetic. If the source says the engine has it wrong, that is a new card.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL cite each of the four items above to a primary source with a `verified_on` date. proves: none
- [ ] THE APP SHALL record whether the checked rules match what the engine computes. proves: none
<!-- AC:END -->

## Tasks
- [ ] **Needs web, so not an unattended card.**
- [ ] Pin the legacy to the Administration of Estates Act 1925 s.46 and SI 2023/758.
- [ ] Pin the capped exemption to IHTA 1984 s.18(2) and the long-term-residence rules in FA 2025.
- [ ] Update the constant's docblock and docs/spec/ASSUMPTIONS.md §27 with the date checked.
