# Selling costs are priced for a freehold house, not a leasehold flat

## Why
From the expert panel, 2026-08-19 (property finding 9, adviser finding 14). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

The default selling cost is thin and the itemisation is missing the leasehold-specific lines. The
agent fee is about right. What is short or absent:

- conveyancing on a **leasehold** sale, which is materially more than freehold
- the managing agent's management pack, which is mandatory on a leasehold sale
- licence to assign, notice of transfer and deed of covenant fees
- removals costed realistically rather than bundled with an EPC
- an accountant for the 60-day capital gains return where one is due, which is not optional

The engine-wide default of 2% is also thin for a leasehold sale plus a move. The property
reviewer puts a realistic all-in figure nearer 4%.

This hits every sell plan equally so the ranking between them holds, but it comes straight off the
net proceeds that the whole comparison balances on.

## Not this card
Capital gains computation itself, which the panel found correct and better than most.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a leasehold property is sold, THE APP SHALL itemise the management pack, licence to assign and notice fees separately from conveyancing.
- [ ] #2 WHEN a disposal triggers a 60-day capital gains return, THE APP SHALL include a disclosed cost for preparing it.
- [ ] #3 THE APP SHALL raise the default selling-cost rate to a sourced figure appropriate to a leasehold sale, and disclose it.
<!-- AC:END -->

## Tasks
- [ ] Add the leasehold sale lines to the selling-cost components in the builder
- [ ] Re-source `HousingProceeds::DEFAULT_SELLING_COST_RATE_BP`, with `source` and `verified_on`
- [ ] Disclose the default through `assumedFigures()`
- [ ] Re-run every sell scenario
