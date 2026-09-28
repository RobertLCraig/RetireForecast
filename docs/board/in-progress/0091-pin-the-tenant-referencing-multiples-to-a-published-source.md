# Pin the tenant-referencing multiples to a published source

## Why
Card 0031 built a referencing feasibility flag on every rent plan. It decides whether the flag
fires from three figures in `packages/finance-engine/src/Housing/Tenancy.php`:

- `REFERENCING_INCOME_MULTIPLE` = **30** times the monthly rent, the gross annual income a standard
  reference asks for. This one alone decides whether a plan is flagged as un-rentable, which is a
  verdict about whether the plan exists at all.
- `GUARANTOR_INCOME_MULTIPLE` = **36**, quoted to the reader as the bar a guarantor must clear.
- `ADVANCE_MONTHS_MIN` / `ADVANCE_MONTHS_MAX` = **6** to **12** months of rent in advance, quoted as
  the capital that route would tie up.

They are the property reviewer's judgement in the 2026-08-19 expert review, and they match common UK
referencing practice, but they are **not cited to a published source**. `docs/spec/ASSUMPTIONS.md`
§15 says so out loud.

The deposit cap in the same file is NOT part of this gap: five weeks' rent (six at £50,000 a year or
more) is statute, Tenant Fees Act 2019 c.4, Schedule 1 paragraph 2.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0031 could ship the mechanism and disclose the figures but could not go and check them.

## Links

**Relates to**
- `0031` - built the flag, the copy and the deposit charge that read these constants.
- `0085`, `0086`, `0087` - the same shape of gap, from the same review and the same missing web
  access. Whoever picks one up can settle all four in one research pass.

## Not this card
Changing the mechanism, the copy or the deposit charge. All three are card 0031's and are built.
This card only replaces numbers and adds their citations.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a primary or fetchable secondary source URL and a verified_on date for the referencing multiple, the guarantor multiple and the rent-in-advance range in docs/spec/ASSUMPTIONS.md, or record there that the search found none. proves: manual
- [ ] WHEN a sourced figure differs from the shipped one, THE APP SHALL use the sourced one and the flag and its copy SHALL move with it. proves: `test_a_rent_plan_whose_income_fails_referencing_is_flagged_in_every_such_year`
<!-- AC:END -->

## Tasks
- [ ] Find published criteria from the referencing providers themselves (HomeLet, Goodlord,
      Rightmove Tenant Passport, Van Mildert) or a letting-industry body, for the income multiple and
      the guarantor multiple.
- [ ] Check whether the multiple varies with rent level or region, which would make a single
      constant the wrong shape.
- [ ] Find a source for how much rent in advance is asked for, and whether it is really re-asked at
      every renewal (the copy says it is).
- [ ] Set the constants in `packages/finance-engine/src/Housing/Tenancy.php` to what the sources say,
      or record on this card why the reviewer's figures stand.
- [ ] Update ASSUMPTIONS.md §15, moving it out of the sourcing-gap list, and add the citations.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Every figure is a
constant in `packages/finance-engine/src/Housing/Tenancy.php`, and both the engine warning and the
results-page banner READ them, so changing one moves the screen with no other edit. The fixture is
`packages/finance-engine/tests/Housing/TenancyReferencingTest.php`. No `ENGINE_VERSION` bump is
needed unless a stored figure moves: the flag changes no projected number.

## Comments
