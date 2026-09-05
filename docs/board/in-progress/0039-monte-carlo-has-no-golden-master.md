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
- [ ] #1 WHEN the simulator runs against a fixed household, fixed settings and a fixed seed, THE APP SHALL produce the same success probability, terminal wealth percentiles and fan-chart points as the pinned expected values.
- [ ] #2 WHEN a pinned value legitimately changes, THE APP SHALL require the change to be deliberate, recorded in DECISIONS.md.
<!-- AC:END -->

## Tasks
- [ ] Add `packages/finance-engine/tests/MonteCarlo/GoldenMasterTest.php` using `HouseholdFixture`
- [ ] Pin the essentials probability, a terminal-wealth percentile and a fan-chart point
- [ ] Note in the test docblock that a diff here needs a DECISIONS entry
