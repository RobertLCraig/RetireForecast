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
- [x] #1 WHEN a leasehold property is sold, THE APP SHALL itemise the management pack, licence to assign and notice fees separately from conveyancing. proves: `test_the_housing_step_itemises_the_leasehold_sale_fees_separately_from_conveyancing`
- [x] #2 WHEN a disposal triggers a 60-day capital gains return, THE APP SHALL include a disclosed cost for preparing it. proves: `test_a_disposal_that_charges_cgt_is_charged_for_preparing_the_sixty_day_return`
- [x] #3 THE APP SHALL raise the default selling-cost rate to a sourced figure appropriate to a leasehold sale, and disclose it. proves: `test_the_assumed_selling_cost_rate_is_read_from_the_engine_constant`
<!-- AC:END -->

## Tasks
- [x] Add the leasehold sale lines to the selling-cost components in the builder
- [x] Re-source `HousingProceeds::DEFAULT_SELLING_COST_RATE_BP`, with `source` and `verified_on`
- [ ] Disclose the default through `assumedFigures()`
- [ ] Re-run every sell scenario

## Comments
**2026-09-05**
RESULT: done
TESTS: +7 new, all green
TOUCHED:
- packages/finance-engine/src/Housing/HousingProceeds.php
- packages/finance-engine/tests/Housing/HousingProceedsReconciliationTest.php
- packages/finance-engine/tests/Housing/OwnershipShareTest.php
- packages/finance-engine/tests/Housing/PurchaseSavingsCgtTest.php
- packages/finance-engine/tests/Housing/UnfundedPurchaseTest.php
- packages/finance-engine/tests/Forecast/ForcedSaleTest.php
- app/Livewire/ScenarioBuilder.php
- app/Forecast/ResultPresenter.php
- app/Forecast/ScenarioForecaster.php
- resources/views/livewire/scenario-builder.blade.php
- resources/views/livewire/scenario-results.blade.php
- resources/views/pdf/partials/report.blade.php
- tests/Feature/Livewire/ScenarioBuilderTest.php
- tests/Unit/Forecast/SaleExplainerTest.php
- tests/Unit/Forecast/AssumptionsPanelTest.php
- docs/spec/ASSUMPTIONS.md
- docs/DECISIONS.md
- docs/HANDOVER.md
- docs/board/todo/0092-pin-the-selling-cost-figures-to-a-published-source.md
- docs/board/todo/0093-a-freehold-seller-is-charged-leasehold-fees-by-default.md
- docs/board/in-progress/0032-selling-costs-are-priced-for-a-freehold-house.md
OUT-OF-SCOPE: 0092, 0093

**What was built.** `HousingProceeds::DEFAULT_SELLING_COST_RATE_BP` goes 200 to **400**, and a new
`CGT_RETURN_FEE_PENCE` (**£750**) is appended as its own breakdown line whenever a disposal actually
charges CGT. `ScenarioBuilder::defaultSellingCosts()` becomes six itemised lines: agent 1.5%,
leasehold conveyancing £2,000, management pack £500, licence to assign plus notices £700, removals
£1,200, EPC £80. The assumptions panel's selling-cost row now reads the rate constant and shows the
pounds, where it used to restate `2.0` in the presenter.

**The return fee is appended AFTER the gain is computed and outside the ownership-share scaling.**
Two reasons, both load-bearing. The cost of computing a tax is not an incidental cost of disposal
(TCGA 1992 s.38), so it must not reduce the gain; and if it did, the charge would be circular, since
the tax is what decides whether the fee applies. It is a household accountancy bill, not a share of
a cost co-owners split, so it is not halved by a 50% beneficial share. The reconciliation identity
(sale = net + mortgage + costs + CGT) and the breakdown-sums-to-total invariant both still hold, and
both are asserted on the fee case.

**Both blades now show the breakdown whenever there is more than one line**, not only when the
reader itemised. Otherwise a sale that assumed the whole rate AND got charged the return fee would
show the bigger total with nothing saying where the extra came from.

**What I could not settle from the repository.**

1. **There is no way to know a property is leasehold.** `Property` has no tenure field, and adding
   one belongs to card 0026 (blocked on 0067). So the leasehold lines ship WITH figures and a
   freeholder clears the two that do not apply, with a note on the builder step telling them to.
   That is the adverse default, and card 0028 hit the same wall and made the same call. The residual
   fault, that a freeholder who does not read the note is charged £1,200 they never owe, is raised
   as **0093** behind 0026 rather than fixed here with a second competing tenure flag.
2. **"Sourced" (criterion #3) means the dated expert review, not a published series.** "Nearer 4%"
   is the 2026-08-19 property reviewer's figure, recorded with `verified_on 2026-08-19`. The six
   itemised line values and the £750 are **this session's own reading of ordinary UK practice**, and
   nothing was fetched to check any of them, because an unattended session has no web access. That
   is stated in the constant docblocks, is the fifth sourcing gap in `docs/spec/ASSUMPTIONS.md` §16,
   and is raised as **0092**. The 60-day deadline itself is statute and is not part of that gap.
3. **Task 3 is left open on purpose.** The default is disclosed through `assumptionsPanel()`, not
   `assumedFigures()`. `ResultPresenter::housingActionFor()` hands `assumedFigures()` the housing
   action only on a `buy_outright` plan, so a note added there would be invisible on a sell-and-rent
   plan, which also sells and also pays these costs. The assumptions panel receives the raw action on
   every variant, so that is where a sale-side disclosure actually reaches the reader. Saying so
   rather than ticking the task as written.
4. **Task 4 is left open.** Re-running the stored scenarios needs the live database, and this was
   built in a worktree. `ENGINE_VERSION` is `finance-engine/leasehold-selling-costs`; every stored
   sell plan is too favourable until it is re-run, and a stay-put plan is byte-identical.

**Not seen in a browser.** Herd serves the app from the main checkout, so the new builder lines, the
help text and the sale waterfall still need a look on a real screen.

**Sixteen existing tests moved with the rate**, all of them fixtures pinned to the old 2% (net
proceeds £392k becoming £384k, and everything downstream of it). Two were rewritten rather than
renumbered: the premium-bonds draw-order test needed bigger accounts, because the wider funding gap
drained both and hid the order it exists to prove; and the CGT-banding test's seed gain now spills
into the higher band, so its "all within the basic band" assertion was replaced with the real split.
