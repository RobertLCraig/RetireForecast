# An income stream stops on 1 January after its end age, not on the birthday

## Why
Found while building card 0036 #4. Card 0036 made an income stream start on the birthday it reaches
`startAge`, so its first year pays only the months after that birthday. The end side was not
touched. `PathProjector::incomeStreamsNominal` pays a whole year while `age <= endAge` and nothing
from the year `age === endAge + 1`, so a stream that runs "to age 70" stops on 1 January of the
year the person turns 71, not on that birthday.

Before 0036 the two errors cancelled over the life of the stream: a whole first year paid too much
and a missing last part year paid too little. Now that the first year is a part year, a stream with
an end age pays `birthMonth / 12` of a year too little in total. It runs against the household,
which is the cautious way, but it is still a wrong figure.

`rentalIncomePerOwner` has the same window and must move with it, so the rent banked and the rent
the letting costs are taken off stay the same figure.

## Links

**Relates to**
- `0036` - made the start year a part year, which is what stopped the two errors cancelling.

## Not this card
The start side. Card 0036 owns it and it is built.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN an income stream has an end age, THE APP SHALL pay it, in the year the person turns one past that age, the months up to that birthday, by the same `workFraction` split salary uses.
<!-- AC:END -->

## Tasks
- [ ] Settle first whether `endAge` is inclusive (paid through the year of that age) or is the birthday the income stops on. The builder label and `IncomeStream`'s docblock are the evidence.
- [ ] Apply the end fraction in `incomeStreamsNominal` and `rentalIncomePerOwner`
- [ ] A test with a birthday in each quarter

## Comments

**2026-10-23** RESULT: done
TESTS: +2 new, all green
TOUCHED: packages/finance-engine/src/Forecast/PathProjector.php
packages/finance-engine/tests/Forecast/TransitionYearProrationTest.php
app/Forecast/ScenarioForecaster.php
docs/DECISIONS.md
docs/HANDOVER.md
OUT-OF-SCOPE: none

Settled endAge as inclusive (IncomeStream docblock says 'active between startAge and endAge'; the builder accepts end == start). DECISIONS 2026-10-23.
streamStartFraction became streamYearFraction, the one home of the age window and both part years; incomeStreamsNominal and rentalIncomePerOwner both read it. The year the owner turns endAge+1 pays birthMonth/12.
Tests: test_income_stream_is_prorated_in_the_year_it_ends and test_a_let_homes_rent_and_its_letting_costs_end_on_the_same_birthday, birthdays in Feb/May/Aug/Nov. Both watched red first (end year paid 0); the rent test was also watched red with only incomeStreamsNominal fixed (part rent, no letting costs).
ENGINE_VERSION bumped to finance-engine/income-stream-ends-on-the-birthday: the re-run is owed for plans with an income stream end age. Golden master did not move.
No screen changed. The results page's 'age N (year)' end label still reads right as the last age paid. No browser check was done from the worktree.
