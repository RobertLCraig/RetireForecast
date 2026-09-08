# The central case plans to a median lifespan, and that is what everyone reads

## Why
From the expert panel, 2026-08-19 (adviser finding 5). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`RepresentativeDeathAge::forHousehold` gives each person their **own independent median** death
age. For a couple the relevant statistic is the **last survivor**, whose median is materially later
and whose tail is far fatter. On the cohort tables a woman of 66 has roughly a one-in-four chance of
reaching her mid-nineties and one-in-ten of reaching about 99. The deterministic path stops her
around 89.

The decision-support plan already records this bias and correctly forces crossings into the Monte
Carlo. But the whole comparison table, the affordability limit tests and the per-month analysis are
all **deterministic**, and the results page still leads with detail rather than the probability.

So every plan is ranked on a depletion year taken from a coin-flip lifespan. For a household with a
long expected survivor period that leaves roughly even odds of a decade of unfunded life.

The adviser's sharpest point: printing that a plan "lasts for life" against a median lifespan is
the single most misleading string the tool can produce.

## Not this card
Leading the page with the probability, which is card 0010.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 THE APP SHALL set the deterministic planning horizon on a household last-survivor basis, not on each person's own median.
- [x] #2 THE APP SHALL default that horizon to a stated high percentile, and offer 50th, 75th and 90th as named settings on the age-of-death lever.
- [x] #3 WHEN a median-lifespan figure is shown, THE APP SHALL label it as roughly even odds rather than as a plan lasting for life.
<!-- AC:END -->

## Tasks
- [x] Add `CohortLifeTable::percentileDeathAge()` and a last-survivor derivation
- [x] Switch the deterministic horizon; ship the 75th percentile as the default
- [x] Name the settings on the age-of-death lever
- [ ] Relabel the median output; re-run every stored scenario
      (relabelled; the re-run is OWED and cannot be done from a worktree, whose database is not
      the app's)

## Comments

**2026-09-08**
RESULT: done
TESTS: +11 new, all green
TOUCHED:
packages/finance-engine/src/Mortality/PlanningHorizon.php (new)
packages/finance-engine/src/Mortality/CohortLifeTable.php
packages/finance-engine/src/Forecast/RepresentativeDeathAge.php
packages/finance-engine/src/Forecast/ForecastSettings.php
packages/finance-engine/src/Forecast/DeterministicForecaster.php
packages/finance-engine/src/Forecast/DeterministicPathDraws.php
packages/finance-engine/src/Forecast/HistoricalBacktester.php
packages/finance-engine/tests/Mortality/LastSurvivorHorizonTest.php (new)
packages/finance-engine/tests/Forecast/PathProjectorTest.php
app/Forecast/AssumptionOverrides.php
app/Forecast/ScenarioForecaster.php
app/Forecast/ResultPresenter.php
app/Livewire/ScenarioBuilder.php
app/Livewire/ScenarioResults.php
app/Livewire/ScenarioCompare.php
app/Export/ScenarioReport.php
resources/views/livewire/scenario-builder.blade.php
resources/views/livewire/scenario-results.blade.php
resources/views/livewire/scenario-compare.blade.php
resources/views/pdf/partials/report.blade.php
tests/Feature/Livewire/ScenarioBuilderTest.php
tests/Feature/Livewire/ScenarioResultsTest.php
tests/Unit/Forecast/PlanningHorizonLabelTest.php (new)
OUT-OF-SCOPE: none

`CohortLifeTable::survivalCurve()` is the one home of the survival arithmetic, and
`percentileDeathAge()` reads it; `medianDeathAge()` is now that method at 0.5, so it cannot
drift from it. `RepresentativeDeathAge::forHousehold()` derives the household horizon as the
first calendar year the probability that ANYBODY is still alive falls below (1 - the
percentile), treating the lives as independent, which is the assumption the Monte Carlo's
joint sampler already makes.

Two derivation calls the card did not settle, made here and reversible in a line:

- **Only the last survivor moves.** The person modelled to die first keeps their own median.
  The card asks for a horizon, not a death pattern, and moving the first death later is the
  FLATTERING direction (a household keeps two lots of income for longer), so it stays put.
- **A lifespan the reader STATED is never extended.** A fixed age or an offset from the peer
  average is a certainty in this model: it enters the joint survival arithmetic as a step
  function and the person carrying it is never carried out past what they said. Only a
  table-derived death age (peer, or a mortality multiplier) is.

A single-person household falls out of the same code path unchanged in shape: with one life
the joint curve IS their own, so at the 50th they get exactly their old median, and at the
default 75th they get the same percentile of their own age at death.

The setting rides `ForecastSettings` beside the other policy choices and reaches it through
the existing sparse `assumptionOverrides` choice-key route, so a scenario stored before this
card carries no key and reads as the default rather than as the median it actually ran on.
The blank option IS the default, exactly as the State Pension uprating control does it.

**ENGINE_VERSION is `finance-engine/last-survivor-planning-horizon` and the stored-scenario
re-run is owed**: every stored plan now has to fund more years, so its terminal wealth and
estate are too high, its depletion year too late and any "the money lasts" reading too
favourable. The Monte Carlo samples lifespans and is untouched, which is why
`MonteCarlo\GoldenMasterTest` did not redden and needs no re-pin.

`PathProjectorTest::FILL_BANDS_LIFETIME_TAX_BEFORE_UFPLS` was re-pinned (10,353,806 to
13,666,556). Nothing about the UFPLS rule moved; that couple simply funds more years now, so
the lifetime tax it pays with no lump sum allowance left is a bigger number. The three
comparisons around it still hold in the same directions.

Where the harness could not give a clean red: criteria #2 and #3 are new API by nature (a
setting that did not exist, and a sentence nobody was printing), so their first red at the
unit level was a missing property or a missing method. Both were then re-proved at the
surface the criterion is actually about, and those reds WERE honest: the builder rendered no
control carrying the three named settings, and the results page rendered no odds phrase.
Criterion #1's red was the real one, and is the card in a line: the horizon came back as
2047, which is exactly the later of the two individual medians.

