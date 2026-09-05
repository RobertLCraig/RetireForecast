# Figures the engine supplies for itself, that the user cannot see or change

## Why
From the expert panel, 2026-08-19 (engineer finding F5, adviser finding 12). Detail in the
gitignored `docs/REVIEW-PANEL-2026-08-19.local.md`.

The project's own hard rule is that no figure reaches a projection without being disclosed. These
do.

**The State Pension triple-lock floor.** `PathProjector::growState` multiplies the State Pension
factor by the greater of inflation and 2.5%. No source, no `verified_on`, no `AssumptionSet` field,
no control, no disclosure. Drawing inflation around 2%, the floor binds in most years, so the State
Pension grows in **real** terms for ever. It also uprates the Pension Credit guarantee, so it moves
the benefit floor too. Assuming the triple lock survives four decades is the optimistic branch of
contested policy, chosen silently - which is the opposite of the standing "adverse default,
user-editable" rule.

**The portfolio allocation.** `ForecastSettings::allocation()` falls back to a cautious 40/60 and
nothing ever passes anything else. It is the largest single determinant of the answer and the user
cannot touch it. See card 0062 for the risk half of this.

**The care assumptions.** `CareAssumptions::default()` supplies care probabilities, a mean duration
and weekly fees to every projection, none of them disclosed.

## Not this card
Exposing allocation as an input, which is card 0062.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL disclose the State Pension uprating floor, the portfolio allocation and every care assumption as assumed figures, each reading the constant that owns it.
- [x] #2 THE APP SHALL let a user choose between full triple lock, triple lock to a stated year then a lower basis, and inflation only.
- [x] #3 WHEN any of these defaults is used, THE APP SHALL show its value and why it applies.
<!-- AC:END -->

## Tasks
- [x] Add `statePensionUpratingFloor` to `AssumptionSet`, sourced and dated
- [x] Read it in `growState`; add the three-way UI control
- [x] Add the allocation and care assumptions to `ResultPresenter::assumedFigures()`
- [x] Extend `AssumedFiguresDisclosureTest` to require all of them

## Comments

**2026-09-05**
RESULT: done
TESTS: +16 new, all green
TOUCHED:
- `packages/finance-engine/src/StatePension/StatePensionUprating.php` (new: the uprating rule and the floor constant)
- `packages/finance-engine/src/Forecast/ForecastSettings.php` (the choice, plus `allocationIsAssumed()` / `statePensionUpratingIsAssumed()`)
- `packages/finance-engine/src/Forecast/PathProjector.php` (`growState` reads the choice; `initialState` carries it)
- `packages/finance-engine/tests/Forecast/StatePensionUpratingTest.php` (new)
- `app/Forecast/ResultPresenter.php` (three new disclosures; `assumedFigures`/`inputNotes` take the run settings)
- `app/Forecast/AssumptionOverrides.php` (`CHOICE_KEYS`, `statePensionUprating()`, sparse storage)
- `app/Forecast/ScenarioForecaster.php` (the choice reaches `settings()`)
- `app/Livewire/ScenarioBuilder.php` (validation and the option list)
- `app/Livewire/ScenarioResults.php`, `app/Export/ScenarioReport.php`, `app/Console/Commands/AuditScenarios.php` (pass the settings, so screen, print and audit agree)
- `resources/views/livewire/scenario-builder.blade.php` (the three-way control and its end-year box)
- `tests/Unit/Forecast/AssumedFiguresDisclosureTest.php`, `tests/Feature/Livewire/ScenarioBuilderTest.php`, `tests/Feature/Forecast/ScenarioForecasterTest.php`
- `docs/spec/ASSUMPTIONS.md` (§19)
OUT-OF-SCOPE: 0099, 0100

**Where the choice lives, against the card's Task.** The task said to put
`statePensionUpratingFloor` on `AssumptionSet`. It went on `ForecastSettings` instead, because it
is not a figure with a mean and a source but a POLICY guess about the future, and it sits beside
the other policy guesses (`modelIht`, `useIsaAllowance`, `modelCareCost`) the reader makes about
what the model should assume happens. It also cost far less: `PathProjector` already receives
`ForecastSettings`, where an `AssumptionSet` figure would have had to cross `PathDraws` and all
three of its implementations. The reader still edits it in the same fieldset as the economic
assumptions, and it is still stored under `assumptionOverrides` in the builder state, so nothing
about where it is entered changed. The floor CONSTANT does have a home of its own,
`StatePensionUprating::TRIPLE_LOCK_FLOOR_BPS`, which every disclosure and the builder label read.

**No `ENGINE_VERSION` bump, and no stored re-run owed.** The default is the full triple lock, which
reproduces the old `max($infl, 0.025)` exactly, and no stored scenario carries a choice. Every
stored figure is byte-identical. The three disclosures are new text on the results page and the
PDF; they change no number.

**The three assumed figures now disclosed.** The uprating floor (read from
`StatePensionUprating::floor()`, and gated on the household actually holding a State Pension
entitlement, so a household with none is not told about a figure that never touched it); the
portfolio allocation, with each weight named against its asset class and the blended real return
they buy, all read from `ForecastSettings::allocation()` and the set; and every care figure, read
from `CareAssumptions::default()`, shown only when the care toggle is on, because with it off no
care figure reaches a projection.

**The allocation note says the split is not yet editable**, which is true and is card 0062's
scope, deliberately untouched here.

**Watched failing.** The engine test was written against the enum and the setting before
`growState` read either, so it failed on the pension still rising 2.5% under prices-only rather
than on a missing class. The three disclosure tests failed on the notes being absent while the
figures were reaching the projection. The forecaster and builder tests were verified by disabling
the wiring, the blade block and the year validation in turn and watching each go red.

