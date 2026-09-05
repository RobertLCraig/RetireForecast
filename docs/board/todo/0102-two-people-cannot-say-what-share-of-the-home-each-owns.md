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

## Not this card
The outside-owner `ownershipShare` field, which is correct and stays. The care means-test rules
themselves (card 0055).

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN one member of a couple owns more of the home than the other, THE APP SHALL let the reader enter each share and SHALL reject shares that do not sum to the household's own share of the property. proves: `test_per_person_home_shares_must_sum_to_the_household_share`
- [ ] #2 WHEN a home entered as 70/30 is sold, THE APP SHALL credit each owner their own share of the net proceeds. proves: `test_a_seventy_thirty_home_pays_each_owner_their_own_share`
- [ ] #3 WHEN a resident living alone is assessed for care, THE APP SHALL assess their own share of the home equity and not half of it. proves: `test_care_assesses_the_residents_own_share_of_the_home`
- [ ] #4 WHEN the reader enters no per-person shares, THE APP SHALL split equally and SHALL say on the screen that it did. proves: `test_an_unstated_split_is_equal_and_disclosed`
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
