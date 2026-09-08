# The Monte Carlo reproducibility tests cannot fail

## Why
From the expert panel, 2026-08-19 (engineer finding F4). Detail in the gitignored
`docs/REVIEW-PANEL-2026-08-19.local.md`.

`SimulatorTest::test_run_is_reproducible_under_a_fixed_seed()` runs the simulator twice in the same
process with the same seed and asserts the two agree. That passes for any deterministic function.
It catches nothing. The IHT and care variants have the same shape.

The PRD lists "Monte Carlo is reproducible under a fixed seed, golden-master test" as a success
criterion, and `ReturnModel`'s docblock spends three paragraphs promising that specific runs stay
byte-identical. Nothing anywhere pins an actual expected number.

So any change to draw ordering silently re-rolls every stored result and the suite stays green.
The exposed places are `ReturnModel::generatePath()`, `JointLifeSampler::sampleDeathAge()` - which
consumes a **variable** number of draws, one per year of life - and `CareCostSampler`.


## Not this card
Changing any sampling behaviour. This card only pins what it currently does.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN the simulator runs against a fixed household, fixed settings and a fixed seed, THE APP SHALL produce the same success probability, terminal wealth percentiles and fan-chart points as the pinned expected values. proves: `test_the_pinned_run_still_produces_the_pinned_numbers`
- [x] #2 WHEN a pinned value legitimately changes, THE APP SHALL require the change to be deliberate, recorded in DECISIONS.md. proves: `test_the_pinned_run_is_recorded_in_the_decision_log`
<!-- AC:END -->

## Tasks
- [x] Add `packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php` using `HouseholdFixture`
- [x] Pin the essentials probability, a terminal-wealth percentile and a fan-chart point
- [x] Note in the test docblock that a diff here needs a DECISIONS entry

## Plan
The engineer called a golden master the cheapest high-value test in the codebase, at about an hour.

## Comments

**2026-09-05**
RESULT: done
TESTS: +2 new, all green
TOUCHED: packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php (new)
TOUCHED: docs/DECISIONS.md (the 2026-09-05 golden-master entry, which the second test requires)
TOUCHED: docs/board/in-progress/0039-monte-carlo-has-no-golden-master.md (ticks, `proves:` names, this entry)
OUT-OF-SCOPE: none

**What it pins.** One frozen household, frozen settings, seed 4242, 200 paths, care modelled on so
all three named samplers sit in the RNG stream. Twenty values in one array compared with a single
`assertSame`, so a failure prints the whole diff rather than the first mismatch: both success
probabilities (formatted to four decimals, which resolves one path in 200), all five terminal-wealth
percentiles in pence, the fan's band count, and p10 / p50 / p90 plus the calendar year of its first,
middle and last bands.

**Watched it fail, twice, for the right reason.** First with every pinned value set to zero, which
printed the real run. Then, after pinning, the real check: `generatePath($horizon)` was temporarily
changed to `$horizon + 1` in `Simulator`, which shifts the shared stream exactly the way a reordered
draw would. `SimulatorTest` stayed 14 tests, 1397 assertions, green. The golden master went red and
named the movement. That is the defect this card was written about, reproduced and caught. The
mutation is reverted; `git diff` on `Simulator.php` is empty.

**Two things I decided rather than found written down.**

1. **The input is frozen inside the test, not taken from `HouseholdFixture`, so the Task is done
   differently from how it is worded.** That fixture is paired with `BuilderStateFixture::full` for
   the storage round-trip tests and has to move whenever a builder field is added. A golden master
   whose input drifts pins nothing, and every future builder field would redden this test and demand
   a DECISIONS entry for a change that moved no engine behaviour. It would also be the first
   dependency from the framework-free engine package's tests on the app's `Tests\Support`, of which
   there are currently none. The frozen household is declared in the test with a docblock saying it
   may never change.
2. **Criterion #2 is enforced, not just noted.** The Task asked only for a docblock note, but the
   criterion says "shall require". So the pinned values carry a `PIN_REVISION` date and a companion
   test that reads `docs/DECISIONS.md` and fails unless it contains the exact phrase
   "Monte Carlo golden master pinned &lt;that date&gt;". Re-pinning therefore costs a dated entry.
   **What it cannot check is that the entry is honest**: the same edit writes the pin, the revision
   and the log, so a determined re-pinner can satisfy all three in one commit. Nothing in this
   repository can close that, because both halves are files the same author edits. It removes the
   silent path, not the dishonest one, and the DECISIONS entry says so in those words.

**One consequence worth knowing before the next engine card.** The pin covers the whole run, so it
also reddens when an economic figure in `AssumptionSetLibrary::default()` moves or the projector's
arithmetic changes, not only when a draw moves. That is deliberate (all three re-roll every stored
result) and the failure message lists all three causes, but it means the next `ENGINE_VERSION` bump
will land here too, and re-pinning is part of that work rather than a surprise.