**What I could not settle from the repository.** Two things, both carded rather than decided here:
- **0099**, what the default should be. The full lock is the OPTIMISTIC branch of contested policy
  and the standing rule on this project is to default to the most adverse. Changing it moves every
  stored plan and is a judgement about the future rather than a figure to look up, so it is Rob's.
  The card's acceptance asked for disclosure and a choice, and got both; the default was left where
  it was so nothing moved under nobody's decision.
- **0100**, the missing earnings limb. The real lock is the highest of earnings, prices and 2.5%,
  and only the last two are modelled. Closing it needs a national average-weekly-earnings series
  that an unattended session cannot fetch; the household's own `salaryGrowth` is an assumption
  about one couple's pay and using it would model one thing with another. The omission understates
  the State Pension, so it errs the cautious way, and the disclosure and ASSUMPTIONS.md §19 both
  say so.

**Not seen in a browser.** Built in a worktree, so the new builder control and the three new
results-page notes have not been looked at. The end-year box appears only for the middle choice,
which is `wire:model.live` and is proved by test, not by eye.

**The 2.5% was not re-fetched.** It is the named parameter of the triple-lock policy rather than an
estimated series, so it is cited by policy definition in the enum docblock and in ASSUMPTIONS.md
§19, which states plainly that gov.uk was not reached this session. No sourcing card was raised for
it, unlike §12 to §18, because those are reviewer judgement figures with no published definition
and this one is a policy's own number.

### 2026-09-05 review (v20260905231954-45dd)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 262s, run by this job rather than reported by the card.

**acceptance: defect**

AC#1 ÔÇö traces. `ResultPresenter::assumedFigures()` builds three notes, each reading its owner: `StatePensionUprating::floor()`, `ForecastSettings::allocation()`, `CareAssumptions::default()` (all seven care fields).

AC#2 ÔÇö traces. Options in `ScenarioBuilder::render()`, control in `resources/views/livewire/scenario-builder.blade.php`, validated in `ScenarioBuilder::rules()`, read by `AssumptionOverrides::statePensionUprating()`, carried by `ScenarioForecaster::settings()`, applied in `PathProjector::growState()` via `StatePensionUprating::increase()`. The choice also enters `SimulationRunner::inputsHash()`, so a changed choice re-runs.

AC#3 ÔÇö traces to screen and print: `ScenarioResults::render()` and `ScenarioReport` both pass the settings.

DEFECT, AC#1 and AC#3. The State Pension note is gated on the household holding a `StatePensionEntitlement`. The floor does not stop there. `PathProjector::meansTestedBenefitNominal()` uprates the Pension Credit applicable amount by `$state['spFactor']`, and that method requires no State Pension entitlement, only that everyone alive is past State Pension age. So a low-income household with no State Pension gets its benefit floor lifted by the undisclosed 2.5% every year. The note's own text says the guarantee rises with the floor, so the gate contradicts it.

Same shape, weaker: under `TripleLockUntil` the floor still runs to the chosen year, but `ForecastSettings::statePensionUpratingIsAssumed()` is false, so its value never reaches the results page.

VERDICT: defect

**scope: defect**

## Scope review of card 0038

**1. The new choice does not reach every projection.** `HousingComparison::rentSettings()` builds a fresh `ForecastSettings` with named arguments and stops at `modelCareCost`. It never copies `statePensionUprating` or `tripleLockUntilYear`, so both fall back to the constructor default, `TripleLock`.

Failure: a reader picks "rises with prices only". `variantInputs()` in the same class hands the rent leg those rebuilt settings. Stay-put and buy-outright run prices only; the rent leg keeps the 2.5% floor for life, plus the Pension Credit guarantee it uprates. Renting is then scored against a richer State Pension than the two it is compared with.

Worse for this card's own purpose: `ScenarioForecaster::variantInputs()` returns the rent leg as the MAIN forecast for a scenario whose variant is `rent`. `ResultPresenter::assumedFigures()` is gated on `ForecastSettings::statePensionUpratingIsAssumed()` reading `ScenarioForecaster::settings()`, which still holds the reader's choice, so the screen shows no triple-lock note while the projection used it. That is the invisible figure the card exists to remove. `AuditScenarios::auditOne()` reads the same un-dropped settings, so it cannot catch this.

`ForecastSettings::withModelCareCost()` was updated; this sibling rebuild was not.

VERDICT: defect

**breakage: defect**

**Breakage: the sell-and-rent variant ignores the new choice.**

`HousingComparison::rentSettings()` (`packages/finance-engine/src/Housing/HousingComparison.php`) builds a fresh `ForecastSettings` and copies only eight fields. It does not copy `statePensionUprating` or `tripleLockUntilYear`, so both fall back to the constructor default: the full triple lock.

`HousingComparison::variantInputs()` hands those settings to the rent plan. `PathProjector::initialState()` reads `$settings->statePensionUprating` from them. So a reader who picks "prices only" gets prices-only on stay-put and buy-outright, and the full lock on rent ÔÇö through the deterministic ladder, `compareHousing()`, `CapacityForLoss::forScenario()`, `ProtectionGap::forScenario()` and `SustainableSpend`. The rent plan is scored more optimistically than the plans it is compared against.

It is silent. `ScenarioResults::render()` and `AuditScenarios` both pass `ScenarioForecaster::settings()`, the base settings, so the disclosure states the reader's choice while the rent projection used another. `ForecastSettings::statePensionUpratingIsAssumed()` returns false there, so no assumed-figure note fires either, and audit check 7 counts nothing missing.

No test runs a rent variant with a non-default choice; `StatePensionUpratingTest` never touches `rentSettings`.

VERDICT: defect

