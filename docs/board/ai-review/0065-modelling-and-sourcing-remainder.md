# Modelling and sourcing remainder

## Why
From the expert panel, 2026-08-19 (adviser findings 11 and 14). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`. Small items, each of which the adviser would fix before
signing a report.

**Annuity rate and tax defects.** The rate is a single free-text field defaulted to a level
joint-life figure and coupled to nothing. Tick index-linked increases and you get an index-linked
annuity at a level annuity's rate - substantially too generous, silently. And there is no tax-free
lump sum interaction: the pot falls by the full amount and all the income is taxable, when in
reality a quarter comes out tax-free and the rest is annuitised. That under-rates annuitising
against drawdown.

**Chattels capital gains are not modelled.** A sale of personal possessions is chargeable above a
threshold per item or set. Where a plan turns on selling art or jewellery, the tax is missing
entirely and the plan is optimistic by the whole bill.

**Selling costs default too low** for a leasehold sale plus a move. See card 0032 for the
itemisation; this is the engine-wide default.

**The published methodology contradicts itself** on whether house prices and salaries have
year-to-year volatility in the Monte Carlo. One section says they do not and another says they do.
It is a user-facing page.

**Economic assumptions carry prose, not per-figure sources.** Statutory figures are properly sourced
and dated. The economic assumptions - which move the answer far more - sit behind a single prose
note with no machine-checkable freshness, and the freshness command does not cover them.

## Not this card
Buying an annuity with non-pension money, which is card 0060.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN an annuity's age, escalation basis or joint-life setting changes, THE APP SHALL recalculate the rate from a sourced table rather than leaving a typed figure standing.
- [x] #2 WHEN an annuity is bought from a pension pot, THE APP SHALL take the tax-free lump sum first and annuitise the balance.
- [x] #3 WHEN personal possessions are sold above the chargeable threshold, THE APP SHALL compute the capital gains tax.
- [x] #4 THE APP SHALL carry a source URL and a verified-on date for every economic assumption, checked by the freshness command.
<!-- AC:END -->

## Tasks
- [x] Add a sourced annuity rate matrix by age, single or joint, level or index-linked
- [x] Split an annuity purchase into tax-free lump sum plus annuitised balance
- [x] Add chattels capital gains to the disposal handling, sourced and dated
- [x] Raise the default selling-cost rate; fix the methodology contradiction
- [x] Extend `figures:freshness` to the economic assumptions

## Comments

**2026-09-08**
RESULT: done
TESTS: +19 new, all green
TOUCHED:
packages/finance-engine/src/Pension/AnnuityRateTable.php
packages/finance-engine/src/Tax/ChattelsGain.php
packages/finance-engine/src/Dto/FigureSource.php
packages/finance-engine/src/Dto/AnnuityPurchase.php
packages/finance-engine/src/Dto/AssumptionSet.php
packages/finance-engine/src/Dto/CapitalReceipt.php
packages/finance-engine/src/Assumptions/AssumptionSetLibrary.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/TaxYear/CgtParameters.php
packages/finance-engine/src/TaxYear/TaxYearRegistry.php
packages/finance-engine/tests/Forecast/AnnuityTaxFreeLumpSumTest.php
packages/finance-engine/tests/Forecast/ChattelsDisposalTest.php
packages/finance-engine/tests/Forecast/PathProjectorTest.php
packages/finance-engine/tests/Forecast/PurchasedLifeAnnuityTest.php
packages/finance-engine/tests/Tax/ChattelsGainTest.php
packages/finance-engine/tests/Assumptions/EconomicAssumptionSourcingTest.php
app/Console/Commands/CheckFigureFreshness.php
app/Finance/Mapping/AssumptionSetMapper.php
app/Forecast/HouseholdAssembler.php
app/Forecast/ScenarioForecaster.php
app/Livewire/ScenarioBuilder.php
resources/views/livewire/partials/annuity-fields.blade.php
resources/views/livewire/scenario-builder.blade.php
tests/Feature/Console/CheckFigureFreshnessTest.php
tests/Feature/Livewire/ScenarioBuilderAnnuityRateTest.php
tests/Feature/Livewire/ScenarioBuilderChattelsTest.php
tests/Feature/Livewire/ScenarioBuilderTest.php
tests/Support/BuilderStateFixture.php
tests/Unit/Forecast/HouseholdAssemblerTest.php
docs/spec/ASSUMPTIONS.md
docs/spec/METHODOLOGY.md
docs/HANDOVER.md
docs/board/todo/0140-source-the-annuity-rate-table-and-the-chattels-figures.md
OUT-OF-SCOPE: 0140

**#1.** `Pension\AnnuityRateTable` is the one home of the quote: a base single-life level rate by
age, interpolated between seven anchors and clamped outside them, times an index-linked multiple and
a survivor's reduction that scales linearly with the fraction continuing. The builder re-quotes on
every field the price actually turns on (`ScenarioBuilder::repricedAnnuities`, shared by
`updatedPensions` and `updatedAccounts`, because a DC pot and an account are one Blade partial), and
those fields became `wire:model.live`. The same table is the assembler's fallback for a row saved
with a blank rate, which used to be a flat 7.2% whatever the row asked for. A reader's own quote
still wins: it is only overwritten when they change the shape it was quoted for, which is the point
of the criterion.

**#2.** Annuitising a DC pot now crystallises the money it takes, through `ufplsSplit` and the same
lump-sum-allowance ledger every other lump sum uses, so the quarter comes out as tax-free cash and
only the balance buys the income. The amount entered is now the money COMMITTED, and the builder
label says so. `ENGINE_VERSION` is `finance-engine/annuity-takes-its-tax-free-cash-first` and the
**stored-scenario re-run is owed** for any plan that annuitises a pension pot. `GoldenMasterTest`
did not redden: its fixture buys no annuity. Four existing tests moved with the rule and were
re-pinned, including `PurchasedLifeAnnuityTest`, whose pot leg now commits £133,333.32 so both legs
still buy the same gross income, which is the whole basis of its comparison.

**#3.** `CapitalReceipt::$chattelCost` is what turns a receipt into a disposal; null (every stored
receipt) keeps it a windfall, so nothing already entered moves. `Tax\ChattelsGain` owns TCGA 1992
s262, and the gain seeds the year's existing CGT charge, so a painting and a share holding share one
annual exempt amount. The restricted-loss rule and the wasting-asset exemption are named in
METHODOLOGY and not modelled; both omissions are the cautious direction.

**#4.** `Dto\FigureSource` carries one citation and one verified-on date per figure, and
`AssumptionSet::$economicSourcing` holds them. `EconomicAssumptionSourcingTest` enumerates the
AssumptionSet CONSTRUCTOR by reflection, so a figure added there without a citation reddens it: that
is what makes "every" hold rather than being a list somebody has to remember to extend.
`figures:freshness` now sweeps them beside the statutory figures and still exits non-zero on a stale
one. The sourcing rides the stored snapshot too, so a stored run keeps the provenance of its
figures and not only the figures.

ASSUMED. All three annuity-table figures and both chattels figures are **STATED, not verified**: this
session had no web access. See docs/spec/ASSUMPTIONS.md sections 36 and 37, carded as **0140**. Two
of the sixteen economic sourcing entries name their own gap in the citation rather than dressing it
up: the single-property volatility multiple is the property reviewer's calibration (card 0086) and
the inflation dynamics are stated against the shape of the record rather than fitted (card 0139).

Built in a worktree, so the re-quoting rate field, the two new pieces of help text, the new
capital-receipt cost input and the reworded methodology paragraphs **have not been seen in a
browser**.

NOT SETTLED FROM THE REPOSITORY. The card's fourth Task asks to raise the default selling-cost rate.
Card 0032 already raised it to `HousingProceeds::DEFAULT_SELLING_COST_RATE_BP` = 400 basis points
with the leasehold itemisation behind it, so there was nothing left to do and no code changed for
it. The other half of that Task, the methodology contradiction, is fixed: the Monte Carlo section
said house-price and salary growth carry no volatility while the limits list said they do. The same
paragraph also still described inflation as drawn separately, which card 0064 made untrue, so that
sentence was corrected in the same edit rather than left standing as a second known-false statement
on a user-facing page.
