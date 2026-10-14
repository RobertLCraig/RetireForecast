---
no_outward_effect: "published" names the legislation somebody else printed, read here; nothing is published by us
---

# Pin the two pension-age Housing Benefit figures to a published source

## Why
Card 0048 gave a pension-age renter Housing Benefit, and valued property capital the way the means
test values it. Two figures do that work, both statutory, and neither was read off the legislation:

- **The Housing Benefit taper, 65% of income above the applicable amount**
  (`Benefits\HousingBenefit::TAPER_BPS`), together with the two rules beside it: the maximum award
  for a household on Guarantee Credit, and nil above the £16,000 capital limit.
- **The notional costs of sale, 10% of a property's market value**
  (`Benefits\CapitalAssessment::NOTIONAL_SALE_COSTS_BPS`), deducted from the value before anything
  secured on it, when property is assessed as capital.

The RULES are stated correctly in each docblock, with the SI named. The VALUES are the building
session's own knowledge of that legislation, and the citation in
[docs/spec/ASSUMPTIONS.md](../../spec/ASSUMPTIONS.md) section 24 is an unvisited URL.

What it costs: both move the answer, and they move it for the household the whole rent-versus-buy
comparison turns on. The taper decides how far above the guarantee help reaches, which on a
£15,000 rent is several thousand pounds a year across the tail of a plan. The 10% decides when a
household holding property it does not live in crosses the £16,000 cliff.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0048 could ship and disclose the figures but could not go and check them.

## Links

**Relates to**
- `0048` - awarded the benefit, and introduced both figures with the disclosure that reads them.
- `0106`, `0109`, `0111` - the same shape of gap, from the same missing web access. Whoever picks
  one up can settle several in one research pass.
- `0114` - the Local Housing Allowance cap, which is the rule these figures feed and is a card of
  its own.

## Not this card
The mechanism. Who qualifies, in what order the tests apply, and how the award comes off the rent
are card 0048's and are built. The Local Housing Allowance cap is card 0114.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for both figures in docs/spec/ASSUMPTIONS.md section 24, or record there that the search found none. proves: manual
- [ ] WHEN a published figure differs from the shipped one, THE APP SHALL use the published one, and the rent charged, the reported award and the result note SHALL all move with it. proves: `test_housing_benefit_tapers_away_above_the_applicable_amount`
<!-- AC:END -->

## Tasks
- [ ] Read the Housing Benefit (Persons who have attained the qualifying age for state pension
      credit) Regulations 2006 and confirm the 65% taper, the Guarantee Credit passport and the
      capital limit.
- [ ] Confirm the 10% notional sale-costs deduction and the order it applies in (off the value,
      before the secured debt).
- [ ] Delete the "SOURCING GAP" block on `HousingBenefit` once pinned, and move ASSUMPTIONS
      section 24 out of the sourcing-gap list.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if either figure moves, and note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Two files hold both
figures: `packages/finance-engine/src/Benefits/HousingBenefit.php` and
`packages/finance-engine/src/Benefits/CapitalAssessment.php`. Nothing restates them: the projector,
the result note and the tests all read the constants.

Run `php artisan test` after. `HousingBenefitTest` writes the statutory ratios out as literals on
purpose, because a test that reads the implementation proves nothing, so a corrected figure reddens
it and the test is the place to record the new one. `InputNotesTest` asserts the literal `65%` in
the note text and would need that one string changed.

## Comments
