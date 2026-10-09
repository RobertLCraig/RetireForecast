# A reader's own CGT-return quote is charged on top of the default

## Why
Found by the 2026-09-05 review of card 0032, repeated by the 2026-09-28 manager pass, and still
true on 2026-10-05. The `HousingProceeds::CGT_RETURN_FEE_PENCE` docblock tells a reader with a real
accountant's quote to enter it as a `SellingCostComponent`. `HousingProceeds::compute()` appends
the £750 after the components whatever they hold, so that reader pays their quote and the £750.
A sell plan is then too pessimistic by £750, and the docblock promises an edit that does not work.

## Links

**Relates to**
- `0032` - added the fee and the docblock.
- `0168` - the same fee is missing from the assumptions panel.
- `0092` - sourced the £750 figure, which this card leaves as it is.

## Not this card
- The £750 figure itself, which card 0092 sourced.
- Whether the fee reduces the gain. It must not (TCGA 1992 s.38), as card 0032 settled.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a reader enters their own figure for the 60-day return, THE APP SHALL charge that figure instead of the default, not both. proves: `test_a_readers_own_cgt_return_quote_replaces_the_default_fee`
<!-- AC:END -->

## Plan
Give the reader a way to state the fee that `compute()` can recognise (a builder field, or a
component the engine can tell apart from an ordinary selling cost), and keep it out of the gain.
Or correct the docblock if Rob would rather the fee stay fixed.

## Comments
