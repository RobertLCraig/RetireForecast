# Two people cannot say what share of the home each of them owns

## Why
A couple who own their home 70/30, or one person who owned the flat before the other moved in, have
no way to say so. The builder collects one "ownership share" figure and the engine holds one
`Property::$ownershipShare`, and that figure means something else entirely: it is the HOUSEHOLD's
beneficial share of a home held with somebody outside the household (tenants in common), which
`HousingProceeds` uses to scale the sale price, the mortgage, the costs and the gain.

So there is no per-person share anywhere, and every rule that has to divide the home between the two
people divides it EQUALLY: the sale proceeds in both sale paths (card 0040), and the equity the care
means test assesses when a resident lives alone. A 70/30 couple therefore get a 50/50 answer.

What that costs is the care means test and the first-death estate, both of which assess the
individual. The member who really owns 70% reaches the upper capital limit sooner than the model
says, and the one who owns 30% reaches it later.

Nobody decided this. `ownershipShare` was added for the outside-owner case (a shared-ownership flat,
a home held with a sibling), and the two-people-inside-the-household case was never separated from
it. Card 0040's first criterion asked for a split "by their ownership shares" and could only be met
with an equal one, because equal is the only share the repository knows.

## Links

**Relates to**
- `0040` - split the sale proceeds equally in both sale paths, which is what this card would make
  configurable.
- `0055` - the care means-test rules themselves are that card; this one only changes how much of
  the home each person is assessed on.

## Not this card
The outside-owner `ownershipShare` field, which is correct and stays. The care means-test rules
themselves (card 0055).

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN one member of a couple owns more of the home than the other, THE APP SHALL let the reader enter each share and SHALL reject shares that do not sum to the household's own share of the property. proves: `test_per_person_home_shares_must_sum_to_the_household_share`
- [x] #2 WHEN a home entered as 70/30 is sold, THE APP SHALL credit each owner their own share of the net proceeds. proves: `test_a_seventy_thirty_home_pays_each_owner_their_own_share`
- [x] #3 WHEN a resident living alone is assessed for care, THE APP SHALL assess their own share of the home equity and not half of it. proves: `test_care_assesses_the_residents_own_share_of_the_home`
- [x] #4 WHEN the reader enters no per-person shares, THE APP SHALL split equally and SHALL say on the screen that it did. proves: `test_an_unstated_split_is_equal_and_disclosed`
<!-- AC:END -->

## Tasks
- [ ] Add per-person shares to `Property` (or to `Person`, whichever keeps one definition in one home)
      and make `PenceSplit::byWeight` take them
- [ ] Carry the new builder field through all four places a field has to move: the blank default,
      validation, `loadState` backfill and `Tests\Support\BuilderStateFixture::full`
- [ ] Disclose the equal-split default as an `assumed_figure` note that READS the constant, the
      no-invisible-figures rule
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION` only if a stored scenario carries unequal shares;
      an unstated split reproduces today's figures exactly

## Plan
Work in `C:\Dev\RetireForecast`; run `php artisan test` from PowerShell (PHP is Laravel Herd and is
not on the Git Bash PATH).

Read `packages/finance-engine/tests/Housing/OwnershipShareTest.php` first: its docblock states what
the EXISTING field means, and this card must not change that meaning. The equal split this replaces
lives in three places, all commented with card 0040: `HousingComparison::withHousing()`, the
forced-sale branch of `PathProjector`, and `PathProjector::careAssessableCapital()`.

The builder field is the risk. See the four-things-together rule in
`app/Livewire/ScenarioBuilder.php` around `blankProperty`: a non-empty default breaks child what-if
deltas until `BuilderStateFixture::full` is updated in the same edit.

## Comments

**2026-10-11** RESULT: done
TESTS: +4 new, all green
TOUCHED: app/Forecast/HouseholdAssembler.php
app/Forecast/ResultPresenter.php
app/Forecast/WhatIfChanges.php
app/Livewire/ScenarioBuilder.php
packages/finance-engine/src/Dto/Property.php
packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/src/Housing/HousingComparison.php
packages/finance-engine/tests/Forecast/OwnerAttributionTest.php
resources/views/livewire/scenario-builder.blade.php
tests/Feature/Livewire/ScenarioBuilderTest.php
tests/Support/BuilderStateFixture.php
tests/Unit/Forecast/ChattelDwellingNoticeTest.php
docs/DECISIONS.md
docs/HANDOVER.md
OUT-OF-SCOPE: none

The card's Why says there is no per-person share anywhere. That is not quite true: card 0059 already added Property::$beneficialShares and a builder field beneficialShareYours, but only the first-death estate read them. So this card wired the existing field into the other rules instead of adding a new one.

Built: Property::shareWeights() is the one home of the weights. Both sale paths (HousingComparison::withHousing and the forced sale in PathProjector) split through PenceSplit::byWeight, which needed no change. careAssessableCapital takes the resident's weight over the LIVING owners. The builder gets a second field, beneficialSharePartner, moved through all four places (blank default, validation, loadState backfill, BuilderStateFixture::full). Each share is a percentage of the WHOLE property, and the pair must add up to ownershipShare (100 when blank). The assembler divides each share by their sum, so the engine still holds shares of the household's own part. The equal-split note now names all three rules it decides and reads the share from Property::beneficialShare.

Each test was watched failing for the reason its criterion gives: 50/50 GIA credit instead of 70/30 (year-0 sale); the 30% owner charged the full fee after a forced sale (watched by undoing that one line); the 30% owner of a let home charged the full fee; no error for 40+30 against a 60% household share; and the note gave no share and did not name the sale or care.

Assumed / not settled from the repository:
- Criterion 3's 'resident living alone' case does not move. In this engine a lone survivor inherits the whole home (settleEstates), so their own share is all of it, which is what it already assessed. The case the equal split really hit is a let home with both members alive. The test proves that case. DECISIONS 2026-10-11 records this.
- A scenario stored before this card with only the first share keeps its old meaning (the other member takes the rest of the household's part). If it is re-saved in the builder, the reader is asked for the second share.
- No ENGINE_VERSION bump. An unstated split gives the same figures to the penny (equal weights round exactly as the old intdiv did). A read-only count against the DB in the per-card .env found 0 of 27 stored scenarios with a share entered.
- The two share inputs (now shown only for a couple) and the error text have NOT been seen in a browser. Herd serves C:\Dev\RetireForecast, not this worktree, so this still needs a browser check.
