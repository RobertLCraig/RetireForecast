# A sell plan's assumed-figure and unfunded-cost notes are computed on the stay-put forecast

## Why
Found while building card 0031.

`ScenarioResults::render()` and `ScenarioReport` both call `ResultPresenter::inputNotes()` with
`$ladderContext->stayPutForecast()`, whatever strategy is on display. Three of the notes that
function produces are read out of the forecast it is handed:

1. the ISA-sheltering disclosure (`assumedFigures`, read from `YearResult::isaSheltered()`),
2. the Money Purchase Annual Allowance disclosure (read from the year's `MPAA_TRIGGERED` warning),
3. the unfunded one-off cost note, kind `unfunded_one_off` (read from the year's warning).

On a sell-and-buy or sell-and-rent plan, none of those come from the plan the reader is looking at.
The stay-put path shelters different money into an ISA, and it carries neither a purchase gap nor
anything else a sell variant charges. **The worked case is card 0025's own headline: an unfunded
purchase gap is charged on the buy variant, so the note naming it cannot appear on the screen at
all, which is exactly the "name the cost" outcome that card was built to deliver.**

`php artisan scenarios:audit` does not catch it, because it passes the VARIANT forecast to both
sides of its check 7 and therefore compares a consistent pair.

Passing the stay-put forecast is deliberate for the *input-sanity* notes (a retirement age below
the current age, a death floored to the base year): those are statements about the household as
entered and must not change with the strategy on display. So the fix is a separation, not a
substitution.

## Links

**Relates to**
- `0025` - built the unfunded one-off note this defect hides on the screen.
- `0031` - found it, and routed its own rent notices through `ResultPresenter::ladder()` (which
  does get the selected-variant forecast) rather than waiting on this.

## Not this card
The rent-referencing and tenancy up-front notices, which card 0031 already surfaces through the
ladder.

## Acceptance
<!-- AC:BEGIN -->
- [x] WHEN a buy-cheaper plan carries an unfunded purchase gap, THE APP SHALL show the note naming that cost on the results page. proves: `test_an_unfunded_purchase_note_reaches_the_results_page`
- [x] WHEN a sell plan is on display, THE APP SHALL compute its forecast-derived disclosures from that plan's own forecast. proves: `test_forecast_derived_disclosures_read_the_selected_variant`
- [x] THE APP SHALL keep the input-sanity notes on the household exactly as entered, whatever strategy is shown. proves: `test_input_sanity_notes_do_not_change_with_the_selected_strategy`
<!-- AC:END -->

## Tasks
- [x] Separate the two note families in `ResultPresenter::inputNotes()`: input-sanity on the
      entered household, forecast-derived on the plan being shown.
- [x] Update the three callers (`ScenarioResults`, `ScenarioReport`, `AuditScenarios::notesOfKind`)
      so screen, print and audit agree on which forecast each family reads.
- [x] Check whether `scenarios:audit` check 7 can be made to catch this class of divergence.

## Plan
Stand in `C:\Dev\RetireForecast` on `master`. The call sites are `app/Livewire/ScenarioResults.php`
(the `inputNotes` entry in `render()`), `app/Export/ScenarioReport.php` and
`app/Console/Commands/AuditScenarios.php`. `tests/Unit/Forecast/InputNotesTest.php` and
`tests/Unit/Forecast/AssumedFiguresDisclosureTest.php` are the fixtures to extend. Run
`php artisan test`, then `php artisan scenarios:audit`.

## Comments

**2026-09-28**
RESULT: done
TESTS: +3 new, all green
TOUCHED: app/Forecast/ResultPresenter.php
app/Livewire/ScenarioResults.php
app/Export/ScenarioReport.php
app/Console/Commands/AuditScenarios.php
tests/Feature/Livewire/ScenarioResultsTest.php
docs/board/todo/0153-a-sell-plan-still-shows-the-notes-about-the-home-it-sold.md
docs/board/in-progress/0089-a-sell-plans-assumed-figure-notes-are-computed-on-the-stay-put-forecast.md
OUT-OF-SCOPE: 0153

`inputNotes()` takes a trailing `?ForecastResult $shown` (null means the entered forecast, so every
other caller is unchanged). The input-sanity notes (a) and (b) read the entered (stay-put) forecast.
Every note read off what a projection did reads `$shown`: assumed figures (ISA sheltering and MPAA
included), unfunded one-off, SMI, deferred care, depreciating home, guardrail, inherited pension,
capital cliff and deprivation. The roll-up and council-tax notes describe the ENTERED home, so they
stay on the entered forecast; fed a sell plan's forecast, the roll-up note would report a £0 balance
on the bought home. Whether a sell plan should show those home notes at all is card 0153. Screen, PDF
and audit now all pass (stay-put, selected) the same way.

The first two tests failed before the fix on exactly the missing notes. The second first asserts the
two forecasts disclose differently, so it cannot pass by accident. The third passed before the fix,
because nothing read a second forecast then; I watched it fail by pointing the early-death note at
`$shown`, then restored the code.

Task 3: check 7 of `scenarios:audit` cannot catch this class. It builds both sides of its comparison
itself, so it cannot see which forecast the screen handed in; it now asks the way the screen asks.
The screen's guard is `test_forecast_derived_disclosures_read_the_selected_variant`. The audit run
showed no new problem class, only the known missing integrity stamps on older runs.

Built in a worktree: the unfunded-purchase note on a buy plan's results page and PDF still needs a
browser check.
