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
- [ ] #1 THE APP SHALL let the reader choose the draw order their forecast is run on, from the orders the engine supports. proves: `test_the_builder_stores_a_chosen_draw_order`
- [ ] #2 WHEN no order is chosen, THE APP SHALL disclose the one it used as an assumed figure, with why it applies. proves: `test_the_default_draw_order_is_disclosed_as_an_assumed_figure`
- [ ] #3 THE APP SHALL name the reader's own order in the comparison panel, whichever it is. proves: `test_the_panel_names_the_chosen_order_as_the_current_one`
<!-- AC:END -->

## Tasks
- [ ] Add the draw order to the builder state, validation and `BuilderStateFixture::full`
- [ ] Read it in `ScenarioForecaster::settings()`, keeping `DEFAULT_DRAWDOWN_STRATEGY` as the fallback
- [ ] Disclose the default in `ResultPresenter::assumedFigures()`, reading the constant that owns it
- [ ] Re-run `php artisan scenarios:audit`; stored figures move if the default itself changes
