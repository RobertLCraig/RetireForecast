# Re-source the asset-class returns and volatilities, and the gilt real return above all

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
