# Pin the tenant-referencing multiples to a published source

## What I need from you
Nothing. The A/B question was decided on 2026-09-29 (see Comments), and the web research is done:
`docs/research/RESEARCH-0091-tenant-referencing.md` (2026-10-11). Build from that note. Do not go to the web.

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
- `0084` - asked whether the unattended loop may use the web; Rob's ruling there is the
  research-only session this card's `waiting_on:` names.

## Not this card
Changing the mechanism, the copy or the deposit charge. All three are card 0031's and are built.
This card only replaces numbers and adds their citations.

## Acceptance
<!-- AC:BEGIN -->
- [x] THE APP SHALL carry a primary or fetchable secondary source URL and a verified_on date for the referencing multiple, the guarantor multiple and the rent-in-advance range in docs/spec/ASSUMPTIONS.md, or record there that the search found none. proves: manual
- [x] WHEN a sourced figure differs from the shipped one, THE APP SHALL use the sourced one and the flag and its copy SHALL move with it. proves: `test_a_rent_plan_whose_income_fails_referencing_is_flagged_in_every_such_year`
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

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

This session has no web access either. WebSearch and WebFetch were both refused with a
permission error, which is the gap the card's Plan predicted. I also searched the local
SecondBrain vault and `docs/research/` for a published referencing criterion (income multiple,
guarantor multiple, rent in advance). Neither holds one.

So neither criterion can be met here. Criterion 1 allows "record that the search found none",
but no search ran, so writing that into ASSUMPTIONS.md §15 would be false. Criterion 2 only
fires once a sourced figure exists. The constants in `Tenancy.php` and §15 are untouched.

