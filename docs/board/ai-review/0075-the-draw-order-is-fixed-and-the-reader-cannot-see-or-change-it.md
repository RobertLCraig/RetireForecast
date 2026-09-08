# The draw order is fixed, and the reader cannot see or change it

## Why
The order money is taken out - savings first, or pension first, or filling the free tax bands - is
one of the biggest levers on lifetime tax the tool has. The results page prices three orders and
names the cheapest, so the reader can see one of them is better.

They cannot then use it. Every forecast on every page is run on one order, "spend your savings
first", chosen in code. It is not on the input form, it is not on the assumptions panel, and nothing
tells the reader it was chosen for them. So the tool can say a different order saves several thousand
pounds and offer no way to model the plan that does it.

Two things make the choice worse than arbitrary. From April 2027 an unspent pension counts in the
estate for inheritance tax, so "spend savings first, pension last" stopped being the safe default for
an estate near the threshold. And a household on Pension Credit is a case the fixed order handles
badly, because pension income takes the credit away pound for pound while capital does not.

Nobody chose this as policy. `ScenarioForecaster::DEFAULT_DRAWDOWN_STRATEGY` was set when only one
order existed, and the other two arrived later as things to compare against rather than to use.

## Not this card
Inventing new draw orders. The three the engine already has are the set.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL let the reader choose the draw order their forecast is run on, from the orders the engine supports. proves: `test_the_builder_stores_a_chosen_draw_order`
- [x] #2 WHEN no order is chosen, THE APP SHALL disclose the one it used as an assumed figure, with why it applies. proves: `test_the_default_draw_order_is_disclosed_as_an_assumed_figure`
- [x] #3 THE APP SHALL name the reader's own order in the comparison panel, whichever it is. proves: `test_the_panel_names_the_chosen_order_as_the_current_one`
<!-- AC:END -->

## Tasks
- [x] Add the draw order to the builder state, validation and `BuilderStateFixture::full`
- [x] Read it in `ScenarioForecaster::settings()`, keeping `DEFAULT_DRAWDOWN_STRATEGY` as the fallback
- [x] Disclose the default in `ResultPresenter::assumedFigures()`, reading the constant that owns it
- [x] Re-run `php artisan scenarios:audit`; stored figures move if the default itself changes

## Comments

**2026-09-08**
RESULT: done
TESTS: +4 new, all green
TOUCHED:
- packages/finance-engine/src/Forecast/DrawdownStrategy.php
- packages/finance-engine/src/Forecast/ForecastSettings.php
- app/Forecast/AssumptionOverrides.php
- app/Forecast/ScenarioForecaster.php
- app/Forecast/WithdrawalStrategyComparison.php
- app/Forecast/ResultPresenter.php
- app/Livewire/ScenarioBuilder.php
- resources/views/livewire/scenario-builder.blade.php
- resources/views/livewire/partials/withdrawal-sequencing.blade.php
- resources/views/pdf/partials/report.blade.php
- tests/Feature/Livewire/ScenarioBuilderTest.php
- tests/Feature/Forecast/ScenarioForecasterTest.php
- tests/Unit/Forecast/AssumedFiguresDisclosureTest.php
- docs/DECISIONS.md
OUT-OF-SCOPE: none

`DrawdownStrategy` is a backed enum now and owns three things the app used to hold or restate: the
string the choice is stored as, the reader-facing `label()`, and a `description()` of what the order
actually does. `WithdrawalStrategyComparison::label()` delegates to it, so the name on the builder
control is the name on the results page.

The choice rides the sparse `assumptionOverrides` choice-key route (`drawdownStrategy`), the same
one the planning horizon and the asset mix use. A scenario stored earlier carries no key, reads as
`DrawdownStrategy::DEFAULT` and reproduces byte-identically, so **no `ENGINE_VERSION` bump and no
stored re-run is owed**; the whole suite, including `GoldenMasterTest`, stayed green with no re-pin.
`ScenarioForecaster::DEFAULT_DRAWDOWN_STRATEGY` is kept as the card asked and now reads the enum
rather than repeating the value.

Criterion 3 cost more than a label swap. `WithdrawalStrategyComparison::CURRENT` and `::ALTERNATIVE`
were both constants, and the alternative was deliberately constant because both panel templates
closed with a sentence describing what fill-the-bands does, which no test could see go wrong. So the
sentence moved onto `DrawdownStrategy::description()` and rides the panel as
`alternativeDescription`; `alternativeTo()` then picks fill-the-bands, or the historical default
where the reader already draws that way. `CURRENT` is gone and `$comparison->current` replaces it.
`test_the_panel_never_compares_the_current_order_against_itself` drifted with that (it guarded two
constants that no longer exist) and now runs over every order the reader can pick. DECISIONS
2026-09-08 records the call and supersedes the 2026-08-19 reasoning the old docblock cited.

`BuilderStateFixture::full` needed no change: the default is blank, so no what-if delta moves.

Watched failing first, each for its own reason: no disclosure mentioning "draw order"; no control on
the builder step; and the baseline tile naming "spending your savings first" for a reader who had
picked the pension-first order.

`php artisan scenarios:audit` still exits 1, on the same two pre-existing classes and nothing new:
120 runs with no integrity stamp, and three assumption sets missing card 0064's shipped figures
(the seeder has not been run here). No disclosure, labelling or reconciliation problem appeared.

Built in a worktree, so the new builder control, the reworded panel sentence and the new results
note **have not been seen in a browser**.
