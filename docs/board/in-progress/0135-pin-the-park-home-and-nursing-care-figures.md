---
no_outward_effect: "published" names a source somebody else printed, read here; nothing is published by us
---

# Pin the park-home and NHS nursing-care figures to a published source

## Why
Card 0059 put three new rules into the engine, and none of them was read off a live page, because
the unattended build loop has **no web access**. All three reach a projection.

- **The site owner's commission, 10% of the sale price** (`Property::MAX_SITE_COMMISSION_BPS`).
  Stated from the Mobile Homes Act 1983 Sch.1 Pt.1 para 25 and the Mobile Homes (Commission) Order
  1983; the legislation.gov.uk citation in [docs/spec/ASSUMPTIONS.md](../../spec/ASSUMPTIONS.md)
  section 31 is an unvisited URL. It comes off the home's value in the estate and in the care means
  test, so a tenth of a park home turns on it.
- **NHS-funded Nursing Care, £254.06 a week**
  (`CareAssumptions::FUNDED_NURSING_CARE_WEEKLY_PENCE`). Stated as the 2025/26 standard rate, and
  the tax year it belongs to is itself unconfirmed. It comes off every week of every modelled
  nursing spell, which is what re-pinned the Monte Carlo golden master.
- **That a park home gets no residence nil-rate band** (`Property::qualifiesForRnrb()`). Stated
  from the statutory need for a qualifying residential interest in a dwelling-house. It is the
  adverse reading of a point that is genuinely arguable rather than settled, and it decides up to
  two full bands of Inheritance Tax on such an estate.

What it costs: the first two are cash figures in every affected projection, and the third can be
worth £350,000 of allowance on a couple's second death.

## Links

**Relates to**
- `0059` - built all three, and the disclosures that read these constants.
- `0106`, `0109`, `0111`, `0113`, `0118`, `0125`, `0127`, `0129`, `0132`, `0134` - the same shape
  of gap from the same missing web access.

## Not this card
Whether NHS Continuing Healthcare should be MODELLED rather than disclosed. Card 0059 decided to
disclose it and not model it, because it turns on a health assessment nothing here can predict;
that is a decision to reopen on its own if it is reopened at all.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for all three rules in docs/spec/ASSUMPTIONS.md section 31, or record there that the search found none. proves: manual
- [ ] WHEN a published figure differs from the shipped one, THE APP SHALL use the published one, and the estate, the care charge and the results copy SHALL all move with it. proves: `test_a_nursing_home_fee_is_charged_net_of_the_nhs_contribution`
<!-- AC:END -->

## Tasks
- [ ] Read the Mobile Homes Act 1983 commission provisions and confirm the maximum rate and that it
      is charged on the sale price rather than on a gain.
- [ ] Read the current NHS England FNC standard rate and confirm which tax year it is for, whether
      the higher (protected) rate still exists, and whether Wales and Northern Ireland differ.
- [ ] Check IHTM46000 and the definition of a qualifying residential interest against the park-home
      case, and record whether the refusal is settled or arguable.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if any figure moves, and note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Two files hold all of
it: `packages/finance-engine/src/Dto/Property.php` and
`packages/finance-engine/src/Care/CareAssumptions.php`. Nothing restates the constants: the
projector and the disclosures read them.

Run `php artisan test` after. `FundedNursingCareTest` and `ChattelDwellingEstateTest` pin the
arithmetic and `ChattelDwellingNoticeTest` pins the copy, so a corrected figure reddens them
together. A moved FNC rate also reddens `MonteCarlo\GoldenMasterTest`, which needs re-pinning with
its own DECISIONS.md entry.

## Comments

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Blocked on web access, as the card's own Plan predicted ("Needs a session with web access"). In this unattended session WebSearch and WebFetch were both refused at the permission prompt (tried legislation.gov.uk for the Mobile Homes (Commission) Order 1983, and a search for the NHS FNC 2025/26 rate). Every task on the card is reading a live page, so none could be done.

Criterion 1 not met: I did not write "the search found none" into ASSUMPTIONS section 31, because no search ran; that line would be false.
Criterion 2 not met: with no published figure read, there is nothing to compare the shipped constants against, so no test was written or watched fail. Property::MAX_SITE_COMMISSION_BPS, CareAssumptions::FUNDED_NURSING_CARE_WEEKLY_PENCE and Property::qualifiesForRnrb() are unchanged, and ENGINE_VERSION is not bumped.

Next: run this card in an attended session at C:\Dev\RetireForecast where Rob can allow WebFetch/WebSearch, or mark it not_for_the_loop. Sending it back to the unattended loop will block again the same way.

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Blocked on web access for the second take in a row. WebFetch (legislation.gov.uk/uksi/1983/748, the Mobile Homes (Commission) Order 1983) and WebSearch (NHS FNC 2025/26 rate) were both refused at the permission prompt in this unattended session. Every task on the card is reading a live page, so none could be done.

Criterion 1 not met: no search ran, so writing "the search found none" into ASSUMPTIONS section 31 would be false.
Criterion 2 not met: no published figure was read, so there is nothing to compare the shipped constants against and no failing test could be written. Property::MAX_SITE_COMMISSION_BPS, CareAssumptions::FUNDED_NURSING_CARE_WEEKLY_PENCE and Property::qualifiesForRnrb() are unchanged; ENGINE_VERSION is not bumped.

Next: this card cannot advance in the unattended loop. It needs not_for_the_loop: in its frontmatter, or an attended session at C:\Dev\RetireForecast where Rob allows WebFetch/WebSearch. Sending it back to the loop will block a third time the same way.
