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

### 2026-09-08 review (v20260908165132-5f52)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 292s, run by this job rather than reported by the card.

**acceptance: sound**

I traced all three.

**#1 ÔÇö chosen order is stored and used.** The select is in `resources/views/livewire/scenario-builder.blade.php` (bound to `assumptionOverrides.drawdownStrategy`), validated in `ScenarioBuilder::rules()`, read by `AssumptionOverrides::drawdownStrategy()` and applied in `ScenarioForecaster::buildSettings()`. `ForecastSettings` carries it. Traced.

**#2 ÔÇö default is disclosed.** `ResultPresenter::inputNotes()` adds an `assumed_figure` note when `ForecastSettings::drawdownStrategyIsAssumed()` is true, and the name and the "what it does" text are read from `DrawdownStrategy::label()` / `::description()`, not restated. That matches the "read the constant that owns the figure" rule. Traced.

**#3 ÔÇö the panel names the reader's own order.** `WithdrawalStrategyComparison::build()` reads `current` off `ScenarioForecaster::settings()`, `alternativeTo()` picks a different one, and `viewModel()` emits `baselineLabel` / `alternativeLabel` / `alternativeDescription`. Both templates (`partials/withdrawal-sequencing.blade.php` and `pdf/partials/report.blade.php`) print them. Traced.

I tried to break it two ways. `alternativeTo()` cannot return the current order, so the panel can never compare an order with itself. A reader who picks "spend savings first" gets stored as blank, so it still reads as assumed and still gets the disclosure ÔÇö right per the card, which asks for a note when nothing was chosen.

VERDICT: sound

**scope: sound**

**Scope check on commit `bc5741e` (16 files, all card-shaped).**

Over the fence? No. No new draw order is invented: `DrawdownStrategy` still has the same three cases; the enum only gained `label()`, `description()` and a `DEFAULT` constant, all of which AC#3 forces (the panel must name the reader's own order, and the closing sentence had to travel with whichever order sits in tile two).

Removing `WithdrawalStrategyComparison::CURRENT` / `::ALTERNATIVE` for `alternativeTo()` is inside the fence too: a constant baseline cannot name a reader's choice.

Ticked-but-unchanged task: "add to `BuilderStateFixture::full`". `tests/Support/BuilderStateFixture` holds no `assumptionOverrides` key at all, so no sibling choice key is there either. Consistent, not a gap.

Half done, but by house pattern rather than by this card: `ForecastSettings::drawdownStrategyIsAssumed()` returns true whenever the order equals the default, and `ScenarioBuilder::render()` maps the default to `''`, so a reader who deliberately picks "spending your savings first" is still told they did not choose. `planningHorizonIsAssumed()` and `statePensionUpratingIsAssumed()` behave identically, so this is the established route the card asked for, not new drift.

Nothing grew.

VERDICT: sound

**breakage: defect**

Reviewed the card-0075 change end to end: every `ForecastSettings` build routes through `ScenarioForecaster::buildSettings`, the run `inputs_hash` covers `assumptionOverrides`, `HousingComparison::rentSettings` carries the order through, and both the screen and PDF panels now read `alternativeLabel` / `alternativeDescription` instead of a fixed "fill the bands". No caller was left behind.

Two things the change made false:

1. `app/Forecast/WithdrawalStrategyComparison.php` ÔÇö the **class docblock** still says the panel prices "the current strategy (tax-efficient: spend non-pension assets first) vs the 'fill the bands' strategy". Since `alternativeTo()`, neither half holds: current is the reader's order, and the alternative is tax-efficient when the reader picks fill-the-bands. Same falsehood in the `$savingPence` constructor comment ("positive = fill-the-bands pays less") and in the `fillBandsSaves()` docblock, which now governs a comparison that may not involve fill-the-bands at all.

2. `ForecastSettings::drawdownStrategyIsAssumed()` ÔÇö the builder stores the default order as `''`, so a reader who deliberately picks "spending your savings first" is still told "You didn't say which draw order to useÔÇª Nobody chose that for your household". The disclosure asserts something untrue of that reader.

VERDICT: defect