Next step: run this card in an attended session with WebSearch and WebFetch allowed (the Plan
already says so). One research pass can settle 0085, 0086 and 0087 at the same time.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Second unattended pick-up, same wall. WebSearch and WebFetch were both refused again ("you
haven't granted it yet"). Nothing else changed since the entry above, so both criteria stay open
and `Tenancy.php` and ASSUMPTIONS.md §15 are untouched. Re-queuing this card unattended will hit
the same refusal every time: it needs Rob to run it attended, or to allow WebSearch and WebFetch
for card sessions.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Third unattended pick-up, same refusal for WebSearch and WebFetch. Nothing changed. Please stop
re-queuing this card unattended: it needs an attended session, or web tools allowed for card
sessions.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fourth unattended pick-up. WebSearch and WebFetch were both tried again and both refused ("you
haven't granted it yet"). Nothing changed. The fix is outside this card: Rob runs it attended, or
allows WebSearch and WebFetch for card sessions, or pulls it off the unattended queue.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifth unattended pick-up. WebSearch and WebFetch were refused again, same message. Nothing
changed and both criteria stay open. This card cannot move until Rob runs it attended or grants
web tools to card sessions.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixth unattended pick-up. WebSearch was refused again ("you haven't granted it yet"). I did not
route round the refusal with curl: that would override a permission Rob has not given. Nothing
changed and both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Seventh unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Eighth unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Ninth unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Tenth unattended pick-up. The WebSearch schema loads, but the call is refused ("you haven't
granted it yet"). Nothing changed and both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Eleventh unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twelfth unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirteenth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fourteenth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifteenth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixteenth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Seventeenth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Eighteenth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Nineteenth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twentieth unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twenty-first unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twenty-second unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twenty-third unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twenty-fourth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twenty-fifth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twenty-sixth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twenty-seventh unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twenty-eighth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Twenty-ninth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirtieth unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirty-first unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirty-second unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirty-third unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirty-fourth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirty-fifth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirty-sixth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirty-seventh unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirty-eighth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Thirty-ninth unattended pick-up. WebSearch was refused again, same message. I did not use the
Playwright browser to route round it, for the same reason as the curl note above. Nothing changed
and both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fortieth unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Forty-first unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Forty-second unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Forty-third unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Forty-fourth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Forty-fifth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Forty-sixth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open. The scheduler keeps re-picking this card with no web access. It needs an
attended session with WebSearch and WebFetch allowed, or it should stop being scheduled unattended.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Forty-seventh unattended pick-up. WebSearch and WebFetch were both refused again, same message.
Nothing changed and both criteria stay open. Same fix as before: an attended session with web
tools allowed, or take this card off the unattended queue.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Forty-eighth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Forty-ninth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fiftieth unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifty-first unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifty-second unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifty-third unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifty-fourth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifty-fifth unattended pick-up. WebSearch and WebFetch were both refused again, same message.
Nothing changed and both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifty-sixth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifty-seventh unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifty-eighth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Fifty-ninth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixtieth unattended pick-up. WebSearch was refused again, same message. Nothing changed and both
criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixty-first unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixty-second unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixty-third unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixty-fourth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixty-fifth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixty-sixth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixty-seventh unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28**
RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: docs/board/in-progress/0091-pin-the-tenant-referencing-multiples-to-a-published-source.md
OUT-OF-SCOPE: none

Sixty-eighth unattended pick-up. WebSearch was refused again, same message. Nothing changed and
both criteria stay open.

**2026-09-28** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

**2026-09-28** Manager pass: left in `human-review/` with an ask. Sixty-eight unattended takes have ended on the same WebSearch refusal. Only Rob can decide whether the loop gets the web (card 0084).

**2026-09-29** **Decided:** B, in the narrower form Rob ruled on 0084 on 2026-09-28: the unattended loop gets the web only in a separate research session that can write notes under `docs/research/` and nothing else, and a later build session with no web builds this card from the note. Settled by an attended agent under Rob's rule that human-review holds only what he must decide: this card's A-or-B ask is the question 0084 asked, and Rob answered it there. The research session is `progressboard#0211`, not yet built, so this card carries `waiting_on:` until it lands, which keeps the build loop from spending another take on the refusal.

**2026-10-13** `waiting_on:` removed. The research this card waited for was done in an attended session on 2026-10-11 and is now committed as `docs/research/RESEARCH-0091-tenant-referencing.md`, so no research session is needed: build from the note. Attended session, on Rob's go.

**2026-10-13** RESULT: done
TESTS: +0 new (1 extended, 2 repointed), all green
TOUCHED: packages/finance-engine/src/Housing/Tenancy.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Housing/TenancyReferencingTest.php
tests/Feature/Forecast/RentReferencingNoticeTest.php
docs/spec/ASSUMPTIONS.md
docs/spec/METHODOLOGY.md
docs/DECISIONS.md
docs/HANDOVER.md
docs/board/todo/0198-referencing-can-be-passed-on-capital.md
docs/board/todo/0199-scotland-rent-in-advance-is-not-englands-rule.md
OUT-OF-SCOPE: 0198, 0199

Built from docs/research/RESEARCH-0091-tenant-referencing.md, no web used.

#1: ASSUMPTIONS §15 now carries a source table with URLs and verified_on 2026-10-11. 30x (HomeLet HL202407 p.8, Goodlord ratio 2.5) and 36x (HomeLet p.8-9, Goodlord ratio 3) stand. The sourcing-gap paragraph is gone. It records that no source requires a homeowner guarantor, and that Scotland differs and Wales/NI were not researched.

#2: the sourced figure that differs is rent in advance. ADVANCE_MONTHS_MIN/MAX (6/12) and Tenancy::rentInAdvance() are deleted. New RENT_IN_ADVANCE_CAP_MONTHS = 1, cited to Renters' Rights Act 2025 s.8 and gov.uk. The flag copy in PathProjector::rentReferencingWarnings reads it: rent up front is no longer a way round, at most 1 month (in pounds), before the tenancy starts. Also dropped from the copy, per the note: 'asked for again at every renewal', 'UK homeowner guarantor' and 'a household that has just sold no longer has a homeowner in it'. The sale-money sentence is softened to say some firms accept proof of funds from a completed sale. The proves test was extended and watched fail on the old copy ('renewal' present) before the change. The results-page banner shows the engine message, so it moves with no view edit.

No ENGINE_VERSION bump: the flag changes no projected number. The new copy still needs a browser check (Herd serves C:\Dev\RetireForecast, not this worktree).

Raised 0198 (HomeLet's independent-means route would pass households the flag still fires on; post-Act sizing unknown, needs web) and 0199 (a Scottish rent plan is told England's 1-month rule; Scotland allows 6).
