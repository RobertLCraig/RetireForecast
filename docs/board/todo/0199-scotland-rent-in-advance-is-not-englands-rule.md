# A Scottish rent plan is told England's rent-in-advance rule

## Why
Found building card 0091. The referencing flag now says "since 1 May 2026 a landlord in England can
ask for at most 1 month's rent in advance" (`Tenancy::RENT_IN_ADVANCE_CAP_MONTHS`, Renters' Rights
Act 2025 s.8). That Act's rent-in-advance ban does not extend to Scotland. There a landlord may ask
for up to 6 months in advance (mygov.scot, "They cannot charge more than 6 months' rent in advance",
read 2026-10-11, https://www.mygov.scot/tenant-new-tenancy). The flag shows the England sentence to
every household, whatever its `RegionProfile`, so a Scottish reader is told a route is closed that
is open to them, and is not told what it would tie up.

Wales and Northern Ireland were not researched (`docs/research/RESEARCH-0091-tenant-referencing.md`,
finding 4).

## Links

**Relates to**
- `0091` - replaced the 6-to-12-month route with the England cap.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a household in Scotland has a rent plan that fails referencing, THE APP SHALL state the Scottish cap on rent in advance and the pounds it would tie up, read from a cited constant, not the England rule. proves: `test_a_scottish_rent_plan_is_told_the_scottish_rent_in_advance_cap`
<!-- AC:END -->

## Tasks
- [ ] Confirm the Scottish cap against Rent (Scotland) Act 1984 s.89 as applied to private residential tenancies (needs web).
- [ ] Pass the household's region to `rentReferencingWarnings` and branch the sentence on it.
- [ ] Decide what Wales and NI are told until they are researched, and record it in ASSUMPTIONS §15.
