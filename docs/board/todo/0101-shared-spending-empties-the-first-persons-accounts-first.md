# Shared spending empties the first-declared person's accounts before the second's

## Why
A year the household cannot fund out of income is funded by selling assets. The waterfall that does
it (`PathProjector::fundShortfall`, the `drawNonPension` closure) walks the buckets in order, cash
then GIA then ISA, and inside each bucket it walks `$household->persons` in DECLARATION ORDER. So
the first-declared person's cash is spent to zero before a penny of the second person's is touched.

That decides two things it should not. The English care means test assesses the RESIDENT's own
capital, so which of the two paid last year's bills decides whether this year's care is self-funded
or picked up by the local authority. And a GIA disposal is taxed against the seller's own capital
gains annual exempt amount, so draining one person wastes the other's allowance.

The effect is visible today: on a couple with equal capital, one of whom is in care for three years,
swapping the order the two people were entered in changes the care bill from £240,000 to £124,131.78.
The first care year is unaffected, because it is assessed on capital as the year opened.

Card 0040 fixed the mirror image of this, money LANDING on the first-declared person, and left this
one deliberately: which of two people's assets pay for shared spending is a modelling rule in its own
right, not a tidy-up. It is why 0040's third criterion is still open.

## Links

**Relates to**
- `0040` - fixed the three places money landed on the first person, and its third criterion cannot
  be met until this one is settled too.

## Not this card
The care means-test rules themselves (card 0055), and the ORDER of the buckets (cash before GIA
before ISA), which is a deliberate drawdown-strategy choice and is not what is wrong here.

## Acceptance
<!-- AC:BEGIN -->
- [ ] #1 WHEN a shortfall is funded from a bucket two living people both hold, THE APP SHALL draw from them in proportion to what each holds rather than exhausting one first. proves: `test_a_shortfall_draws_from_both_holders_in_proportion`
- [ ] #2 WHEN the order two people were entered in is swapped, THE APP SHALL produce the same care charge over a whole care spell funded by drawdown. proves: `test_swapping_the_order_leaves_a_drawdown_funded_care_spell_unchanged`
<!-- AC:END -->

## Tasks
- [ ] Make `drawNonPension` split each bucket's draw across the living holders, pro rata to balance,
      with a second pass for anyone the first pass capped out
- [ ] Check the pension and `drawGiaToAea` passes for the same declaration-order walk and say on this
      card whether they carry it too
- [ ] Bump `ScenarioForecaster::ENGINE_VERSION`: this moves every stored two-person plan that ever
      draws down, and re-pin `MonteCarlo\GoldenMasterTest` if it reddens
- [ ] Widen the two narrowed tests in `OwnerAttributionTest` back to the whole care spell

## Plan
Work in `C:\Dev\RetireForecast`, engine tests with `php artisan test --testsuite=Engine` from
PowerShell (PHP is Laravel Herd and is not on the Git Bash PATH).

`packages/finance-engine/tests/Forecast/OwnerAttributionTest.php` already holds the fixtures. Two of
its tests carry a comment naming this card and assert on the FIRST care year only, because that is
the part that no longer moves; widening them to `careCostReal()` is the red test this card starts
from. `packages/finance-engine/src/Money/PenceSplit.php` already owns the order-independent split
rule (`byWeight` takes balances as weights), so the draw is a call to it rather than new arithmetic.
