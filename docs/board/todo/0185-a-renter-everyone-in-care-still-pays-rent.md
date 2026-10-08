# A renting household with everyone in care still pays rent

## Why
Card 0056 #4 stopped the post-sale rent for a household that OWNED a home, sold it, and has every
living member in care: `PathProjector::projectYear()` skips the rent when `$home !== null` and
nobody is at home. A household that rents from year 0 (no `primaryResidence`) is not covered, so
once every living member is in permanent care it is charged its rent and the full care fee.

Whether that is wrong depends on the tenancy: a renter in permanent care normally gives it up, but
a couple's survivor may keep it during a spell that could be temporary. The engine treats every
modelled care year as permanent (card 0056), so the consistent reading is no rent.

Found while building card 0056 #4, outside that card's wording ("the home is sold").

## Links

**Relates to**
- `0056` - the owner case, which this extends to a renter.

## Acceptance
<!-- AC:BEGIN -->
- [ ] WHEN every living member of a renting household is in care, THE APP SHALL NOT charge rent for those years. proves: `test_a_renting_household_all_in_care_pays_no_rent`
<!-- AC:END -->

## Plan
Drop the `$home !== null` half of `$soldForCare` in `PathProjector::projectYear()`, bump
`ENGINE_VERSION`, and check the Housing Benefit path still reads a zero rent cleanly.

## Comments
