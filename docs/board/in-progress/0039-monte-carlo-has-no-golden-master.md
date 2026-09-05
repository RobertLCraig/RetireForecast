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

The engineer called this the cheapest high-value test in the codebase, at about an hour.

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
