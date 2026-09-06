# The forecast pays Pension Credit to a household that has never claimed it

## Why
Every year the means test entitles a household to Guarantee Credit, the projection credits it, and
the household spends it. Nothing anywhere asks whether they have actually applied. Around a third of
eligible pensioner households never claim Pension Credit at all, so for those households the
forecast is spending money that is not arriving: their income is too high, their savings last too
long, and the plan the comparison ranks first may be the one that only works on an award nobody has
asked for.

It costs most where it hurts most. A household on Guarantee Credit is the lowest-income household
this tool models, and the award is a large share of what it lives on. Card 0045 made it worse in
passing: a year of Guarantee Credit now also brings Support for Mortgage Interest, so an unclaimed
award silently pays part of an unclaimed mortgage too.

It came about because the credit was modelled as a calculation rather than as a claim. The engine
computes the entitlement correctly and there has never been anywhere to say whether it is in
payment. Card 0046 took the credit out of the guaranteed income floor and started prompting the
household to claim it, which makes the gap visible without closing it: the reader is now told to
claim a benefit the forecast has already spent.

## Links

**Relates to**
- `0046` - moved Pension Credit out of the secure floor and added the claim prompt, which is what
  makes this gap legible; it deliberately did not touch whether the award is claimed.

## Not this card
Awarding Housing Benefit or Council Tax Reduction. Changing how the entitlement is calculated.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL let the reader say whether Pension Credit is currently claimed. proves: `test_a_household_can_say_pension_credit_is_not_claimed`
- [ ] #2 WHEN Pension Credit is marked as not claimed, THE APP SHALL project no Pension Credit income and no Support for Mortgage Interest. proves: `test_an_unclaimed_award_reaches_neither_income_nor_support_for_mortgage_interest`
- [ ] #3 WHEN Pension Credit is marked as not claimed and the means test would award it, THE APP SHALL report what claiming it is worth over the plan. proves: `test_the_value_of_claiming_is_reported_when_an_award_goes_unclaimed`
<!-- AC:END -->

## Tasks
- [ ] Add the claimed flag to the builder (default: claimed, so no stored scenario moves silently)
- [ ] Gate the `means_tested_benefit` income source and the SMI eligibility test on it
- [ ] Report the unclaimed award as a named figure, not only as a lower wealth line
- [ ] Bump `ENGINE_VERSION` and re-run stored scenarios if any default changes

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. Run `php artisan test` first and expect green.

The entitlement is computed in `PathProjector::pensionCreditAward()`, and its annual figure reaches
the year through `$src['means_tested_benefit']`. The same figure gates Support for Mortgage Interest
just below it (`supportForMortgageInterestNominal` takes `$benefitNominal`), so one gate settles
both. `Dto\Household` is the place a household-level flag belongs; `HouseholdAssembler` maps builder
form-state onto it, and adding a builder field means moving four things together (the blank default,
the validation rule, the `loadState` backfill and `Tests\Support\BuilderStateFixture::full()`).

A DEFAULT of "claimed" keeps every stored scenario byte-identical, which is why it is the default
rather than the cautious answer. Card 0046 wrote up the reasoning for the sibling figure in
`docs/spec/ASSUMPTIONS.md`; follow that shape.

"It worked" is a results page that shows no Pension Credit line for a household that says it has not
claimed, and a figure naming what claiming would be worth.

## Comments
**2026-09-06** Raised from card 0046, which found this and left it: 0046's four criteria are about
how the credit is REPORTED, and whether it is in payment at all is a modelling change with a stored
scenario cost behind it.
