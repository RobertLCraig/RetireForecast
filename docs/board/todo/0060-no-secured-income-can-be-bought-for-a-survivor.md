# An annuity can only be bought with pension money, so the obvious fix cannot be tested

## Why
From the expert panel, 2026-08-19 (adviser finding 3). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`AnnuityPurchase` can only be funded from a defined-contribution pot -
`PathProjector::processAnnuityPurchases` walks the pots and nothing else. So a household whose
money sits in cash, a general investment account or an ISA **cannot buy an annuity in this model at
all**, however much of it there is.

That rules out the textbook case for annuitising: a large essential spending floor, almost no
flexible spend, a much younger spouse facing a long period alone, no capacity for loss, and a
failure mode that is longevity plus an income cliff rather than sequence risk. Buying secured
income for the survivor is the one intervention that removes that risk permanently, and the tool
cannot express it.

Buying an annuity with money that is not pension money is a **purchased life annuity**, and its tax
treatment is different and favourable: only the interest element is taxable, with the exempt
proportion set by age at purchase. That treatment is a material part of why it suits a basic-rate
survivor, and the engine has no representation of it.

The adviser's conclusion: a decision between two plans, taken without a partially-annuitised
variant on the table, is a decision taken without the third option.

## Not this card
Annuity rate sourcing and the tax-free lump sum interaction, which are in card 0065.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL let an annuity be bought from a named non-pension account.
- [x] #2 WHEN an annuity is bought with non-pension money, THE APP SHALL tax only the interest element, using the exempt proportion for the buyer's age.
- [x] #3 THE APP SHALL let an annuity purchase be deferred to a chosen age, with income starting later.
- [x] #4 THE APP SHALL let an annuity be marked as enhanced for impaired health, at a disclosed uplift.
<!-- AC:END -->

## Tasks
- [x] Allow a non-pension source account on `AnnuityPurchase`
- [x] Implement the purchased life annuity tax split, sourced and dated
- [x] Add a deferred start age and an enhanced-rate flag
- [ ] Add a partially-annuitised what-if variant so it can be compared

## Comments

**2026-09-07**
RESULT: done
TESTS: +7 new, all green
TOUCHED:
- packages/finance-engine/src/Dto/AnnuityPurchase.php
- packages/finance-engine/src/Dto/Account.php
- packages/finance-engine/src/Pension/PurchasedLifeAnnuity.php
- packages/finance-engine/src/Forecast/PathProjector.php
- packages/finance-engine/tests/Forecast/PurchasedLifeAnnuityTest.php
- app/Forecast/HouseholdAssembler.php
- app/Forecast/ResultPresenter.php
- app/Livewire/ScenarioBuilder.php
- resources/views/livewire/scenario-builder.blade.php
- resources/views/livewire/partials/annuity-fields.blade.php
- tests/Unit/Forecast/HouseholdAssemblerTest.php
- tests/Feature/Livewire/ScenarioBuilderTest.php
- docs/spec/ASSUMPTIONS.md
- docs/DECISIONS.md
- docs/HANDOVER.md
- docs/board/todo/0136-source-the-purchased-life-annuity-tax-and-enhanced-uplift-figures.md
OUT-OF-SCOPE: 0136

All four criteria are met. The annuity hangs off the ACCOUNT that pays for it
(`Account::$annuityPurchase`, the exact mirror of `DcPension::$annuityPurchase`), which is what
makes the account the named source without a second household-level list, an owner field to keep in
step, or a second annuity DTO. `PathProjector::drawAnnuityPriceFromAccount()` takes the price out of
that one wrapper only, capped at what is in it, and a GIA sale realises its gain into the same
`$seedGains` path a year-0 disposal already uses, so the CGT is charged once and shares one annual
exempt amount.

`Pension\PurchasedLifeAnnuity` is the one home of the tax split. The exempt capital element comes
off the income-tax pass and NOWHERE else: the money is still received, and still assessable income
for both the Pension Credit and the care means tests, so removing it wholesale would have bought a
tax exemption and a benefits gain out of one rule.

What I assumed, both written up at docs/spec/ASSUMPTIONS.md §32 and carded as 0136. The statute
spreads the capital element over an expectation of life from tables HMRC prescribes; this engine
holds no copy of them and this session had no web, so it uses the engine's own ONS cohort life
expectancy, which is a real sourced figure of the same shape rather than an invented table, and a
results disclosure says the tax on such an annuity is therefore an estimate. The enhanced uplift
defaults to 10%, the cautious end of a market range running to roughly a third, disclosed by
`ResultPresenter::assumedFigures()` reading the constant.

Nothing stored moves: a purchased life annuity exists only once a reader ticks the new toggle, so
every figure is byte-identical, no `ENGINE_VERSION` bump is owed and `GoldenMasterTest` did not
redden. Built in a worktree, so the new account sub-form and the two disclosures have not been seen
in a browser.

