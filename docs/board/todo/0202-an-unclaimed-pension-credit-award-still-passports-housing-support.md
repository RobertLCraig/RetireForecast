# An unclaimed Pension Credit award still passports Housing Benefit and Council Tax Reduction

## Why
Card 0110 lets a household say Pension Credit is not claimed, and the forecast then pays no award
and no Support for Mortgage Interest. But `HousingBenefit::annualAward` and
`CouncilTax::reductionAnnual` still give the maximum award whenever the means test's
`guaranteeCreditWeekly` is positive, which is the passport a household gets only by being IN
RECEIPT of Guarantee Credit. So a household that has not claimed still gets passported housing
support. Where its income is below the guarantee the taper gives much the same answer, so the gap
is mostly the capital limit: a passported household is not held to the £16,000 Housing Benefit and
Council Tax Reduction limit, and an unclaimed one is.

## Links

**Relates to**
- `0110` - added the claimed flag and left housing support alone (its "Not this card").

## Not this card
Changing how Housing Benefit or Council Tax Reduction are calculated for a household that HAS claimed.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN Pension Credit is marked as not claimed, THE APP SHALL assess Housing Benefit and Council Tax Reduction without the Guarantee Credit passport, on their own income taper and capital limit. proves: `test_an_unclaimed_award_does_not_passport_housing_support`
<!-- AC:END -->

## Plan
`PathProjector::housingBenefitNominal` and `councilTaxNominal` pass the means test's award to both
calculators. Gate the passport on `Household::$pensionCreditClaimed` there, once, rather than in
each calculator. Test with a renter on Guarantee Credit holding more than £16,000.

## Comments
**2026-10-14** Raised from card 0110, which found this and left it as out of scope.
