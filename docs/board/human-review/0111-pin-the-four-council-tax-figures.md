---
no_outward_effect: "published" names the statutory source somebody else printed, read here; nothing is published by us
---

# Pin the four council tax figures to a published source

## Why
Card 0047 split council tax out of the home's running costs and made it shrink the way a real bill
does. Four figures do that work, all of them statutory, and none of them was read off the
legislation:

- **The single-person discount, 25%** (`Benefits\CouncilTax::SINGLE_PERSON_DISCOUNT_BPS`).
- **The Council Tax Reduction taper, 20% of income above the applicable amount**
  (`Benefits\CouncilTax::REDUCTION_TAPER_BPS`), together with the two rules beside it: maximum
  reduction for a household on Guarantee Credit, and nil above the £16,000 capital limit.
- **The band proportions in ninths, band A 6 through band H 18** (`Dto\CouncilTaxBand::ninths()`).
- **The disabled band reduction, charged as the band below, with band A reduced by one ninth of
  band D** (`Dto\CouncilTaxBand::reducedNinths()`).

The RULES are stated correctly in each docblock, with the Act or the SI named. The VALUES are the
building session's own knowledge of that legislation, and every citation in
[docs/spec/ASSUMPTIONS.md](../../spec/ASSUMPTIONS.md) section 23 is an unvisited URL.

What it costs: all four move the answer, and the household they move it most for is the poorest one
in the model. A wrong taper misprices Council Tax Reduction for every household above the guarantee,
which on a typical bill is over a thousand pounds a year. Wrong ninths misprice the disabled band
reduction, which is not means-tested and so reaches every household that claims it.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0047 could ship and disclose the figures but could not go and check them.

## Links

**Relates to**
- `0047` - split the bill out, and introduced all four figures with the disclosure that reads them.
- `0106`, `0109` - the same shape of gap, from the same missing web access. Whoever picks one up can
  settle several in one research pass.

## Not this card
The mechanism. Which reliefs apply, in what order, and how the pension-age reduction is assessed are
card 0047's and are built. This card replaces four numbers and adds their citations.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for all four figures in docs/spec/ASSUMPTIONS.md section 23, or record there that the search found none. proves: manual
- [ ] WHEN a published figure differs from the shipped one, THE APP SHALL use the published one, and the council tax charged, the result note and the reported year SHALL all move with it. proves: `test_a_disabled_band_reduction_charges_the_band_below`
<!-- AC:END -->

## Tasks
- [ ] Read Local Government Finance Act 1992 s.5 and s.11 and confirm the ninths and the 25%.
- [ ] Read the Council Tax (Reductions for Disabilities) Regulations 1992 and confirm the
      band-below rule and the band A case.
- [ ] Read the Council Tax Reduction Schemes (Prescribed Requirements) (England) Regulations 2012
      and confirm the 20% taper, the Guarantee Credit passport and the capital limit.
- [ ] Delete the "SOURCING GAP" blocks on `CouncilTax` and `CouncilTaxBand` once pinned, and move
      ASSUMPTIONS section 23 out of the sourcing-gap list.
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` if any figure moves, and note the owed re-run.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Two files hold every
figure: `packages/finance-engine/src/Benefits/CouncilTax.php` and
`packages/finance-engine/src/Dto/CouncilTaxBand.php`. Nothing restates them: the projector, the
result note and the tests all read the constants or the enum.

Run `php artisan test` after. `CouncilTaxTest` writes the statutory ratios out as literals on
purpose, because a test that reads the implementation proves nothing, so a corrected figure reddens
it and the test is the place to record the new one. `InputNotesTest` asserts the literal `25%` in
the note text and would need that one string changed.

While reading the 2012 Regulations, settle one open modelling call recorded in ASSUMPTIONS section
23: the reduction reuses the Pension Credit applicable amount rather than the CTR scheme's own
personal allowances and premiums. If the two differ by enough to matter, that is a separate card,
not this one.

## Comments

**2026-10-14** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card needs web access, and this unattended session has none. WebSearch and WebFetch were both refused on their first call (a legislation.gov.uk fetch of LGFA 1992 s.5, then a search). Its own Plan says the same: "Needs a session with web access."

Criterion 1 is not met. It allows recording that "the search found none", but no search ran. Writing that into ASSUMPTIONS section 23 would claim a search that did not happen.

Criterion 2 is not met. It depends on reading the published figures. With no source read, no figure can be confirmed or moved. A test written now would just restate the values already shipped, so its failure would prove nothing.

Next step: run this card in an attended session with web access, from C:\Dev\RetireForecast. Read LGFA 1992 s.5 and s.11, SI 1992/554 (Reductions for Disabilities), and SI 2012/2885 (CTR Prescribed Requirements, England). Cards 0106 and 0109 have the same gap and can share one research pass. Consider adding not_for_the_loop: to this card's frontmatter so the loop stops picking it up.

**2026-10-14** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second unattended take. It is blocked the same way as the first. WebFetch (legislation.gov.uk, LGFA 1992 s.5) and WebSearch were both refused on their first call because the permission was not granted. The card's Plan says it "Needs a session with web access."

Criterion 1 is not met. No search ran, so recording "the search found none" in ASSUMPTIONS section 23 would be false.
Criterion 2 is not met. No published figure was read, so none can be confirmed or moved. A test written now would only restate the shipped values.

The loop has now picked this card up twice with no way to advance it. Rob: add not_for_the_loop: to its frontmatter, then run it attended from C:\Dev\RetireForecast with web access. Read LGFA 1992 s.5 and s.11, SI 1992/554 and SI 2012/2885. Cards 0106 and 0109 can share that research pass.

**2026-10-14** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.