The fourth Task is left open, and it is the one thing here nobody has decided: a one-click
partly-annuitised what-if has to pick HOW MUCH of the savings to annuitise and at what age, and
those are exactly the adviser's question rather than a default an engine may supply. No acceptance
criterion covers it, and a reader can already build the same comparison by hand on a what-if child
now that the toggle exists. Raise it as a card of its own if the preset is wanted.

Two smaller calls, both recorded in DECISIONS 2026-09-07. A deferred annuity pays nothing at all if
the annuitant dies inside the deferral (value protection is not modelled, the adverse reading), and
no uplift is invented for the wait: a real deferred quote pays more, so the reader enters the rate
they were quoted.

### 2026-09-08 review (v20260908095129-17d6)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 219s, run by this job rather than reported by the card.

**acceptance: sound**

I traced each criterion to real code.

**AC#1 ÔÇö buy from a named non-pension account.** `Account::$annuityPurchase` (`packages/finance-engine/src/Dto/Account.php`), built by `HouseholdAssembler::annuity()`/`account()`, seeded by `PathProjector::annuityState()` with the account type as `source`, and paid for by `PathProjector::drawAnnuityPriceFromAccount()` ÔÇö capped at the wrapper, GIA gain fed to the year's CGT. UI: `resources/views/livewire/partials/annuity-fields.blade.php`, included for `accounts` in `scenario-builder.blade.php`, validated in `ScenarioBuilder::rules()`.

**AC#2 ÔÇö tax only the interest element.** `PurchasedLifeAnnuity::capitalElementPerYear()`, set once in `processAnnuityPurchases()` using life expectancy at the income-start age, carried per year by `annuityIncomeNominal()` as `exempt`, and subtracted only inside the income-tax pass in `PathProjector::project()` (`$annuityExempt`). Means tests still see the full income.

**AC#3 ÔÇö deferred start.** `AnnuityPurchase::$incomeFromAge` / `incomeStartAge()`; `annuityIncomeNominal()` pays nothing before that age. Field present and validated.

**AC#4 ÔÇö enhanced at a disclosed uplift.** `AnnuityPurchase::ENHANCED_UPLIFT_BPS` and `effectiveRate()`; disclosed by `ScenarioBuilder::enhancedAnnuityUplift()` and `ResultPresenter::assumedFigures()`, both reading the constant.

I tried to break each one and could not.

VERDICT: sound

**scope: defect**

**Scope check on commit `096df91`** (the card's only commit; the huge diff in the brief is the whole branch, not this work).

What it built matches the four criteria. Nothing crosses the 0065 fence: the annuity rate itself is untouched, and the tax-free lump sum is not referenced.

Two findings.

1. **Left half done, and not tracked.** Task 4, "Add a partially-annuitised what-if variant", is unticked. The comment says to "raise it as a card of its own if the preset is wanted", but no such card exists (`docs/board/todo/` holds only 0065 and the new 0136 for annuity work). So the one thing the card's own Why argues for, a third option on the table to compare, exists nowhere on the board. The figures got card 0136; this got nothing.

2. **Small growth, cheap.** `ScenarioBuilder::blankAnnuity()` and `rules()` add `annuityIncomeFromAge` and `annuityEnhanced` to **pension** pots too, not only the non-pension accounts the card is about. Criteria #3 and #4 do not say "non-pension", so this reads as in scope, but it does widen an existing feature. Note it, do not fix it.

Fix 1 by raising the card.

VERDICT: defect

**breakage: defect**

I read the engine paths, not just the summary.

**Finding ÔÇö the "named account" is not actually the source.**

`PathProjector::drawAnnuityPriceFromAccount` takes the price from `$state[$key][$pid]`, where `$key` is derived from the account **type** (`Cash`/`PremiumBonds` ÔåÆ `'cash'`, `Gia`, `Isa`). Those state buckets are per-person **pooled totals across every account of that type** (built in the state setup beside `$state['cash'][...]`, and used that way everywhere, e.g. `growState`, `inheritEstate`). The annuity hangs off one `Account` (`Account::$annuityPurchase`), but nothing ever reads that account's own `balance`.

Breakage:
- A ┬ú5,000 cash account carrying an annuity of ┬ú100,000 buys the full ┬ú100,000 if the person also holds a ┬ú200,000 premium-bonds or other cash account. The cap the card asks for (AC #1, a *named* account) is not enforced, and the result is an overstated secured income ÔÇö the exact failure this project says it must not make.
- The docblock on `drawAnnuityPriceFromAccount` states "out of ONE named non-pension wrapper ÔÇª Capped at what is in it." That sentence is false as written.
- `PurchasedLifeAnnuityTest` builds households with a single account per type, so no test constructs the case.

VERDICT: defect

