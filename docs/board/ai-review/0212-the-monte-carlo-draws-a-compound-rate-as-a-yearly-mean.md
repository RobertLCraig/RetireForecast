# The Monte Carlo draws a compound rate as a yearly mean, so every pot grows too slowly

## Why
Found by the full review on 2026-10-21, and checked in the code.

The shipped equity return of 4.4% real is the FCA projection rate deflated (ASSUMPTIONS.md, "Why FCA
returns but DMS volatilities"). COBS 13 Annex 2 applies that rate as a compound annual growth rate, so
it is the rate a pot grows at over time, which is a geometric rate.

- `Forecast/DeterministicPathDraws.php` compounds the blended 4.4% straight. That is the right reading.
- `MonteCarlo/ReturnModel.php:161` draws `$mean + $vol * $z` for each year and compounds those draws.
  That treats 4.4% as the arithmetic mean of one year's return. A series of yearly returns with mean
  m and volatility s compounds at about m - s^2/2.

So the Monte Carlo median grows about s^2/2 a year slower than the deterministic line. At 23% equity
volatility that is about 2.6 points a year; for a 60/40 mix about 1 point. Every success probability
is too low, and the plans with the most invested money are hurt most, which can reorder the ranking.
Neither ASSUMPTIONS.md nor METHODOLOGY.md mentions it.

Source: https://www.handbook.fca.org.uk/handbook/COBS/13/Annex2.html (deterministic projections at
the lower, intermediate and higher rates, compounded).

## Direction
Convert each asset class's stated compound rate g to the arithmetic mean the normal draw needs:
`mu = g + s^2 / 2`, per asset class, using that class's own volatility. Do it in one place, where
`ReturnModel` reads the means, and leave `AssumptionSet` holding the stated compound rate the reader
sees. A diversified mix then has a Monte Carlo median a little ABOVE the deterministic line
(diversification return); the deterministic line stays the cautious one. Say so in METHODOLOGY.md.

Apply the same conversion to the house-price and salary draws if they are compounded the same way:
check each before changing it.

Expect `MonteCarlo\GoldenMasterTest` red: re-pin, bump `PIN_REVISION`, write the DECISIONS entry.
Bump `ENGINE_VERSION`. Every stored Monte Carlo result is owed a re-run.

## Links

**Relates to**
- `0137` - re-sourcing the returns and volatilities themselves. This card is about how they are used.

## Acceptance
<!-- AC:BEGIN -->
- [x] #1 WHEN a Monte Carlo runs one 100% equity pot with no flows over 40 years, THE APP SHALL give a median annualised real return within 0.3 points of the stated equity rate. proves: `test_the_monte_carlo_median_grows_at_the_stated_compound_rate`
- [x] #2 WHEN the volatility of an asset class is zero, THE APP SHALL draw exactly its stated rate every year. proves: `test_a_zero_volatility_class_draws_its_stated_rate`
- [x] #3 THE APP SHALL state in docs/spec/METHODOLOGY.md and ASSUMPTIONS.md that the stated returns are compound rates and how the Monte Carlo converts them. proves: manual
<!-- AC:END -->

## Tasks
- [x] Write #1 first and watch it fail on the current code.
- [x] Convert the means in `ReturnModel`.
- [x] Check the house and salary draws.
- [x] Re-pin the golden master, bump `ENGINE_VERSION`, write DECISIONS and the docs.

## Comments

**2026-10-22** RESULT: built
TESTS: +4 new (`CompoundRateTest`), full suite green, golden master re-pinned (pin 7b08e449241f)
TOUCHED: `ReturnModel` (asset and salary means, new `arithmeticMean()`), `SampledPathDraws::propertyGrowthReal()`, `ScenarioForecaster::ENGINE_VERSION`, METHODOLOGY, ASSUMPTIONS, DECISIONS
OUT-OF-SCOPE: card 0197 (one home's shock compounding). This card's shift follows whatever spread that card leaves.

Watched #1 fail first: median all-equity growth 1.75%, one home -0.56%. Now 4.4% and 1.0%, each
within 0.3 points. Each home is shifted by its own single-property spread, not the index's, because
that is the spread it is drawn with. Inflation is not shifted (a yearly CPI target). On the pinned
household, essentials success went from 45.5% to 60.0%. Every stored Monte Carlo result owes a re-run.

Review should attack two things. First, whether the house index path in `ReturnModel`, left unshifted
on purpose because each home is shifted in `SampledPathDraws`, is read raw anywhere else. Second,
whether a zero-volatility set is byte-identical to before (it should be: zero spread, zero shift).