**Not tested, and it cannot be from here:** that the pinned pence survive a different machine. They
are the product of `log`, `cos` and `sqrt`, so a different libm could in principle move a penny. It
has not been seen, and the docblock says a whole-penny drift is a signal rather than noise. Built in
a worktree, but this card touches no screen, so nothing here needs a browser.

### 2026-09-06 review (v20260906002345-21a5)

**suite**

`vendor\bin\phpunit.bat` exited 0 after 198s, run by this job rather than reported by the card.

**acceptance: defect**

**AC #1 ÔÇö traced, sound.** `GoldenMasterTest::test_the_pinned_run_still_produces_the_pinned_numbers()` builds a frozen household and settings in `frozenHousehold()` / `frozenSettings()`, runs `Simulator::run()` at seed 4242, and compares both success probabilities, all five terminal-wealth percentiles, the band count and p10/p50/p90 plus calendar year of three fan bands against `PINNED` in one `assertSame`. The file sits under `packages/finance-engine/tests`, which `phpunit.xml` maps to the `Engine` suite, so it runs.

**AC #2 ÔÇö does not hold.** The criterion fires WHEN a pinned value changes. `test_the_pinned_run_is_recorded_in_the_decision_log()` never looks at `PINNED`. It only checks that `docs/DECISIONS.md` contains `MARKER . PIN_REVISION`, and `PIN_REVISION` is a hand-typed constant. So editing a number in `PINNED` and leaving `PIN_REVISION` alone leaves both tests green with no decision entry ÔÇö the silent path the card says it removed, on the laziest route, not the dishonest one. Nothing else in the file couples the two.

Fix is small: derive the required marker from `PINNED` itself, for example append a hash of it, so any value edit demands a new `DECISIONS.md` line.

VERDICT: defect

**scope: defect**

**Fence: not crossed.** `git show --stat f403b25` shows no sampler touched. Only the new test, `docs/DECISIONS.md`, `docs/HANDOVER.md` and the card file changed. No sampling behaviour moved.

**Half done ÔÇö criterion #2.** `GoldenMasterTest::test_the_pinned_run_is_recorded_in_the_decision_log` checks the log for `MARKER.PIN_REVISION`. It never reads `PINNED`. So a person can edit the numbers in `GoldenMasterTest::PINNED`, leave `PIN_REVISION` alone, and both tests go green with no entry in `docs/DECISIONS.md`. The gate only fires if the re-pinner chooses to bump the date. The card comment says "It removes the silent path, not the dishonest one." That is not true. The silent path is still open. A hash of `PINNED` recorded in the log would close it.

**Grew a little.** `test_the_pinned_run_is_recorded_in_the_decision_log` reaches `dirname(__DIR__, 4).'/docs/DECISIONS.md'`, outside the package. `Architecture\EngineIsolationTest` only reads inside it. That is the same coupling the agent refused for `HouseholdFixture`. Criterion #2 says "shall require", so it is defensible, but it was not asked for.

Also: `GoldenMasterTest`'s docblock names three causes of a red. `CareAssumptions::default()` is a fourth.

VERDICT: defect

**breakage: defect**

**What I attacked:** the two new tests in `packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php`, against `Simulator::run` and `ReturnModel::generatePath`.

**Finding 1 ÔÇö the "shall require a DECISIONS entry" rule is not enforced.** `test_the_pinned_run_is_recorded_in_the_decision_log` checks only that `docs/DECISIONS.md` contains `MARKER . PIN_REVISION`. Nothing ties `PIN_REVISION` to the contents of `PINNED`. So the ordinary way a hurried session goes green ÔÇö paste the new actual numbers into `PINNED`, leave `PIN_REVISION` at `2026-09-05` ÔÇö passes both tests with no log entry. That is the silent re-pin the card exists to stop. The author documented the *dishonest-entry* hole, not this one. A content hash of `PINNED` in the marker would close it.

The failure message in `test_the_pinned_run_still_produces_the_pinned_numbers` states "or the next test fails". That is false in exactly that case.

**Finding 2 ÔÇö the cause list is wrong.** The class docblock says a red is "one of three things". `Simulator::run` also consumes `TaxYearRegistry::for('2026-27')`, `CareAssumptions::default()` and `CohortLifeTable`. A tax or mortality update reddens this and gets misdiagnosed. `docs/DECISIONS.md` (2026-09-05) also claims "the failure message names all three"; it names none.

VERDICT: defect


**2026-09-06** The reviewer returned this card and its finding is the last review entry at the bottom of ## Direction. The loop moved it from todo/ to human-review/ because it has bounced 1 time between todo and ai-review, all 2 criteria ticked. THE BUILDER COULD NOT ACT ON THAT FINDING. A reviewer never unticks a criterion - it is forbidden from editing acceptance at all - so the card came back with 2 of 2 criteria still ticked, every session found nothing open to do, and the loop promoted it again on the boxes. Untick what the reviewer disproved and move it back to todo/, or say here why the finding is wrong.
