---
not_for_the_loop: needs web to check the statute's percentage method and the band history, which an unattended session cannot reach
---
# The downsizing addition measures an early sale against the wrong band

## Why
`InheritanceTaxCalculator::downsizingAddition` caps the former home's value at the residence band
in force at DEATH. The `ResidenceDisposal` docblock says the sale is measured against the band in
force when it HAPPENED, and the statute (IHTA 1984 s.8FE, as recalled, not checked) works in
percentages of that earlier band: £100,000 for a sale before 6 April 2017, then £125,000, £150,000
and £175,000 from 2020.

While every disposal was one the plan made, in the base year or later, the two bands were the same
frozen figure and this did not matter. Card 0126 lets a reader enter a sale from before the plan, so
a sale in 2016 to 2019 is now reachable. Example, as recalled: a £100,000 home sold in 2016 used all
of that year's band, so the statute restores 100% of the band at death, £175,000. The engine
restores min(£100,000, £175,000) = £100,000. The addition is understated, so the tax is overstated.

Found while building card 0126.

## Links

**Relates to**
- `0126` - made a pre-plan sale reachable.
- `0053` - built the arithmetic.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE ENGINE SHALL measure a former home against the residence band in force at its sale, as the statute's percentage method does, from a cited source with a verified-on date. proves: `test_a_sale_before_april_2017_is_measured_against_the_100000_band`
<!-- AC:END -->

## Plan
Read IHTA 1984 ss.8FA to 8FE on legislation.gov.uk and the HMRC IHT manual (IHTM46000 onwards) for
the worked examples. The band-by-year table belongs beside the calculator with its `source` and
`verified_on`.

## Comments
