# Re-source the asset-class returns and volatilities, and the gilt real return above all

## What I need from you

The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

## Why
Card 0062 gave every asset class a source and a verified-on date for its return and for its
volatility, which they had never carried. The values it recorded were **stated, not read off a live
page**, because the unattended build loop has **no web access**. They are the two figures that
decide the answer more than any other in this engine.

- **The citations are the ones docs/spec/ASSUMPTIONS.md already held** (the FCA Handbook COBS 13
  Annex 2 page for the returns, the UBS Global Investment Returns Yearbook / Barclays Equity Gilt
  Study for the volatilities). Neither URL was fetched, and the FCA's projection rates are subject
  to periodic review, so the derived real returns may already have moved.
- **The verified-on date is 2026-06-24**, which is the sign-off date recorded in
  `AssumptionSetLibrary`'s own docblock, not a fresh check. A date that says "checked" while nobody
  checked is worse than no date, so it has to be re-earned or replaced.
- **The gilt real return of 0.0% is the one to look at first.** It sits on a 60% weight in the
  default cautious mix, so it is most of the blended figure, and it is stale in the CAUTIOUS
  direction against index-linked gilt yields since 2022. That understates every de-risked plan and,
  worse, understates the secured-income strategies a cautious household most needs, which is the
  exact comparison this tool exists to run.

What it costs: the blended return every pot grows at, so the depletion year, the success odds and
which plan the comparison ranks first.

## Links

**Relates to**
- `0062` - added the sourcing fields, the named mixes and the glidepath, and left this open.
- `0106`, `0109`, `0111`, `0113`, `0118`, `0125`, `0127`, `0129`, `0132`, `0134`, `0135`, `0136` -
  the same shape of gap from the same missing web access.

## Not this card
The four named allocation profiles and their weights, which are a judgement card 0062 recorded, not
a sourcing gap.

## Acceptance
<!-- AC:BEGIN -->
- [ ] THE APP SHALL carry a fetched source URL and the date it was fetched for every shipped asset-class return and volatility. proves: `test_every_shipped_asset_class_carries_a_source_and_a_verified_on_date`
- [ ] THE APP SHALL state the gilt real return against current index-linked gilt yields, or record in docs/spec/ASSUMPTIONS.md section 33 why the existing figure stands. proves: manual
<!-- AC:END -->

## Tasks
- [ ] Fetch the FCA COBS 13 Annex 2 rates and re-derive the real returns from the current nominals
- [ ] Fetch a current DMS / Barclays volatility and correlation set
- [ ] Re-source the gilt real return against index-linked gilt yields, dated
- [ ] Move `AssumptionSetLibrary::VERIFIED_ON` to the date of the check, and update ASSUMPTIONS.md

## Comments

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

This session has no web access. WebFetch of the FCA COBS 13 Annex 2 page was refused ("permissions to use WebFetch ... not granted"). This is the same gap that left the card open in 0062.

Criterion 1 cannot be met. It asks for a FETCHED source URL and the date of the fetch. AssumptionSetLibrary::VERIFIED_ON (2026-06-24) is still the sign-off date and not a check date. If I moved it without a fetch, that would be the false 'checked' date the card warns about. The named test, test_every_shipped_asset_class_carries_a_source_and_a_verified_on_date, already exists in packages/finance-engine/tests/Assumptions/AssetClassSourcingTest.php:26. It proves that a source and a date are present. It cannot prove that the page was fetched, so a green run of it does not show that criterion 1 is met. No fixture can tell a fetched date from a stated one. The proof is the dated fetch record itself.

Criterion 2 cannot be met either way. To restate the gilt real return, I need the current index-linked gilt yields. To record why 0.0% stands, I need those same yields. The repository has neither, and a reason written without them would be invented.

Next step: run this card in an attended session that has WebFetch/WebSearch. Fetch the FCA COBS 13 Annex 2 rates, a current DMS/UBS Yearbook or Barclays Equity Gilt Study volatility set, and the BoE/DMO index-linked gilt real yield curve. Then update the figures and VERIFIED_ON, and write ASSUMPTIONS.md section 33. If a figure moves, expect MonteCarlo GoldenMasterTest to go red, and do a re-pin plus a DECISIONS entry.

**2026-10-19** RESULT: blocked
TESTS: +0 new, none run (no code changed)
TOUCHED: none
OUT-OF-SCOPE: none

Second take, same block. This session has no web access: WebFetch of https://www.handbook.fca.org.uk/handbook/COBS/13/Annex2.html and a WebSearch for UK index-linked gilt real yields were both refused ("permissions ... not granted").

I searched the repository for a usable figure. docs/research/RESEARCH-0095-db-escalation-figures.md (read 2026-10-11) cites index-linked gilts only for the 2030 RPI reform, not for any yield. No fetched return, volatility or real-yield figure is on disk.

Criterion 1 is not met. It needs a fetched URL and the fetch date. Moving AssumptionSetLibrary::VERIFIED_ON without a fetch would write the false 'checked' date the card warns about. The named test already exists (packages/finance-engine/tests/Assumptions/AssetClassSourcingTest.php) and proves presence, not that a fetch happened.

Criterion 2 is not met. Both of its branches (restate the gilt return, or record why 0.0% stands) need current index-linked gilt yields, and the repository has none.

This card cannot advance in the unattended loop. It needs an attended session with WebFetch/WebSearch, or a /research run that writes the FCA COBS 13 Annex 2 rates, a DMS/UBS or Barclays volatility set and the BoE/DMO real yield curve into docs/research/ with read dates. A card session can then build from that file without the web. If a figure moves, expect the MonteCarlo GoldenMasterTest to go red: re-pin it and add a DECISIONS entry.

**2026-10-19** The loop moved this card from in-progress/ to human-review/. 2 takes in a row ended with it still in in-progress/, and the last one said: `made no progress: 2 of 2 still open, exactly as this take found it`. What this card is waiting for is not another session. bin/work-card.ps1 counts those takes out of storage/logs/work-card.log, and will start it again as soon as a person has moved it back to todo/.

### 2026-10-19 manager (m20261019141732-3568)

**outcome: rob**

Every open criterion needs figures fetched from the web, and only an attended session can fetch them.

**what the session said**

**Outcome: `rob`.** I left this card for you. Another loop session cannot finish it.

Both criteria are still open, and no review finding disproves a ticked box, so `reopen` does not apply. Both criteria need figures from the web. The unattended loop has no web, and both builder sessions were refused WebFetch and WebSearch.

I checked `docs/research/`. No file there has the FCA COBS 13 Annex 2 rates, a volatility set or index-linked gilt real yields. `RESEARCH-0095-db-escalation-figures.md` mentions index-linked gilts only for the 2030 RPI reform. So a builder session has nothing to build from.

This is not a question for you either. Ordinary practice settles the method: fetch the real figures, then update them.

**What you do:** In an attended session with web access, run `/research` for three things:
1. The FCA COBS 13 Annex 2 projection rates.
2. A DMS/UBS Yearbook or Barclays Equity Gilt Study volatility set.
3. The BoE/DMO index-linked gilt real yield curve, with read dates.

Then move the card back to `todo/`. The loop will build from that file.

WHY: Every open criterion needs figures fetched from the web, and only an attended session can fetch them.

OUTCOME: rob


**2026-10-25** **Research, 2026-10-25 (attended session, web)**
