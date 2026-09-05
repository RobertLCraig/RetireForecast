# Property holding costs rise smoothly and never arrive in a lump

## Why
From the expert panel, 2026-08-19 (property findings 2 and 10). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

Two related problems with how a flat's running costs behave over a projection.

**No lumpy capital item.** Service charges escalate smoothly and there is no major-works event
anywhere. A Section 20 demand on a block is legally enforceable, cannot be deferred, and lands as
one bill. That is exactly the shape of liability a survivor on a thin margin cannot absorb, and no
scenario carries one. The machinery already exists - `oneOffCosts`, used for the park-home re-roof.

**The escalator is too benign.** Block insurance, building-safety compliance and energy inside a
service charge have all compounded faster than CPI since 2019. A service charge that includes water
and electricity is exposed to energy prices that no CPI escalator captures. The property reviewer
would model CPI plus 3% real, with CPI plus 1.5% as the optimistic sensitivity.

Over a long projection that difference is thousands a year of real spend, concentrated in the
survivor years. It can flip a verdict on its own.

## Not this card
Utilities and insurance categorisation, which is card 0033.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL let a user enter dated major-works costs against a property, and charge them in the year given. proves: `test_a_one_off_cost_can_be_tied_to_owning_the_home` and `test_a_major_works_cost_is_charged_in_the_year_it_falls`
- [x] #2 WHEN a leasehold property has no explicit cost-growth rate, THE APP SHALL apply a sourced default above CPI and disclose it as an assumed figure. proves: `test_a_blank_rate_escalates_the_bucket_at_the_engine_default` and `test_the_assumed_property_cost_growth_is_disclosed_with_its_value`
- [x] #3 THE APP SHALL expose the property cost-growth rate as an editable input with its sourced alternatives. proves: `test_the_property_cost_growth_input_offers_its_sourced_alternatives`
<!-- AC:END -->

## Tasks
- [x] Surface `oneOffCosts` against a property in the builder, for major works
- [x] Re-source the leasehold default for `propertyCostsRealGrowth`, with `source` and `verified_on`
- [x] Disclose it via `assumedFigures()`, reading the constant that owns it
- [ ] Re-run every stored scenario

## Comments

**2026-09-05**
RESULT: done
TESTS: +10 new, all green
TOUCHED: packages/finance-engine/src/Dto/ExpenseProfile.php
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
TOUCHED: packages/finance-engine/tests/Forecast/PropertyCostsGrowthTest.php
TOUCHED: packages/finance-engine/tests/Forecast/ForcedSaleTest.php
TOUCHED: app/Forecast/HouseholdAssembler.php
TOUCHED: app/Forecast/ResultPresenter.php
TOUCHED: app/Forecast/ScenarioForecaster.php
TOUCHED: app/Livewire/ScenarioBuilder.php
TOUCHED: app/Livewire/ScenarioResults.php
TOUCHED: app/Export/ScenarioReport.php
TOUCHED: app/Console/Commands/AuditScenarios.php
TOUCHED: resources/views/livewire/scenario-builder.blade.php
TOUCHED: tests/Feature/Livewire/ScenarioBuilderTest.php
TOUCHED: tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
TOUCHED: docs/DATA-MODEL.md
TOUCHED: docs/DECISIONS.md
TOUCHED: docs/spec/ASSUMPTIONS.md
TOUCHED: docs/HANDOVER.md
TOUCHED: docs/board/todo/0085-the-service-charge-escalation-default-has-no-published-source.md
OUT-OF-SCOPE: 0085

**The escalator.** `ExpenseProfile::propertyCostsRealGrowth()` now returns
`DEFAULT_PROPERTY_COSTS_REAL_GROWTH_BPS` (CPI + 3% real) when the rate is null and the
`while_owning_home` bucket is positive. An explicit rate, including an explicit zero, still wins, and
a household with no service charge gets nothing invented for it. `ResultPresenter::assumedFigures()`
discloses it, reading the constant. `ENGINE_VERSION` is `finance-engine/property-costs-default-growth`.

**The lump.** A one-off cost row carries an optional `condition` of `while_owning_home`, which makes
it a liability of owning the current home: dropped by `withoutPropertyCosts()` on the buy and rent
variants, and skipped by the projector once the home is sold mid-projection. Two of the three engine
tests here were watched failing on exactly that: before the change a Section 20 demand dated after a
forced sale was still charged, and a sell variant carried the demand for a flat it no longer owned.
Entry is a two-option select on each one-off row in builder step 4, stored sparsely so an ordinary
lump records no key and no what-if delta.

**What I could not settle, and it is the important part.** The card asks for a *sourced* default. An
unattended card session has no `WebSearch` or `WebFetch` (both are refused), so the only source I
could reach is this card's own `## Why`: the property reviewer of 2026-08-19 models CPI + 3% real
with CPI + 1.5% as the optimistic sensitivity. That is what I shipped, cited as the expert review
with `verified_on 2026-08-19`, and `docs/spec/ASSUMPTIONS.md` §12 says in as many words that it is a
reviewer's judgement rather than a published series and is the only figure in that document without a
primary citation. **Raised as card 0085**, which carries the lookup and needs an attended session.
Shipping the reviewer's figure beats leaving the rate at plain CPI, which the same review positively
rules out, and it is adverse-side and user-editable either way.

**Two other things a reviewer should know.**

1. **"Leasehold" is a proxy.** Criterion #2 says "WHEN a leasehold property...", and `Property` has
   no tenure field: adding one is card 0026's #1, which is blocked on 0067. So the trigger is the
   observable thing the repository does have, a positive `while_owning_home` property-costs bucket,
   which is the service charge or ground rent a leaseholder enters and the exact bucket the rate
   grows. A leaseholder who enters no service charge at all gets no default, but there would be
   nothing for it to grow.
2. **The disclosure had to become variant-aware.** `assumedFigures()` is handed the BASE household,
   so without a gate a sell-and-rent plan would have been shown a note about a service charge its
   projection strips. `ResultPresenter::keepsCurrentHome()` is the sibling of the existing
   `housingActionFor()`, and `inputNotes()` / `assumedFigures()` now take the variant. That is why
   `ScenarioResults`, `ScenarioReport` and `AuditScenarios` are on the TOUCHED list.

**Not done: the stored-scenario re-run** (the fourth task, left unticked). This was built in a
worktree that shares Rob's live `.env`, so re-running would write to the live database from a tree
his browser does not serve. Same position as card 0024. Every stay-put plan carrying a service charge
and no explicit rate now spends more, so its stored wealth, depletion year and success odds are too
favourable until that re-run happens.

**Not seen in a browser.** The builder select and the two new help paragraphs are proven by a
Livewire render test only; Herd serves the app from `C:\Dev\RetireForecast`, not from this worktree.
`ForcedSaleTest`'s two households were given an explicit `propertyCostsRealGrowth: Percent::zero()`:
they exist to prove housing costs stop at a sale, which needs the £12k that stops to still be £12k in
the sale year, and a blank rate no longer means flat.
