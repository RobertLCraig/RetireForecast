# Pin the selling-cost figures to a published source

## Why
Card 0032 repriced what selling a home costs. Two of its figures move real money on every plan that
sells, and neither was cited to anything published:

- `HousingProceeds::DEFAULT_SELLING_COST_RATE_BP` = **400** (4% of the sale price), the all-in
  catch-all the engine charges when the reader itemises nothing. It doubled from 2%.
- `HousingProceeds::CGT_RETURN_FEE_PENCE` = **£750**, the accountant's fee for preparing the 60-day
  capital-gains return, charged only on a disposal that owes tax.

The six itemised figures the builder ships alongside them were in the same position: estate agent
1.5%, leasehold conveyancing £2,000, management pack £500, licence to assign plus notices £700,
removals £1,200, energy certificate £80 (`ScenarioBuilder::defaultSellingCosts()`).

"Nearer 4%" was the property reviewer's judgement in the 2026-08-19 expert review. Everything else
in the list was the building session's own reading of ordinary UK practice, and
`docs/spec/ASSUMPTIONS.md` §16 said so out loud. Selling costs come off the net proceeds, and the
net proceeds are what the whole buy-versus-rent comparison rests on.

It came to be this way because the unattended build loop has **no web access**, so the session that
built card 0032 could ship and disclose the figures but could not go and check them.

## Links

**Relates to**
- `0032` - set these constants, and the itemised builder lines that sit beside them.
- `0085`, `0086`, `0087`, `0091` - the same shape of gap, from the same review and the same missing
  web access, each now waiting on `progressboard#0211` under Rob's 0084 ruling.

## Not this card
The mechanism, the itemisation and the disclosure. All three are card 0032's and are built. This
card only replaces numbers and adds their citations.

## Acceptance
<!-- AC:BEGIN -->
- [x] THE APP SHALL carry a primary or fetchable secondary source URL and a verified_on date for the all-in selling-cost rate and the capital-gains return fee in docs/spec/ASSUMPTIONS.md, or record there that the search found none. proves: manual
- [x] WHEN a sourced figure differs from the shipped one, THE APP SHALL use the sourced one, and the disclosure that reads the constant SHALL move with it. proves: `test_the_assumed_selling_cost_rate_is_read_from_the_engine_constant`
<!-- AC:END -->

## Tasks
- [x] Find a published all-in cost-of-moving or cost-of-selling series and check whether it splits
      leasehold from freehold.
- [x] Find published figures for the leasehold-specific fees: management pack, licence to assign,
      notices and deed of covenant.
- [x] Find an accountancy fee benchmark for a single 60-day UK property capital-gains return.
- [x] Check whether a whole-of-market rate is even the right shape, or whether the flat fees should
      scale with the sale price.
- [x] Set the constants and the builder line values, or record on this card why the shipped figures
      stand.
- [x] Update ASSUMPTIONS.md §16, moving it out of the sourcing-gap list, and add the citations.

## Comments

**2026-09-28 to 2026-09-29** Thirty-six unattended pick-ups in one day, each ending "WebSearch
refused", condensed to this line. The loop moved the card here on 2026-09-29 and a manager pass on
2026-09-30 said a person with web access had to do it. The scheduler-side fix is card 0084 (decided
2026-09-28: a research-only web session, built by `progressboard#0211`).

**2026-09-30** Done in an attended session with web access. Every shipped figure sits inside a
published 2026 range, so **no constant and no builder line moved**, which is why the second
criterion is ticked without a code change: the sourced figures do not differ, and
`test_the_assumed_selling_cost_rate_is_read_from_the_engine_constant` still proves the disclosure
reads the constant. No `ENGINE_VERSION` bump and no scenario re-run, because no projected money
changed. What was found, all read 2026-09-30, now lives with its URLs in `docs/spec/ASSUMPTIONS.md`
§16 (the sourcing-gap flag there is gone) and in the two docblocks in `HousingProceeds.php`:

- Estate agent 1.5%: HomeOwners Alliance average 1.42% inc VAT, sole-agency range 1.2% to 1.8%.
- Conveyancing £2,000: HOA £610 to £950 plus about £300 leasehold and £50 mortgage; Purplebricks
  2026 £1,421 to £2,182. Kept at the cautious end under the adverse-default rule.
- Management pack £500: HOA "typically £500" (£300 to £800); Innovus £200 to £500. A £200 LPE1 cap
  is announced, not law.
- Licence, notices and deed of covenant £700: HOA notice of transfer up to £300, notice of charge
  £50 to £200, deed of covenant about £80; £700 is the sum of the upper ends. Notices are often the
  buyer's cost; a seller not charged them clears the line.
- Removals £1,200: White & Company local 2-bed £500 to £1,200, 2 to 3 bed £1,000 to £1,500.
- EPC £80: HOA £60 to £120.
- CGT return £750: accountants publish £250 to £950 plus VAT for one 60-day return; mid-range.
- **All-in 4%: no published leasehold series exists; recorded as "the search found none".**
  Published all-in figures cover a freehold house with no CGT (HOA about 1.7%; Springbok 2% to
  2.6% with removals). The itemised leasehold lines plus the conditional £750 on a £300,000 flat come
  to 3.4% to 3.9%, so 4% stands as the cautious catch-all. On shape: agent fees scale with price and
  everything else is flat, which is exactly how the itemised set is built, so the rate is only the
  fallback for a reader who itemises nothing.

For review: attack the citations, not the boxes. The pages are secondary sources (a consumer body,
an agent, a managing agent, accountancy firms); no primary series for selling costs exists in the
UK, and the card allowed a "fetchable secondary source".
