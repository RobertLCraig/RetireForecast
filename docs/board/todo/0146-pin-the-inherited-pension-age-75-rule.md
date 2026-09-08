# Pin the inherited-pension age-75 rule to a live page

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
