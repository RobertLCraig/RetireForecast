# Pin the inherited-pension age-75 rule to a live page

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
Card 0079 made a draw from an inherited pension tax-free where its owner died under 75, and taxable
where they died at 75 or over. The rule was **stated, not read off a live page**, because the
unattended build loop has **no web access**.

It reaches every projection of every household whose first death is early, and the two answers are
"no income tax at all" and "the survivor's own marginal rate on every pound", so getting it wrong
either overstates or understates the largest single asset a survivor is often left with.

Two things need confirming, not one:
- that the age-75 split applies to **beneficiary DRAWDOWN** and not only to lump-sum death benefits
  (the tool already applies it to the lump-sum form, sourced the same way, in
  `PathProjector::collectDeathInServiceBenefit`);
- that the under-75 exemption on drawdown is **not itself capped** by the deceased's remaining lump
  sum and death benefit allowance, the way the lump-sum form is. The engine currently applies no cap
  at all in drawdown, which is the favourable reading, and a favourable reading is the one to check.

## Links

**Relates to**
- `0079` - built the rule and its disclosure.
- `0134` - pins the OTHER half of the same PTM073010 page (the beneficiary's income tax where the
  death was at or after 75), so the two can be settled in one fetch.
- `0106`, `0109`, `0111`, `0113`, `0118`, `0125`, `0127`, `0129`, `0132`, `0135`, `0136`, `0138`,
  `0139`, `0140` - the same shape of gap from the same missing web access.

## Not this card
Changing the rule. If the source says the exemption is capped, that is a build, and it gets its own
card rather than widening this one.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a source URL and a verified_on date for the age-75 drawdown rule in docs/spec/ASSUMPTIONS.md section 38, or record there that the search found none. proves: manual
- [ ] WHEN the source shows the rule differs from the stated one, THE APP SHALL raise that as its own card rather than changing the engine here. proves: none
<!-- AC:END -->

## Tasks
- [ ] Fetch HMRC PTM073010 and confirm the age-75 split for beneficiary drawdown.
- [ ] Confirm whether the under-75 drawdown exemption is capped by the lump sum and death benefit
      allowance.
- [ ] Record both in ASSUMPTIONS.md section 38 with the date, and update the `verified_on` line in
      `PathProjector::drawIsTaxFree` and in `InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE`,
      which share the citation.

## Plan
Needs a session with web access. Stand in `C:\Dev\RetireForecast` on `master`. Nothing here changes
a figure, so the suite should stay green and no `ENGINE_VERSION` bump is owed. Do card `0134` in the
same pass: it is the same page.

## Comments

**2026-10-20** RESULT: blocked
TESTS: +0 new, suite not run (no file changed)
TOUCHED: none
OUT-OF-SCOPE: none

This card needs a live read of HMRC PTM073010 (https://www.gov.uk/hmrc-internal-manuals/pensions-tax-manual/ptm073010). In this unattended session both WebFetch and WebSearch were refused by the permission layer, so the page could not be read. I did not fall back to curl, because that would route round a refused permission.

Criterion 1 allows recording that 'the search found none', but no search ran, so writing that into ASSUMPTIONS section 38 would be false. Nothing was changed: ASSUMPTIONS.md, PathProjector::drawIsTaxFree and InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE are as they were. Criterion 2 has nothing to act on until the page is read.

The card's own Plan already says it needs a session with web access, standing in C:\Dev\RetireForecast. It should be taken by Rob or an attended session (with card 0134 in the same pass), or carry not_for_the_loop: so the loop stops picking it up.

**2026-10-20** RESULT: blocked
TESTS: +0 new, suite not run (no file changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second unattended take, same block as the first. The WebFetch of HMRC PTM073010 (https://www.gov.uk/hmrc-internal-manuals/pensions-tax-manual/ptm073010) was refused by the permission layer again, so the page was not read. I did not route round it with curl.

Criterion 1 cannot be met honestly: no search ran, so recording 'the search found none' in ASSUMPTIONS section 38 would be false. Criterion 2 has nothing to act on until the page is read. ASSUMPTIONS.md, PathProjector::drawIsTaxFree and InheritanceTaxCalculator::BENEFICIARY_TAXED_FROM_AGE are unchanged.

This card cannot advance in the loop. It needs Rob or an attended session with web access (do card 0134 in the same pass), or a not_for_the_loop: line in its frontmatter so the loop stops picking it up. I did not add that line because I may not edit the card.

**2026-10-20** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-20 manager (m20261020073744-480c)

**outcome: rob**

The card needs a live read of an HMRC page, and only an attended session with web access can do that.

**what the session said**

I checked the card against the code. The work is still not done.

`docs/spec/ASSUMPTIONS.md` has no PTM073010 source yet. Both criteria are still open. No review finding disproves anything. The work never started.

The only task is to read one HMRC web page. That page is PTM073010. The loop has no web access. So `reopen` would fail the same way a third time.

What a person must do:
- Start an attended Claude session with web access in `C:\Dev\RetireForecast`. Tell it to do cards 0146 and 0134 together, because both use the same page.
- Or add a `not_for_the_loop:` line to the card, so the loop stops taking it.

WHY: The card needs a live read of an HMRC page, and only an attended session with web access can do that.

OUTCOME: rob

