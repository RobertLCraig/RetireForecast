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
- [ ] #1 WHEN an income stream has an end age, THE APP SHALL pay it, in the year the person turns one past that age, the months up to that birthday, by the same `workFraction` split salary uses.
<!-- AC:END -->

## Tasks
- [ ] Settle first whether `endAge` is inclusive (paid through the year of that age) or is the birthday the income stops on. The builder label and `IncomeStream`'s docblock are the evidence.
- [ ] Apply the end fraction in `incomeStreamsNominal` and `rentalIncomePerOwner`
- [ ] A test with a birthday in each quarter