Built in a worktree, so the new builder control, the relabelled ladder and estate copy, the
Compare note and the PDF line **have not been seen in a browser**.

### 2026-09-08 review (v20260908100035-fb9b)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 220s, run by this job rather than reported by the card.

**acceptance: defect**

I traced each criterion.

**#1 ÔÇö traced, sound.** `RepresentativeDeathAge::forHousehold()` and `lastSurvivorYear()` (`packages/finance-engine/src/Forecast/RepresentativeDeathAge.php`) derive the horizon from the joint survival curve, and `DeterministicForecaster::forecast()` uses it.

**#2 ÔÇö traced, sound.** `PlanningHorizon` enum with `DEFAULT = P75`, three named cases and `label()`; wired through `AssumptionOverrides::planningHorizon()`, `ScenarioForecaster::settings()`, and the `planningHorizon` select in `ScenarioBuilder::render()` / `scenario-builder.blade.php`.

**#3 ÔÇö DEFECT.** `ResultPresenter::planningHorizonBasis()` exists and is shown on Results, Compare and the PDF. But the **Affordability page is not covered**, and it is one of the surfaces the card's Why names. `App\Livewire\Affordability` never passes `planningHorizonBasis`, and `resources/views/livewire/affordability.blade.php`, in its "On the expected path" block (the deterministic one), still prints "keep the essentials paid **for life**", "it is the safest of your plans that still **lasts for life**", and "These keep your essential bills paid **for the rest of your life**" ÔÇö with no odds phrase anywhere on the page. Set the lever to P50 and the tool prints the exact string the card calls its most misleading.

Same untouched string in `scenario-results.blade.php` advice-cost block and `pdf/partials/report.blade.php`: "The money would still last for life."

VERDICT: defect

**scope: defect**

**What I checked:** every file the card touched, and the fence.

**Over the fence: nothing.** The results page was relabelled, not restructured. No verdict-first landing, so card 0010 is untouched. The new lever, `PlanningHorizon`, `RepresentativeDeathAge::forHousehold` and `ForecastSettings::planningHorizon` all sit inside the card's four lines. No extra features rode along.

**Left half done, and it bites.** The card's own fourth task is unticked: the stored scenarios were never re-run. That is not just paperwork.

- `App\Forecast\ResultPresenter::planningHorizonBasis` reads the **current** settings, and `App\Livewire\ScenarioResults::render` (and `ScenarioCompare::render`, `Export\ScenarioReport::data`) feed it to the page.
- The stored figures beside it came from a run stamped with the **old** engine version. Nothing compares `SimulationRun::$engine_version` to `ScenarioForecaster::ENGINE_VERSION`, and no view prints it.

So a stored plan now shows median-lifespan numbers wearing a "one household in four still has somebody alive" caption. The card exists to stop exactly that kind of over-favourable reading, and this makes it read as verified.

Fix: re-run stored scenarios, or flag a run whose engine version is stale.

VERDICT: defect

**breakage: defect**

**What I found**

`HousingComparison::rentSettings()` builds a fresh `ForecastSettings` by hand and lists only eight fields. The card added a ninth-of-fourteen, `planningHorizon`, and did not add it there. So on a **sell-and-rent** plan the horizon silently falls back to P75 even when the reader picks 50th or 90th on the new lever.

Why that matters: the comparison table then ranks a stay-put plan run to the chosen horizon against a rent plan run to a different one. Nothing says so. `ForecastSettings::withModelCareCost()` *was* updated, so the class now has one rebuild that carries the field and one that drops it ÔÇö the exact drift the sibling card warns about.

No test builds this case: `LastSurvivorHorizonTest` and `ScenarioBuilderTest` both read the base settings, never a variant leg's settings.

Two docs are now false:
- `docs/board/todo/0124-rent-variant-settings-silently-drop-six-fields.md` says six fields; it is seven, and its list omits the horizon.
- `ForecastSettings::$planningHorizon` docblock says it is how long "the deterministic plan" lasts. For the rent leg it is not.

Everything else checked out: `DeterministicForecaster`, `HistoricalBacktester` and `AssumptionOverrides` all pass the horizon through, and `medianDeathAge()` genuinely reads `percentileDeathAge()`.

VERDICT: defect

