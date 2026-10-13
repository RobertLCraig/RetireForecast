# Referencing can be passed on capital, and the flag fires on households that route would pass

## Why
Found building card 0091. HomeLet's published criteria (Referencing Service Guidelines HL202407,
p.11-12, "Living on independent means") pass an applicant on capital: bank statements showing at
least 3x the total rent for the tenancy term, or a solicitor's letter showing equity from a completed
house sale. That is exactly the household the referencing flag (`PathProjector::rentReferencingWarnings`)
fires on: just sold, large pot, small pension.

Card 0091 only softened the copy to say some firms accept proof of funds. The flag still fires, so a
plan that a real referencing firm would accept is told a landlord "normally would not" say yes.

The note is `docs/research/RESEARCH-0091-tenant-referencing.md`, finding 3. It also says what is NOT
known: the document predates the Renters' Rights Act, and since 1 May 2026 new tenancies in England are
periodic, so "the tenancy term" that sizes the test has no fixed length. How HomeLet sizes it now was
not found. That needs research before a number can be built.

## Links

**Relates to**
- `0091` - sourced the multiples and softened the sale-money sentence.
- `0031` - built the flag.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 THE APP SHALL carry a published source URL and verified_on date for how a referencing provider sizes the independent-means test for a periodic tenancy, in docs/spec/ASSUMPTIONS.md §15, or record there that the search found none. proves: manual
- [ ] #2 WHEN a rent plan's income fails the standard reference but its liquid capital clears the sourced independent-means bar, THE APP SHALL NOT raise the referencing-failed flag for that year and SHALL say which route it passed on. proves: `test_a_rent_plan_that_clears_the_independent_means_bar_is_not_flagged`
<!-- AC:END -->

## Tasks
- [ ] Research the post-Act sizing of the independent-means route (needs a session with web).
- [ ] Decide which balances count as "funds" (cash and investments, not pensions) and read them from the year's state.
- [ ] Add the route to `Tenancy` as a constant with its citation, and move the flag and its copy with it.
